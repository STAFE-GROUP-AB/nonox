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
 * Makes a company in the installation a customer of the operator company
 * (ADR-044).
 *
 * Everything is written in the operator company through its own Actions
 * inside CurrentCompany::run, acting as its owner (the system has no user
 * of its own) and with `via: operator_sync` on every audit entry, never
 * with raw inserts:
 *
 * - No link yet: the queue prospect the sync made for the company's owner
 *   becomes the customer (ConvertToCustomer). Otherwise, when the operator
 *   already has a customer or CRM account with the same organisation
 *   number that the sync did not create, nothing is linked, written or
 *   added: the link waits in NEEDS_REVIEW until an HQ admin chooses Koppla
 *   or Skapa ny kund (anyone can type any organisation number when they
 *   create a company). Otherwise SaveCustomer creates the customer.
 * - Linked: only the fields whose value changed in the source since the
 *   last push are written, so the operator's own edits stay. An archived
 *   company makes the customer inactive; taking it out of the archive
 *   makes it active again. A customer anonymised, restricted (GDPR) or
 *   removed in the operator company is never written to or created again.
 * - With CRM on: the owner of the company becomes a contact person on the
 *   customer's account: under the agreement for a sole trader (the owner
 *   is the customer), else under legitimate interest (a contact in their
 *   professional role). Not when the address is on the suppression list
 *   or the person there must not be contacted.
 *
 * A sole trader's number is a personal number: it goes to the register's
 * encrypted personal_number, never to org_number, and the VAT number (which
 * contains it) is not copied. TODO(legal-verify): dataskyddslagen 3 kap.
 * 10 § (when a personal number may be processed) for a sole trader's
 * number in the operator's customer register.
 */
final class SyncCompanyToOperator
{
    public const string CREATED = 'created';

    public const string LINKED = 'linked';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    public const string SKIPPED = 'skipped';

    public const string NEEDS_REVIEW = 'needs_review';

    /** The context string the change-tracking key is derived with from APP_KEY. */
    private const string HASH_CONTEXT = 'nonox-operator-sync-change-tracking-v1';

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

