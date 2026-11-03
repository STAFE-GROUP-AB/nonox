<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Domain\Waitlist\WaitlistStatus;
use App\Models\Company;
use App\Models\OperatorLink;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\IdentityNumber;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Makes a queue applicant a CRM prospect of the waitlist target company
 * (ADR-044): an account with the company name (and the organisation number
 * when it is not a personal number) and source "Kö", and the applicant as
 * its contact person with consent as the legal basis, dated when they
 * consented on /ko; informed the same moment, since the form showed the
 * privacy policy.
 *
 * Needs CRM on in the target (else nothing happens and HQ warns). Never
 * twice for one entry (operator_links). Nothing is created for an address
 * or phone number on the target's suppression list, or when the target
 * already has the person marked Kontakta inte. An existing account with
 * the same organisation number and an existing person with the same e-mail
 * are reused, and then never erased by ReleaseWaitlistProspect. A rejected
 * entry, or an approved one whose user already has a company, gets nothing.
 */
final class SyncWaitlistEntryToOperator
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public const string SUPPRESSED = 'suppressed';

    public const string SKIPPED = 'skipped';

    public const string CRM_OFF = 'crm_off';

    public const string SOURCE = 'Kö';

    public function __construct(
        private readonly SaveAccount $saveAccount,
        private readonly SavePerson $savePerson,
        private readonly LinkPerson $linkPerson,
        private readonly ModuleManager $modules,
    ) {}

    public function __invoke(WaitlistEntry $entry, Company $target): string
    {
        if (! $this->modules->isEnabled(CrmModule::KEY, $target)) {
            return self::CRM_OFF;
        }

        if ($entry->status === WaitlistStatus::Rejected || $this->hasCompany($entry)) {
            return self::SKIPPED;
        }

        $actor = SyncCompanyToOperator::actor($target);

        return Cache::lock("operator-sync:waitlist:{$target->id}:{$entry->id}", 30)->block(10, fn (): string => DB::transaction(function () use ($entry, $target, $actor): string {
            $link = OperatorLink::query()->where('target_company_id', $target->id)->where('waitlist_entry_id', $entry->id)->lockForUpdate()->first();

            if ($link !== null && in_array($link->status, [OperatorLink::SYNCED, OperatorLink::SUPPRESSED], true)) {
                return self::UNCHANGED;
            }

            $link ??= new OperatorLink(['target_company_id' => $target->id, 'waitlist_entry_id' => $entry->id]);

            $outcome = CurrentCompany::run($target, function () use ($entry, $target, $actor, $link): string {
                $email = User::normaliseEmail($entry->email);
                $person = Person::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

                if (SuppressionList::match($email, $entry->phone) || $person?->do_not_contact_at !== null) {
                    $link->forceFill(['status' => OperatorLink::SUPPRESSED]);

                    return self::SUPPRESSED;
                }

                $orgNumber = IdentityNumber::forCrm($entry->org_number);
                $account = $orgNumber === null ? null : Account::query()->where('org_number', $orgNumber)->orderBy('id')->first();
                $accountCreated = $account === null;

                $account ??= ($this->saveAccount)($actor, $target, [
                    'name' => $entry->company_name,
                    'org_number' => $orgNumber,
                    'kind' => AccountKind::Company->value,
                    'source' => self::SOURCE,
                ]);

                $personCreated = $person === null;

                if ($person === null) {
                    $consented = $entry->consent_at->timezone((string) config('app.timezone'))->toDateString();

                    $person = ($this->savePerson)($actor, $target, [
                        'name' => $entry->name,
                        'email' => $email,
                        'phone' => $entry->phone,
                        'legal_basis' => LegalBasis::Consent->value,
                        'legal_basis_note' => __('Anmälan till kön på webbplatsen.'),
                        'consent_at' => $consented,
                        'informed_at' => $consented,
                        'account_id' => $account->id,
                        'role' => PersonRole::Other->value,
                    ]);
                } elseif (! AccountPerson::query()->where('account_id', $account->id)->where('person_id', $person->id)->exists()) {
                    ($this->linkPerson)($actor, $target, $account, $person, PersonRole::Other);
                }

                $link->forceFill([
                    'status' => OperatorLink::SYNCED,
                    'crm_account_id' => $account->id,
                    'crm_person_id' => $person->id,
                    'account_created' => $accountCreated,
                    'person_created' => $personCreated,
                ]);

                return self::CREATED;
            });

            $link->forceFill(['error' => null, 'failed_at' => null, 'synced_at' => now()])->save();

            // Ids and the outcome only: the entry itself is deleted later, the log is not.
            Audit::instance('hq.operator_prospect_synced', null, [
                'waitlist_entry_id' => $entry->id,
                'target_company_id' => $target->id,
                'crm_account_id' => $link->crm_account_id,
                'outcome' => $outcome,
            ]);

            return $outcome;
        }));
    }

    /** An approved applicant who already owns a company: that company becomes the customer instead. */
    private function hasCompany(WaitlistEntry $entry): bool
    {
        return $entry->approved_user_id !== null
            && Company::query()->where('user_id', $entry->approved_user_id)->where('is_test_company', false)->exists();
    }
}
