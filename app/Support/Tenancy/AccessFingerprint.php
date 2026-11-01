<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * What decides a user's access in a company, as one hash: their role (the
 * owner counts as "owner") and the modules that are on. Two users with the
 * same fingerprint may see the same things; a changed fingerprint means the
 * access changed. One query; the modules come from ModuleManager's cache.
 */
final class AccessFingerprint
{
    public static function for(User $user, int $companyId): string
    {
        $row = DB::table('teams')
            ->leftJoin('team_user', fn ($join) => $join->on('team_user.team_id', '=', 'teams.id')->where('team_user.user_id', $user->id))
            ->where('teams.id', $companyId)
            ->first(['teams.user_id', 'team_user.role']);
        $role = $row === null ? '' : ((int) $row->user_id === $user->id ? 'owner' : (string) $row->role);
        $modules = app(ModuleManager::class);
        $enabled = array_filter(array_keys(app(ModuleRegistry::class)->all()), fn (string $key): bool => $modules->isEnabled($key, $companyId));

        return hash('sha256', $role.'|'.implode(',', $enabled));
    }
}
