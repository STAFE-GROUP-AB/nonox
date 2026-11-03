<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Models\Company;
use App\Models\OperatorLink;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * What happens to the CRM prospect when its waiting-list entry is deleted
 * (ADR-044): by HQ (Radera anmälan, also on the applicant's request), or by
 * waitlist:prune when the retention period is over. Runs in the caller's
 * transaction, before the entry is deleted.
 *
 * - The prospect has become a customer, or the link belongs to a company
 *   by now: nothing is erased, the entry is only unlinked.
 * - Otherwise the sync's own records are erased through EraseCrmData:
 *   the account when the sync created it and nobody has worked on it (no
 *   activity and no deal on it, and it is no customer), and the person
 *   when the sync created them, no activity names them and they are at no
 *   other account. Anything a person has worked on stays, unlinked, for
 *   the CRM's own pruning (24 months without activity by default) or an
 *   erasure by hand in the CRM.
 *
 * TODO(legal-verify): whether a prospect a seller has worked on may stay
 * after the applicant asked for erasure of the queue entry, or must be
 * erased in the CRM too (HQ says so on deletion; see ADR-044).
 */
final class ReleaseWaitlistProspect
{
    public function __construct(private readonly EraseCrmData $erase) {}

    /** @return array{erased_accounts: int, erased_people: int, kept: int} */
    public function __invoke(int $entryId): array
    {
        $result = ['erased_accounts' => 0, 'erased_people' => 0, 'kept' => 0];

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

            [$accounts, $people, $kept] = DB::transaction(fn () => ArchivedCompany::maintenance(fn () => CurrentCompany::run($target, fn () => $this->release($link, $target))));

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
            ]);

            $link->delete();
        }

        return $result;
    }

    /** @return array{0: int, 1: int, 2: int} erased accounts, erased people, kept records */
    private function release(OperatorLink $link, Company $target): array
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
        $erasable = $link->account_created ? $idle : null;

        if ($person !== null) {
            // The prospect's own account counts only when someone has worked on it.
            $elsewhere = AccountPerson::query()
                ->where('person_id', $person->id)
                ->when($idle !== null, fn ($query) => $query->where('account_id', '<>', $idle?->id))
                ->exists();
            $named = Activity::query()->where('person_id', $person->id)->exists();

            if ($link->person_created && ! $elsewhere && ! $named) {
                $this->erase->person(null, $target, $person);
                $people++;
            } elseif ($link->person_created) {
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
}
