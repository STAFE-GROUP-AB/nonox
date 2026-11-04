<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Jobs;

use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One full round for a connected calendar (ADR-049): write what is behind
 * (everything when $force, after the details choice changed), remove what
 * no longer belongs, then read the calendar's changes to NONOX's events.
 * Run by crm:calendar-sync every 15 minutes and right after connecting.
 */
class SyncCalendarConnection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $companyId, public readonly int $connectionId, public readonly bool $force = false) {}

    public function uniqueId(): string
    {
        return "crm-calendar-{$this->connectionId}";
    }

    public function handle(CalendarSync $sync): void
    {
        $company = CalendarJobGuard::company($this->companyId);

        if ($company === null) {
            return;
        }

        CurrentCompany::run($company, function () use ($sync, $company): void {
            $connection = CalendarConnection::query()->find($this->connectionId);

            if (! $connection instanceof CalendarConnection || ! $connection->isActive() || ! CalendarJobGuard::userMay($connection, $company)) {
                return;
            }

            try {
                $sync->pushAll($connection, $this->force);
                $sync->pull($connection);
            } catch (CalendarProviderException $exception) {
                CalendarJobGuard::note($connection, $exception);
            }
        });
    }
}
