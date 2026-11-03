<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who the CRM's retention rule reaches (ADR-043): contact people and
 * prospects with no activity since $cutoff. Kept regardless: a prospect
 * that has become a customer (it is in the customer register and the
 * books), and a person who is the invoice recipient at a customer, whose
 * name may be on invoices. Queries for the current company.
 *
 * Stage 2: an account with an open deal, and the contact person of an open
 * deal, are kept too: the business is still going on.
 *
 * TODO(legal-verify): which links to the ledger keep a contact person.
 */
final class PruneCandidates
{
    /** @return Builder<Person> */
    public static function people(CarbonImmutable $cutoff): Builder
    {
        return Person::query()
            ->whereRaw('COALESCE(crm_people.last_activity_at, crm_people.updated_at) < ?', [$cutoff])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('crm_account_person')
                ->join('crm_accounts', 'crm_accounts.id', '=', 'crm_account_person.account_id')
                ->whereColumn('crm_account_person.person_id', 'crm_people.id')
                ->where('crm_account_person.role', PersonRole::InvoiceRecipient->value)
                ->whereNotNull('crm_accounts.customer_id'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('crm_deals')
                ->whereColumn('crm_deals.person_id', 'crm_people.id')
                ->where('crm_deals.status', DealStatus::Open->value));
    }

    /** @return Builder<Account> */
    public static function prospects(CarbonImmutable $cutoff): Builder
    {
        return Account::query()
            ->whereNull('customer_id')
            ->whereRaw('COALESCE(crm_accounts.last_activity_at, crm_accounts.updated_at) < ?', [$cutoff])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('crm_deals')
                ->whereColumn('crm_deals.account_id', 'crm_accounts.id')
                ->where('crm_deals.status', DealStatus::Open->value));
    }
}
