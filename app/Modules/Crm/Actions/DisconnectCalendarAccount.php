<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\Jobs\DisconnectCalendar;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * "Koppla bort" (ADR-049): the connection stops syncing at once; a queued
 * job removes NONOX's events from the calendar (if asked), revokes the
 * grant where the provider allows it, and deletes the tokens. The user's
 * own; no permission is needed to end one's own connection.
 */
final class DisconnectCalendarAccount
{
    /** @return bool whether there was a connection */
    public function __invoke(User $user, Company $company, bool $removeEvents = true): bool
    {
        $connection = CurrentCompany::run($company, fn (): ?CalendarConnection => DB::transaction(function () use ($user): ?CalendarConnection {
            $connection = CalendarConnection::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $connection?->forceFill(['status' => CalendarConnection::REVOKED])->save();

            return $connection;
        }));

        if (! $connection instanceof CalendarConnection) {
            return false;
        }

        DisconnectCalendar::dispatch($company->id, $connection->id, $removeEvents);

        return true;
    }
}
