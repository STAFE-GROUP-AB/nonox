<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Models\Company;
use App\Models\IntercompanyCounterparty;
use App\Models\User;
use App\Policies\IntercompanyPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a customer's or supplier's group company marking. The tags it gave
 * stay until a person runs "Uppdatera koncernmarkeringar", which then shows
 * them as tags to remove; tags a person set by hand are never touched.
 */
final class UnmarkIntercompanyCounterparty
{
    /** @throws AuthorizationException */
    public function __invoke(User $user, Company $company, int $markingId): void
    {
        if (! IntercompanyPolicy::mayWrite($user, $company)) {
            throw new AuthorizationException;
        }

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $markingId): void {
            // Inside the company: another company's marking id answers 404.
            $marking = IntercompanyCounterparty::query()->lockForUpdate()->findOrFail($markingId);

            Audit::record('intercompany_counterparty.unmarked', $marking, array_filter([
                'customer_id' => $marking->customer_id,
                'supplier_id' => $marking->supplier_id,
            ]), $company, $user);

            $marking->delete();
        }));
    }
}
