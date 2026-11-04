<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What MarketingEligibility needs about people, read as plain rows in one
 * query (ADR-048): the person's own flags, and per account the kind, the
 * organisation number and the customer's e-mail and restrictions. For the
 * current company only.
 *
 * @phpstan-type CandidateAccount array{id: int, kind: string, org_number: ?string, customer_id: ?int, customer_email: ?string, customer_blocked: bool}
 * @phpstan-type Candidate array{id: int, email: string, active: bool, dnc: ?string, opt_out: bool, bounced: bool, marketing_consent: bool, customer_exception: bool, accounts: list<CandidateAccount>}
 */
final class MarketingCandidates
{
    /** The base query: crm_people joined with their accounts and those accounts' customers. */
    public static function base(): QueryBuilder
    {
        $companyId = CurrentCompany::id();

        return DB::table('crm_people')
            ->join('crm_account_person', 'crm_account_person.person_id', '=', 'crm_people.id')
            ->join('crm_accounts', 'crm_accounts.id', '=', 'crm_account_person.account_id')
            ->leftJoin('customers', 'customers.id', '=', 'crm_accounts.customer_id')
            ->where('crm_people.company_id', $companyId)
            ->where('crm_accounts.company_id', $companyId)
            ->select([
                'crm_people.id',
                'crm_people.email',
                'crm_people.is_active',
                'crm_people.do_not_contact_reason',
                'crm_people.marketing_opt_out_at',
                'crm_people.email_bounced_at',
                'crm_people.marketing_consent_at',
                'crm_people.customer_exception_at',
                'crm_accounts.id as account_id',
                'crm_accounts.kind',
                'crm_accounts.org_number',
                'crm_accounts.customer_id',
                'customers.email as customer_email',
                'customers.processing_restricted_at',
                'customers.is_active as customer_active',
            ])
            ->orderBy('crm_people.id')
            ->orderBy('crm_accounts.id');
    }

    /**
     * People with every account they are at.
     *
     * @param  list<int>  $personIds
     * @return array<int, Candidate>
     */
    public static function forPeople(array $personIds): array
    {
        return $personIds === [] ? [] : self::group(self::base()->whereIn('crm_people.id', $personIds)->get()->all());
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return array<int, Candidate>
     */
    public static function group(array $rows): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            $candidates[$id] ??= [
                'id' => $id,
                'email' => mb_strtolower(trim((string) $row->email)),
                'active' => (bool) $row->is_active,
                'dnc' => $row->do_not_contact_reason === null ? null : (string) $row->do_not_contact_reason,
                'opt_out' => $row->marketing_opt_out_at !== null,
                'bounced' => $row->email_bounced_at !== null,
                'marketing_consent' => $row->marketing_consent_at !== null,
                'customer_exception' => $row->customer_exception_at !== null,
                'accounts' => [],
            ];

            $candidates[$id]['accounts'][] = [
                'id' => (int) $row->account_id,
                'kind' => (string) $row->kind,
                'org_number' => $row->org_number === null ? null : (string) $row->org_number,
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'customer_email' => $row->customer_email === null ? null : mb_strtolower(trim((string) $row->customer_email)),
                'customer_blocked' => $row->customer_id !== null && ($row->processing_restricted_at !== null || ! (bool) $row->customer_active),
            ];
        }

        return $candidates;
    }
}
