<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The company's members (the owner and everyone in team_user), read with a
 * query of their own. Jetstream's allUsers() loads the users relation onto
 * the Company it is given, and Jetstream's role checks then read that
 * cached list: a member added later in the same request would have no role.
 */
final class CompanyMembers
{
    /** @return Collection<int, User> by name */
    public static function all(Company $company): Collection
    {
        return User::query()
            ->where(fn ($query) => $query
                ->whereKey($company->user_id)
                ->orWhereIn('id', fn ($members) => $members->select('user_id')->from('team_user')->where('team_id', $company->id)))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return list<int> */
    public static function ids(Company $company): array
    {
        return array_values(array_map('intval', self::all($company)->pluck('id')->all()));
    }
}
