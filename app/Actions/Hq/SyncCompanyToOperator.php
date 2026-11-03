<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Actions\Invoicing\SaveCustomer;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OperatorLink;
use App\Models\User;
use App\Modules\Crm\Actions\ConvertToCustomer;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\IdentityNumber;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Hq\OperatorSync;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes a company in the installation a customer of the target company
 * (ADR-044): the operator company, or the company HQ chose for customers.
 *
 * Everything is written in the target through its own Actions inside
 * CurrentCompany::run, acting as the target's owner (the system has no
 * user of its own), never with raw inserts:
 *
 * - No link yet: the queue prospect of the company's owner becomes the
 *   customer (ConvertToCustomer), else the one existing customer with the
 *   same organisation number is linked, else SaveCustomer creates one.
 * - Linked: only the fields whose value changed in the source since the
 *   last push are written, so the operator's own edits stay. An archived
 *   company makes the customer inactive; taking it out of the archive
 *   makes it active again. A customer anonymised, restricted (GDPR) or
 *   removed in the target is never written to or created again.
 * - With CRM on in the target: the owner of the company becomes a contact
 *   person on the customer's account (legal basis: agreement), unless the
 *   address is on the target's suppression list or the person there must
 *   not be contacted.
 *
 * A sole trader's number is a personal number: it goes to the register's
 * encrypted personal_number, never to org_number, and the VAT number (which
 * contains it) is not copied.
 */
final class SyncCompanyToOperator
{
    public const string CREATED = 'created';

    public const string LINKED = 'linked';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    public const string SKIPPED = 'skipped';

