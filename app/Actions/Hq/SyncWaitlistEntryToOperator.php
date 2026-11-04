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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Makes a queue applicant a CRM prospect of the operator company (ADR-044):
 * an account with the company name (and the organisation number when it is
 * not a personal number) and source "Kö", and the applicant as its contact
 * person with consent as the legal basis, dated when they consented on
 * /ko. Informed the same day, since the form showed the privacy policy; a
 * consent given before the policy mentioned the CRM counts as informed
 * from INFORMED_FROM, with a note.
 *
 * Needs CRM on in the operator company (else nothing happens and HQ warns).
 * Never twice for one entry (operator_links). Nothing is created for an
 * address or phone number on the suppression list, or when the operator
 * already has the person marked Kontakta inte. When the operator already
 * has an account with the same organisation number or a person with the
 * same e-mail, nothing is linked or created: the link waits in NEEDS_REVIEW
 * until an HQ admin chooses Koppla (use them) or Skapa nytt. A rejected
 * entry, or an approved one whose user already has a company, gets nothing.
 * Every audit entry the sync writes carries `via: operator_sync`.
 */
final class SyncWaitlistEntryToOperator
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public const string SUPPRESSED = 'suppressed';

    public const string SKIPPED = 'skipped';

    public const string CRM_OFF = 'crm_off';

    public const string LINKED = 'linked';

    public const string NEEDS_REVIEW = 'needs_review';

    /**
     * The day the privacy policy (version 1.4) first said that queue
     * applicants are also kept in the service's CRM. A consent given before
     * it is recorded as informed on this day, not on the day of the consent.
     */
    public const string INFORMED_FROM = '2026-11-03';

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

        return Audit::withContext(['via' => 'operator_sync'], fn (): string => Cache::lock("operator-sync:waitlist:{$target->id}:{$entry->id}", 30)->block(10, fn (): string => DB::transaction(function () use ($entry, $target, $actor): string {
            $link = OperatorLink::query()->where('target_company_id', $target->id)->where('waitlist_entry_id', $entry->id)->lockForUpdate()->first();

            if ($link !== null && in_array($link->status, [OperatorLink::SYNCED, OperatorLink::SUPPRESSED], true)) {
                return self::UNCHANGED;
            }

            // Waiting for an HQ admin: nothing is created until they decide.
            if ($link?->status === OperatorLink::NEEDS_REVIEW && $link->review_decision === null) {
                return self::NEEDS_REVIEW;
            }

            $link ??= new OperatorLink(['target_company_id' => $target->id, 'waitlist_entry_id' => $entry->id]);

            $outcome = CurrentCompany::run($target, function () use ($entry, $target, $actor, $link): string {
                $email = User::normaliseEmail($entry->email);
                $existingPerson = Person::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

                if (SuppressionList::match($email, $entry->phone) || $existingPerson?->do_not_contact_at !== null) {
                    $link->forceFill(['status' => OperatorLink::SUPPRESSED]);

                    return self::SUPPRESSED;
                }

                $orgNumber = IdentityNumber::forCrm($entry->org_number);
                $existingAccount = $orgNumber === null ? null : Account::query()->where('org_number', $orgNumber)->orderBy('id')->first();

                // Anyone can type any organisation number and address on /ko: an existing record is never linked without an HQ admin.
                if ($link->review_decision === null && ($existingAccount !== null || $existingPerson !== null)) {
                    $link->forceFill([
                        'status' => OperatorLink::NEEDS_REVIEW,
                        'review_account_id' => $existingAccount?->id,
                        'review_person_id' => $existingPerson?->id,
                    ]);

                    return self::NEEDS_REVIEW;
                }

                $linkExisting = $link->review_decision === OperatorLink::DECISION_LINK;
                $account = $linkExisting && $link->review_account_id !== null ? Account::query()->find($link->review_account_id) : null;
                $person = $linkExisting && $link->review_person_id !== null ? Person::query()->find($link->review_person_id) : null;
                $accountCreated = $account === null;
                $personCreated = $person === null;

                $account ??= ($this->saveAccount)($actor, $target, [
                    'name' => $entry->company_name,
                    'org_number' => $orgNumber,
                    'kind' => AccountKind::Company->value,
                    'source' => self::SOURCE,
                ]);

                if ($person === null) {
                    $person = ($this->savePerson)($actor, $target, [
                        'name' => $entry->name,
                        'email' => $email,
                        'phone' => $entry->phone,
                        'legal_basis' => LegalBasis::Consent->value,
                        'legal_basis_note' => self::consentNote($entry),
                        'consent_at' => self::day($entry->consent_at),
                        'informed_at' => self::informedAt($entry),
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
                    'review_account_id' => null,
                    'review_person_id' => null,
                ]);

                return $linkExisting ? self::LINKED : self::CREATED;
            });

            $link->forceFill(['error' => null, 'failed_at' => null, 'synced_at' => now()])->save();

            // Ids and the outcome only: the entry itself is deleted later, the log is not.
            Audit::instance($outcome === self::NEEDS_REVIEW ? 'hq.operator_review_needed' : 'hq.operator_prospect_synced', $link, [
                'waitlist_entry_id' => $entry->id,
                'target_company_id' => $target->id,
                'crm_account_id' => $link->crm_account_id,
                'review_account_id' => $link->review_account_id,
                'review_person_id' => $link->review_person_id,
                'outcome' => $outcome,
                'via' => 'operator_sync',
            ]);

            return $outcome;
        })));
    }

    /** The calendar day, in Swedish time, of a moment. */
    private static function day(CarbonImmutable $moment): string
    {
        return $moment->timezone((string) config('app.timezone'))->toDateString();
    }

    /**
     * When the applicant was told that the queue answers are also kept in
     * the CRM: the day of the consent, or, for a consent given before the
     * privacy policy said so, the day the policy started to say it.
     */
    public static function informedAt(WaitlistEntry $entry): string
    {
        $consented = self::day($entry->consent_at);

        return $consented < self::INFORMED_FROM ? self::INFORMED_FROM : $consented;
    }

    private static function consentNote(WaitlistEntry $entry): string
    {
        return self::day($entry->consent_at) < self::INFORMED_FROM
            ? __('Anmälan till kön på webbplatsen. Informerad om CRM genom integritetspolicyn från :date.', ['date' => self::INFORMED_FROM])
            : __('Anmälan till kön på webbplatsen.');
    }

    /** An approved applicant who already owns a company: that company becomes the customer instead. */
    private function hasCompany(WaitlistEntry $entry): bool
    {
        return $entry->approved_user_id !== null
            && Company::query()->where('user_id', $entry->approved_user_id)->where('is_test_company', false)->exists();
    }
}