        return Audit::withContext(['via' => 'operator_sync'], fn (): string => Cache::lock("operator-sync:company:{$target->id}:{$source->id}", 30)->block(10, fn (): string => DB::transaction(function () use ($source, $target, $actor): string {
            $link = OperatorLink::query()->where('target_company_id', $target->id)->where('source_company_id', $source->id)->lockForUpdate()->first();

            // An archived company that never was a customer does not become one now.
            if ($link?->customer_id === null && $source->isArchived()) {
                return self::SKIPPED;
            }

            // The customer was linked once and has been removed in the target since: the target's decision stands.
            if ($link !== null && $link->synced_at !== null && $link->customer_id === null && $link->status === OperatorLink::SYNCED) {
                return self::SKIPPED;
            }

            // Waiting for an HQ admin: nothing is written until they decide.
            if ($link?->status === OperatorLink::NEEDS_REVIEW && $link->review_decision === null) {
                return self::NEEDS_REVIEW;
            }

            $values = self::values($source);

            [$outcome, $link, $customer] = $link?->customer_id === null
                ? $this->attach($source, $target, $actor, $values, $link)
                : $this->update($source, $target, $actor, $values, $link);

            if ($outcome === self::NEEDS_REVIEW) {
                $link->forceFill(['status' => OperatorLink::NEEDS_REVIEW, 'error' => null, 'failed_at' => null])->save();

                Audit::instance('hq.operator_review_needed', $link, [
                    'source_company_id' => $source->id,
                    'target_company_id' => $target->id,
                    'review_customer_id' => $link->review_customer_id,
                    'review_account_id' => $link->review_account_id,
                ]);

                return $outcome;
            }

            if ($customer !== null && $this->modules->isEnabled(CrmModule::KEY, $target)) {
                $this->ensureOwnerContact($link, $source, $target, $actor, $customer);
            }

            $link->forceFill([
                'status' => OperatorLink::SYNCED,
                'review_customer_id' => null,
                'review_account_id' => null,
                'review_person_id' => null,
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
                    'via' => 'operator_sync',
                ]);
            }

            return $outcome;
        })));
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
     * keeping the values (a personal number among them). The key is derived
     * from APP_KEY with HKDF and a fixed context string, and `_key` keeps a
     * fingerprint of it: after an APP_KEY rotation the hashes no longer
     * match, so the next sync takes the current values as the new baseline
     * instead of overwriting the operator's own edits.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function hashes(array $values): array
    {
        $key = self::hashKey();

        return [
            ...array_map(fn (mixed $value): string => hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), $key), $values),
            '_key' => self::keyFingerprint(),
        ];
    }

    private static function hashKey(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, self::HASH_CONTEXT);
    }

    private static function keyFingerprint(): string
    {
        return substr(hash_hmac('sha256', 'fingerprint', self::hashKey()), 0, 16);
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array{0: string, 1: OperatorLink, 2: ?Customer}
     */
    private function attach(Company $source, Company $target, User $actor, array $values, ?OperatorLink $link): array
    {
        $prospect = $link?->review_decision === null && $this->modules->isEnabled(CrmModule::KEY, $target) ? $this->queueProspect($source, $target) : null;

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

        if ($link->review_decision === OperatorLink::DECISION_LINK) {
            $chosen = $this->chosenCustomer($link, $target, $actor);

            if ($chosen !== null) {
                // Linked as it is, by an HQ admin's decision: only later changes in the source are pushed.
                $link->forceFill(['customer_id' => $chosen->id, 'pushed' => self::hashes([...$values, 'archived' => $source->isArchived()])])->save();

                return [self::LINKED, $link, $chosen];
            }
        }

        if ($link->review_decision === null) {
            [$customerId, $accountId] = $this->candidates($target, $values['org_number']);

            if ($customerId !== null || $accountId !== null) {
                $link->forceFill(['review_customer_id' => $customerId, 'review_account_id' => $accountId]);

                return [self::NEEDS_REVIEW, $link, null];
            }
        }

        $customer = ($this->saveCustomer)($actor, $target, [...array_filter($values, fn (?string $value): bool => $value !== null), 'payment_terms_days' => 30]);

        $link->forceFill(['customer_id' => $customer->id, 'customer_created' => true, 'pushed' => self::hashes([...$values, 'archived' => false])])->save();

        return [self::CREATED, $link, $customer];
    }

    /**
     * The customer an HQ admin chose to link to: the candidate customer, or
     * the candidate CRM account, made a customer when it is not one yet.
     */
    private function chosenCustomer(OperatorLink $link, Company $target, User $actor): ?Customer
    {
        return CurrentCompany::run($target, function () use ($link, $target, $actor): ?Customer {
            if ($link->review_customer_id !== null) {
                return Customer::query()->find($link->review_customer_id);
            }

            $account = $link->review_account_id === null ? null : Account::query()->find($link->review_account_id);

            if ($account === null) {
                return null;
            }

            if ($account->customer_id !== null) {
                return Customer::query()->find($account->customer_id);
            }

            $customer = ($this->convertToCustomer)($actor, $target, $account);
            $link->forceFill(['crm_account_id' => $account->id, 'customer_created' => true]);

            return $customer;
        });
    }

    /**
     * Records the operator already has with this organisation number and
     * that the sync did not create: a customer no link points at, or a CRM
     * account that is not a customer.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function candidates(Company $target, ?string $orgNumber): array
    {
        if ($orgNumber === null) {
            return [null, null];
        }

        return CurrentCompany::run($target, function () use ($target, $orgNumber): array {
            $customerId = Customer::query()
                ->where('org_number', $orgNumber)
                ->whereNotIn('id', OperatorLink::query()->where('target_company_id', $target->id)->whereNotNull('customer_id')->select('customer_id'))
                ->orderBy('id')
                ->value('id');

            $accountId = $customerId !== null ? null : Account::query()
                ->where('org_number', $orgNumber)
                ->whereNull('customer_id')
                ->orderBy('id')
                ->value('id');

            return [$customerId === null ? null : (int) $customerId, $accountId === null ? null : (int) $accountId];
        });
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

        // A new APP_KEY: the old hashes say nothing, so today's values become the baseline.
        if (($pushed['_key'] ?? null) !== $hashes['_key']) {
            $link->forceFill(['pushed' => $hashes]);

            return [self::UNCHANGED, $link, $customer];
        }

        $changed = array_keys(array_filter($hashes, fn (string $hash, string $field): bool => $field !== '_key' && ($pushed[$field] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));

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

    /** The CRM prospect the sync made from the queue entry of the company's owner, not yet any company's. */
    private function queueProspect(Company $source, Company $target): ?OperatorLink
    {
        return OperatorLink::query()
            ->where('target_company_id', $target->id)
            ->whereNull('source_company_id')
            ->whereNotNull('crm_account_id')
            ->where('account_created', true)
            ->where('status', OperatorLink::SYNCED)
            ->whereIn('waitlist_entry_id', DB::table('waitlist_entries')->where('approved_user_id', $source->user_id)->select('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
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
     * The legal basis for the owner as the customer's contact person: the
     * agreement for a sole trader, whose owner is the customer; legitimate
     * interest for anyone else, a contact in their professional role.
     * TODO(legal-verify): the balancing test for company owners as contacts.
     *
     * @return array{0: LegalBasis, 1: string}
     */
    public static function ownerBasis(Company $source): array
    {
        return IdentityNumber::isPersonal($source->org_number)
            ? [LegalBasis::Agreement, __('Ägare till en enskild firma som är kund.')]
            : [LegalBasis::LegitimateInterest, __('Ägare till ett företag som är kund; kontakt i yrkesrollen.')];
    }

    /**
     * The owner of the company as a contact person on the customer's CRM
     * account. A person the queue already brought in with consent is the
     * same person: linked, and the legal basis follows ownerBasis().
     */
    private function ensureOwnerContact(OperatorLink $link, Company $source, Company $target, User $actor, Customer $customer): void
    {
        $owner = $source->loadMissing('owner')->owner;

        if (! $owner instanceof User || $owner->anonymised_at !== null) {
            return;
        }

        [$basis, $note] = self::ownerBasis($source);

        CurrentCompany::run($target, function () use ($link, $target, $actor, $customer, $owner, $basis, $note): void {
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
                    'legal_basis' => $basis->value,
                    'legal_basis_note' => $note,
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
                    'legal_basis' => $basis->value,
                    'legal_basis_note' => $note,
                    'informed_at' => $person->informed_at?->toDateString(),
                ], $person);
            }

            $link->forceFill(['crm_person_id' => $person->id]);
        });
    }
}
