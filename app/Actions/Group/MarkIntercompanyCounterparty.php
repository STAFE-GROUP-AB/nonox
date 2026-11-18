<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\IntercompanyOrgNumber;
use App\Domain\Group\IntercompanySide;
use App\Models\Company;
use App\Models\Customer;
use App\Models\IntercompanyCounterparty;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\IntercompanyPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a customer or supplier of the company as a group company (ADR-060,
 * addendum 2026-11-07, 2.3), or changes the organisation number of a
 * marking. The other group company is named by organisation number only;
 * the number defaults to the customer's or supplier's own. Needs
 * vouchers:create or accounts:manage. Nothing is tagged here: rows get
 * their tags when a person runs "Uppdatera koncernmarkeringar".
 */
final class MarkIntercompanyCounterparty
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, IntercompanySide $side, int $partyId, ?string $orgNumber = null): IntercompanyCounterparty
    {
        if (! IntercompanyPolicy::mayWrite($user, $company)) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): IntercompanyCounterparty => DB::transaction(function () use ($user, $company, $side, $partyId, $orgNumber): IntercompanyCounterparty {
            // Inside the company: another company's customer or supplier id answers 404.
            $party = $side === IntercompanySide::Customer
                ? Customer::query()->lockForUpdate()->findOrFail($partyId)
                : Supplier::query()->lockForUpdate()->findOrFail($partyId);

            $given = trim((string) $orgNumber);
            $number = IntercompanyOrgNumber::validated($company, $given !== '' ? $given : (string) $party->org_number, 'counterparty_org_number');
            $column = $side === IntercompanySide::Customer ? 'customer_id' : 'supplier_id';

            $marking = IntercompanyCounterparty::query()->where($column, $party->id)->lockForUpdate()->first();
            $created = $marking === null;
            $marking ??= new IntercompanyCounterparty(['company_id' => $company->id, $column => $party->id, 'created_by' => $user->id]);
            $marking->counterparty_org_number = $number;

            if (! $created && ! $marking->isDirty()) {
                return $marking;
            }

            $marking->save();

            Audit::record($created ? 'intercompany_counterparty.marked' : 'intercompany_counterparty.updated', $marking, [
                $column => $party->id,
                // Field names only: the log never keeps the number as a value.
                'changed' => ['counterparty_org_number'],
            ], $company, $user);

            return $marking;
        }));
    }
}
