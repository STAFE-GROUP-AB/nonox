<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Models\Company;
use App\Models\OperatorLink;
use App\Models\WaitlistEntry;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Domain\IdentityNumber;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * What happens to the CRM prospect when its waiting-list entry is deleted
 * (ADR-044). Runs in the caller's transaction, before the entry is deleted.
 *
 * - The prospect has become a customer, or the link belongs to a company
 *   by now: nothing is erased, the entry is only unlinked.
 * - **On request** (HQ → Radera anmälan, GDPR art. 17): the person the sync
 *   created is always erased, even when someone has worked on them, and
 *   their e-mail and phone numbers go on the suppression list (hashes
 *   only), so a later sync cannot add them again. The account the sync
 *   created is erased too, unless someone has worked on it (activities or
 *   deals) and it is a company's: then it stays, without the person, as
 *   company data. A sole trader's account (the entry's number is a
 *   personal number) is about the person and is always erased.
 * - **By retention** (waitlist:prune): the sync's own account and person
 *   are erased unless someone has worked on them. A person who stays is
 *   no longer kept on the queue consent: the legal basis becomes
 *   legitimate interest (contact has been had), and the CRM's own pruning
 *   takes over. TODO(legal-verify): the balancing test for keeping a
 *   queue contact someone has worked on.
 *
 * Records the sync did not create (linked by an HQ admin's decision) are
 * never erased here.
 */
final class ReleaseWaitlistProspect
{
    public function __construct(
        private readonly EraseCrmData $erase,
        private readonly SavePerson $savePerson,
    ) {}

    /** @return array{erased_accounts: int, erased_people: int, kept: int} kept = records left in the CRM */
    public function __invoke(int $entryId, bool $request = false): array
    {
        $result = ['erased_accounts' => 0, 'erased_people' => 0, 'kept' => 0];

        $personal = IdentityNumber::isPersonal(WaitlistEntry::query()->whereKey($entryId)->value('org_number'));
        $links = OperatorLink::query()->where('waitlist_entry_id', $entryId)->lockForUpdate()->get();

        foreach ($links as $link) {
            if ($link->source_company_id !== null || $link->customer_id !== null) {
                $link->forceFill(['waitlist_entry_id' => null])->save();

                continue;
            }

            $target = Company::query()->find($link->target_company_id);

            if ($target === null) {
                $link->delete();

                continue;
            }

            [$accounts, $people, $kept] = DB::transaction(fn () => Audit::withContext(['via' => 'operator_sync'], fn () => ArchivedCompany::maintenance(fn () => CurrentCompany::run($target, fn () => $this->release($link, $target, $request, $personal)))));

            $result['erased_accounts'] += $accounts;
            $result['erased_people'] += $people;
            $result['kept'] += $kept;

            Audit::instance('hq.operator_prospect_released', null, [
                'target_company_id' => $target->id,
                'crm_account_id' => $link->crm_account_id,
                'crm_person_id' => $link->crm_person_id,
                'erased_accounts' => $accounts,
                'erased_people' => $people,
                'kept' => $kept,
                'by' => $request ? 'request' : 'retention',
            ]);

            $link->delete();
        }

        return $result;
    }

    /** @return array{0: int, 1: int, 2: int} erased accounts, erased people, kept records */
    private function release(OperatorLink $link, Company $target, bool $request, bool $personal): array
    {
        $account = $link->crm_account_id === null ? null : Account::query()->find($link->crm_account_id);
        $person = $link->crm_person_id === null ? null : Person::query()->find($link->crm_person_id);
        $accounts = $people = $kept = 0;

        $accountWorked = $account !== null && (
            $account->customer_id !== null
            || Activity::query()->where('account_id', $account->id)->exists()
            || Deal::query()->where('account_id', $account->id)->exists()
        );
        $idle = $account !== null && ! $accountWorked ? $account : null;

        // On request a sole trader's account goes even when worked on; a company's stays as company data.
        $erasable = $account !== null && $link->account_created && $account->customer_id === null && ($idle !== null || ($request && $personal)) ? $account : null;

        if ($person !== null && $link->person_created) {
            // The prospect's own account counts only when someone has worked on it.
            $elsewhere = AccountPerson::query()
                ->where('person_id', $person->id)
                ->when($idle !== null, fn ($query) => $query->where('account_id', '<>', $idle?->id))
                ->exists();
            $named = Activity::query()->where('person_id', $person->id)->exists();

            if ($request) {
                SuppressionList::suppress(SuppressionList::ERASURE, $person->email, $person->phone, $person->mobile);
                $this->erase->person(null, $target, $person);
                $people++;
            } elseif (! $elsewhere && ! $named) {
                $this->erase->person(null, $target, $person);
                $people++;
            } else {
                $this->keepAfterContact($target, $person);
                $kept++;
            }
        }

        if ($erasable !== null) {
            $this->erase->prospect(null, $target, $erasable);
            $accounts++;
        } elseif ($account !== null && $link->account_created) {
            $kept++;
        }

        return [$accounts, $people, $kept];
    }

    /** A queue contact someone has worked on, kept after the entry: no longer on the queue consent. */
    private function keepAfterContact(Company $target, Person $person): void
    {
        if ($person->legal_basis !== LegalBasis::Consent) {
            return;
        }

        $lastActivity = $person->last_activity_at;

        $person = ($this->savePerson)(SyncCompanyToOperator::actor($target), $target, [
            'name' => $person->name,
            'title' => $person->title,
            'email' => $person->email,
            'phone' => $person->phone,
            'mobile' => $person->mobile,
            'legal_basis' => LegalBasis::LegitimateInterest->value,
            'legal_basis_note' => __('Kontakt i kön; behålls efter kontakt med personen.'),
            'informed_at' => $person->informed_at?->toDateString(),
        ], $person);

        // A change of legal basis is not contact with the person: the CRM's pruning clock stays where it was.
        $person->forceFill(['last_activity_at' => $lastActivity])->save();
    }
}
