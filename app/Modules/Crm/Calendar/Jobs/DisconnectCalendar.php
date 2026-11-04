<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Jobs;

use App\Models\Company;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ends a calendar connection (ADR-049): removes the events NONOX wrote
 * (best effort), revokes the grant at the provider where it can, and
 * deletes the tokens and the event map. Runs also when the company is
 * archived or CRM is off: the tokens must go either way.
 */
class DisconnectCalendar implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $companyId, public readonly int $connectionId, public readonly bool $removeEvents = true) {}

    public function handle(CalendarSync $sync): void
    {
        $company = Company::query()->find($this->companyId);

        if (! $company instanceof Company) {
            return;
        }

        CurrentCompany::run($company, function () use ($sync, $company): void {
            $connection = CalendarConnection::query()->find($this->connectionId);

            if (! $connection instanceof CalendarConnection) {
                return;
            }

            $connection->forceFill(['status' => CalendarConnection::REVOKED])->save();
            self::end($sync, $connection, $this->removeEvents);
            Audit::record('crm.calendar_disconnected', null, ['connection_id' => $connection->id, 'provider' => $connection->provider->value, 'user_id' => $connection->user_id], $company);
        });
    }

    /** Removes the events (if asked), revokes the grant and deletes the row. Never throws. */
    public static function end(CalendarSync $sync, CalendarConnection $connection, bool $removeEvents): void
    {
        $provider = $sync->provider($connection);

        if ($removeEvents && $connection->calendar_id !== null && $provider->isConfigured()) {
            // removeAll needs the connection active for nothing but its token; isActive() is not checked there.
            $sync->removeAll($connection);
        }

        if ($provider->isConfigured()) {
            try {
                $provider->revoke($connection->refresh_token ?? $connection->access_token);
            } catch (\Throwable) {
                // Revocation is best effort; the tokens are deleted below whatever the answer.
            }
        }

        $connection->delete();
    }
}
