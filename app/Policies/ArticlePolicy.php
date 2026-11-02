<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;

/**
 * Articles: the sales register, and with Lager on (ADR-039) also the stock
 * register, so whoever manages the stock may see and change them too.
 * Lager's permissions are false while the module is off.
 */
class ArticlePolicy extends SalesRegisterPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return parent::viewAny($user, $company) || $user->hasTeamPermission($company, 'inventory:manage');
    }

    public function create(User $user, Company $company): bool
    {
        return parent::create($user, $company) || $user->hasTeamPermission($company, 'inventory:manage');
    }

    public function update(User $user, Customer|Article $record): bool
    {
        if (parent::update($user, $record)) {
            return true;
        }

        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'inventory:manage');
    }
}
