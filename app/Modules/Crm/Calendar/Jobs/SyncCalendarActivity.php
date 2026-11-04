<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Jobs;

use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Writes one changed activity to its assignee's connected calendar, and
 * removes it from a former assignee's (ADR-049). Carries the company id and
 * sets the company itself (AGENTS.md §6.0).
 */
class SyncCalendarActivity implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param list<int> $userIds */
    public function __construct(public readonly int $companyId, public readonly int $activityId, public readonly array $userIds) {}

    public function handle(CalendarSync $sync): void
    {
        $company = CalendarJobGuard::company($this->companyId);

        if ($company === null) {
            return;
        }

        CurrentCompany::run($company, function () use ($sync, $company): void {
            $connections = CalendarConnection::query()->whereIn('user_id', $this->userIds)->where('status', CalendarConnection::ACTIVE)->get();

            if ($connections->isEmpty()) {
                return;
            }

            $activity = Activity::query()->with(CalendarEntries::relations())->find($this->activityId);

            foreach ($connections as $connection) {
                if (! CalendarJobGuard::userMay($connection, $company)) {
                    continue;
                }

                try {
                    $sync->syncActivity($connection, $activity, $this->activityId);
                } catch (CalendarProviderException $exception) {
                    CalendarJobGuard::note($connection, $exception);
                }
            }
        });
    }
}
