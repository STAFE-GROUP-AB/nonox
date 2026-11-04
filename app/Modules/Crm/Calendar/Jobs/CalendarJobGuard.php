<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Jobs;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\CalendarRules;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\ModuleManager;

/**
 * The checks every calendar job makes first (ADR-049): the company exists,
 * is not archived and has CRM on; the connection's user is still a member
 * with crm:view, and the company still allows connections (CRM settings).
 * A connection that is no longer allowed is disconnected.
 */
final class CalendarJobGuard
{
    public static function company(int $companyId): ?Company
    {
        $company = Company::query()->find($companyId);

        if (! $company instanceof Company || $company->isArchived()) {
            return null;
        }

        return app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company) ? $company : null;
    }

    public static function userMay(CalendarConnection $connection, Company $company): bool
    {
        $user = User::query()->find($connection->user_id);

        // The company has switched the connections off, or the user is no longer allowed: the connection ends.
        if (CalendarRules::syncAllowed($company) && $user instanceof User && $user->hasTeamPermission($company, 'crm:view')) {
            return true;
        }

        DisconnectCalendar::dispatch($company->id, $connection->id);

        return false;
    }

    /** Keeps a short, token-free reason on the connection; the next run tries again. */
    public static function note(CalendarConnection $connection, CalendarProviderException $exception): void
    {
        if ($connection->status !== CalendarConnection::ACTIVE) {
            return;
        }

        $connection->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 255)])->save();
    }
}
