<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Pos\PosModule;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may do what with the tills (ADR-042). The module must be on; then
 * connecting, disconnecting and the connection's settings need
 * settings:manage, seeing the page and its log inventory:view (or
 * settings:manage), linking an unmatched line inventory:manage and
 * proposing the daily voucher vouchers:create.
 */
final class PosGate
{
    public static function manage(User $user, Company $company): void
    {
        self::require($user, $company, 'settings:manage');
    }

    public static function view(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(PosModule::KEY, $company);

        if (! self::canView($user, $company)) {
            throw new AuthorizationException;
        }
    }

    public static function canView(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:view') || $user->hasTeamPermission($company, 'settings:manage');
    }

    public static function link(User $user, Company $company): void
    {
        self::require($user, $company, 'inventory:manage');
    }

    public static function draft(User $user, Company $company): void
    {
        self::require($user, $company, 'vouchers:create');
    }

    private static function require(User $user, Company $company, string $permission): void
    {
        app(ModuleManager::class)->ensureEnabled(PosModule::KEY, $company);

        if (! $user->hasTeamPermission($company, $permission)) {
            throw new AuthorizationException;
        }
    }
}
