<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyRowTag;
use App\Models\User;

/**
 * Intercompany counterparties and row tags (ADR-060, addendum 2026-11-07,
 * 2.3): everyone who sees vouchers sees them; writing them needs
 * vouchers:create or accounts:manage in the company.
 */
abstract class IntercompanyPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'vouchers:view');
    }

    public function create(User $user, Company $company): bool
    {
        return self::mayWrite($user, $company);
    }

    public function delete(User $user, IntercompanyCounterparty|IntercompanyRowTag $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && self::mayWrite($user, $company);
    }

    public static function mayWrite(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'vouchers:create') || $user->hasTeamPermission($company, 'accounts:manage');
    }
}