    /** @var list<string> the customer fields a company feeds */
    public const array FIELDS = ['name', 'org_number', 'personal_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country'];

    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly ConvertToCustomer $convertToCustomer,
        private readonly SavePerson $savePerson,
        private readonly LinkPerson $linkPerson,
        private readonly ModuleManager $modules,
    ) {}

    public function __invoke(Company $source, Company $target): string
    {
        if (OperatorSync::isExcluded($source, $target)) {
            return self::SKIPPED;
        }

        $actor = self::actor($target);

        return Cache::lock("operator-sync:company:{$target->id}:{$source->id}", 30)->block(10, fn (): string => DB::transaction(function () use ($source, $target, $actor): string {
            $link = OperatorLink::query()->where('target_company_id', $target->id)->where('source_company_id', $source->id)->lockForUpdate()->first();

            // An archived company that never was a customer does not become one now.
            if ($link?->customer_id === null && $source->isArchived()) {
                return self::SKIPPED;
            }

            // The customer was linked once and has been removed in the target since: the target's decision stands.
            if ($link !== null && $link->synced_at !== null && $link->customer_id === null && $link->status === OperatorLink::SYNCED) {
                return self::SKIPPED;
            }

            $values = self::values($source);

            [$outcome, $link, $customer] = $link?->customer_id === null
                ? $this->attach($source, $target, $actor, $values, $link)
                : $this->update($source, $target, $actor, $values, $link);

            if ($customer !== null && $this->modules->isEnabled(CrmModule::KEY, $target)) {
                $this->ensureOwnerContact($link, $source, $target, $actor, $customer);
            }

            $link->forceFill([
                'status' => OperatorLink::SYNCED,
                'error' => null,
                'failed_at' => null,
                'synced_at' => now(),
            ])->save();

            if ($outcome !== self::UNCHANGED) {
                // Ids and the outcome only, never a name or a number.
                Audit::instance('hq.operator_customer_synced', null, [
                    'source_company_id' => $source->id,
                    'target_company_id' => $target->id,
                    'customer_id' => $link->customer_id,
                    'outcome' => $outcome,
                ]);
            }

            return $outcome;
        }));
    }

    /**
     * The customer fields as the company has them now.
     *
     * @return array<string, ?string>
     */
    public static function values(Company $company): array
    {
        $number = $company->org_number;
        $personal = IdentityNumber::isPersonal($number);
        $owner = $company->loadMissing('owner')->owner;

        return [
            'name' => (string) ($company->legal_name ?: $company->name),
            'org_number' => $personal ? null : $number,
            'personal_number' => $personal ? $number : null,
            // A sole trader's VAT number contains the personal number.
            'vat_number' => $personal ? null : $company->vat_number,
            'email' => $company->email ?: ($owner instanceof User && $owner->anonymised_at === null ? $owner->email : null),
            'phone' => $company->phone,
            'address_line1' => $company->address_line1,
            'address_line2' => $company->address_line2,
            'postal_code' => $company->postal_code,
            'city' => $company->city,
            'country' => strtoupper((string) ($company->country ?: 'SE')),
        ];
    }

    /** The target's owner, who the target's Actions authorise. */
    public static function actor(Company $target): User
    {
        $owner = $target->loadMissing('owner')->owner;

        if (! $owner instanceof User || $owner->anonymised_at !== null) {
            throw new RuntimeException('Målföretaget saknar en ägare som kan göra ändringen.');
        }

        return $owner;
    }

    /**
     * Keyed hashes of the values, so the link can tell what changed without
     * keeping the values (a personal number among them).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function hashes(array $values): array
    {
        $key = hash_hmac('sha256', 'nonox-operator-sync', (string) config('app.key'));

        return array_map(fn (mixed $value): string => hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), $key), $values);
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array{0: string, 1: OperatorLink, 2: ?Customer}
     */
    private function attach(Company $source, Company $target, User $actor, array $values, ?OperatorLink $link): array
    {
        $prospect = $this->modules->isEnabled(CrmModule::KEY, $target) ? $this->queueProspect($source, $target) : null;

        if ($prospect !== null) {
            // The queue applicant's link becomes this company's: one row, no duplicate.
            $link?->delete();
            $link = $prospect;
            $link->source_company_id = $source->id;

            $customer = CurrentCompany::run($target, function () use ($link, $target, $actor, $values): ?Customer {
                $account = Account::query()->find($link->crm_account_id);

                if ($account === null) {
                    return null;
                }

                if ($account->customer_id !== null) {
                    return Customer::query()->find($account->customer_id);
                }

                $customer = ($this->convertToCustomer)($actor, $target, $account);
                $link->customer_created = true;

                // The company's own details replace what the applicant wrote in the queue.
                return $this->write($actor, $target, $customer, $values);
            });

            if ($customer !== null) {
                $link->forceFill(['customer_id' => $customer->id, 'pushed' => self::hashes([...$values, 'archived' => false])]);

                return [self::CREATED, $link, $customer];
            }
        }

        $link ??= new OperatorLink(['target_company_id' => $target->id, 'source_company_id' => $source->id]);

        $existing = $this->existingCustomer($target, $values['org_number']);

        if ($existing !== null) {
            // The operator's own customer: linked as it is, only later changes in the source are pushed.
            $link->forceFill(['customer_id' => $existing->id, 'customer_created' => false, 'pushed' => self::hashes([...$values, 'archived' => $source->isArchived()])])->save();

            return [self::LINKED, $link, $existing];
        }

        $customer = ($this->saveCustomer)($actor, $target, [...array_filter($values, fn (?string $value): bool => $value !== null), 'payment_terms_days' => 30]);

        $link->forceFill(['customer_id' => $customer->id, 'customer_created' => true, 'pushed' => self::hashes([...$values, 'archived' => false])])->save();

        return [self::CREATED, $link, $customer];
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array{0: string, 1: OperatorLink, 2: ?Customer}
     */
    private function update(Company $source, Company $target, User $actor, array $values, OperatorLink $link): array
    {
        $customer = CurrentCompany::run($target, fn (): ?Customer => Customer::query()->find($link->customer_id));

        if ($customer === null || $customer->isRestricted() || self::anonymised($customer)) {
            return [self::UNCHANGED, $link, null];
        }

        $now = [...$values, 'archived' => $source->isArchived()];
        $hashes = self::hashes($now);
        $pushed = (array) $link->pushed;
        $changed = array_keys(array_filter($hashes, fn (string $hash, string $field): bool => ($pushed[$field] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return [self::UNCHANGED, $link, $customer];
        }

        $fields = array_intersect_key($values, array_flip($changed));

        if (in_array('archived', $changed, true)) {
            $fields['is_active'] = ! $now['archived'];
        }

        $customer = $this->write($actor, $target, $customer, $fields);
        $link->forceFill(['pushed' => $hashes]);

        return [self::UPDATED, $link, $customer];
    }

    /**
     * Writes fields to a customer through SaveCustomer, which needs the
     * whole record: the customer's current values, with these on top.
     *
     * @param  array<string, mixed>  $fields
     */
    private function write(User $actor, Company $target, Customer $customer, array $fields): Customer
    {
        $keep = ['name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'payment_terms_days', 'is_active', 'reverse_charge_construction', 'foreign_sale', 'currency'];
        $input = [];
        // A customer just created holds only what was given; the database's defaults (is_active) come with a fresh read.
        $customer = CurrentCompany::run($target, fn (): Customer => $customer->refresh());

        foreach ($keep as $field) {
            $value = $customer->getAttribute($field);
            $input[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        // An empty personal number would not clear the stored one; the register keeps what it has.
        if (array_key_exists('personal_number', $fields) && $fields['personal_number'] === null) {
            unset($fields['personal_number']);
        }

        return ($this->saveCustomer)($actor, $target, [...$input, ...$fields], $customer);
    }

    /** The CRM prospect from the queue entry of the company's owner, not yet any company's. */
    private function queueProspect(Company $source, Company $target): ?OperatorLink
    {
        return OperatorLink::query()
            ->where('target_company_id', $target->id)
            ->whereNull('source_company_id')
            ->whereNotNull('crm_account_id')
            ->whereIn('waitlist_entry_id', DB::table('waitlist_entries')->where('approved_user_id', $source->user_id)->select('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    /** The one customer of the target with this organisation number that no other company is linked to. */
    private function existingCustomer(Company $target, ?string $orgNumber): ?Customer
    {
        if ($orgNumber === null) {
            return null;
        }

        return CurrentCompany::run($target, function () use ($target, $orgNumber): ?Customer {
            $matches = Customer::query()
                ->where('org_number', $orgNumber)
                ->whereNotIn('id', OperatorLink::query()->where('target_company_id', $target->id)->whereNotNull('customer_id')->select('customer_id'))
                ->limit(2)
                ->get();

            return $matches->count() === 1 ? $matches->first() : null;
        });
    }

    /** Anonymised on request in the target's register (EraseRegisterEntry). */
    private static function anonymised(Customer $customer): bool
    {
        return AuditLog::query()
            ->where('company_id', $customer->company_id)
            ->where('action', 'privacy.erased')
            ->where('subject_type', $customer->getMorphClass())
            ->where('subject_id', (string) $customer->id)
            ->exists();
    }

    /**
     * The owner of the company as a contact person on the customer's CRM
     * account, under the agreement. A person the queue already brought in
     * with consent is the same person: linked, and the legal basis becomes
     * the agreement.
     */
    private function ensureOwnerContact(OperatorLink $link, Company $source, Company $target, User $actor, Customer $customer): void
    {
        $owner = $source->loadMissing('owner')->owner;

        if (! $owner instanceof User || $owner->anonymised_at !== null) {
            return;
        }

        CurrentCompany::run($target, function () use ($link, $target, $actor, $customer, $owner): void {
            $account = Account::query()->where('customer_id', $customer->id)->first();

            if ($account === null) {
                return;
            }

            $email = User::normaliseEmail($owner->email);
            $person = $link->crm_person_id === null ? null : Person::query()->find($link->crm_person_id);

            if ($person !== null && User::normaliseEmail((string) $person->email) !== $email) {
                // Ownership has moved: the former owner stays to the CRM's own pruning.
                $person = null;
            }

            $person ??= Person::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

            if ($person === null) {
                if (SuppressionList::match($email)) {
                    return;
                }

                $person = ($this->savePerson)($actor, $target, [
                    'name' => $owner->name,
                    'email' => $email,
                    'legal_basis' => LegalBasis::Agreement->value,
                    'legal_basis_note' => __('Ägare till ett företag som använder tjänsten.'),
                    'account_id' => $account->id,
                    'role' => PersonRole::DecisionMaker->value,
                ]);
                $link->forceFill(['crm_person_id' => $person->id, 'person_created' => true]);

                return;
            }

            if ($person->do_not_contact_at !== null) {
                return;
            }

            if (! AccountPerson::query()->where('account_id', $account->id)->where('person_id', $person->id)->exists()) {
                ($this->linkPerson)($actor, $target, $account, $person, PersonRole::DecisionMaker);
            }

            if ($person->legal_basis === LegalBasis::Consent && $link->crm_person_id === $person->id) {
                ($this->savePerson)($actor, $target, [
                    'name' => $person->name,
                    'title' => $person->title,
                    'email' => $person->email,
                    'phone' => $person->phone,
                    'mobile' => $person->mobile,
                    'legal_basis' => LegalBasis::Agreement->value,
                    'legal_basis_note' => __('Ägare till ett företag som använder tjänsten.'),
                    'informed_at' => $person->informed_at?->toDateString(),
                ], $person);
            }

            $link->forceFill(['crm_person_id' => $person->id]);
        });
    }
}
