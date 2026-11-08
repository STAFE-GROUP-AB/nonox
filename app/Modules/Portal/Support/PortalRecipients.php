<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Modules\Portal\PortalModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;

/**
 * The company's users who hold a portal permission, in one query (ADR-075):
 * the owner when the module gives the owner the permission, and the members
 * whose role carries it, read the way Jetstream's hasTeamPermission reads a
 * role (the permission itself, "*", or "*:create" / "*:update").
 */
final class PortalRecipients
{
    /** @return Collection<int, User> */
    public static function withPermission(Company $company, string $permission): Collection
    {
        $roles = collect(Jetstream::$roles)
            ->filter(fn (Role $role): bool => self::grants($role->permissions, $permission))
            ->keys()
            ->all();
        $owner = in_array($permission, app(ModuleRegistry::class)->get(PortalModule::KEY)->rolePermissions()['owner'] ?? [], true);

        return User::query()
            ->whereNull('anonymised_at')
            ->where(fn ($query) => $query
                ->when($owner, fn ($query) => $query->orWhereKey($company->user_id))
                ->orWhereIn('id', fn ($members) => $members->select('user_id')->from('team_user')->where('team_id', $company->id)->whereIn('role', $roles === [] ? [''] : $roles)))
            ->get();
    }

    /** @param  array<int, string>  $permissions */
    private static function grants(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true)
            || in_array('*', $permissions, true)
            || (Str::endsWith($permission, ':create') && in_array('*:create', $permissions, true))
            || (Str::endsWith($permission, ':update') && in_array('*:update', $permissions, true));
    }
}
