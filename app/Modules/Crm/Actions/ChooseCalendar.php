<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\Calendar\Providers\RemoteCalendar;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Which of the user's own calendars NONOX writes to (ADR-049). Only a
 * calendar the provider lists as writable for the account can be chosen.
 * Moving to another calendar removes NONOX's events from the old one and
 * writes them to the new one.
 */
final class ChooseCalendar
{
    public function __construct(private readonly CalendarSync $sync) {}

    public function __invoke(User $user, Company $company, string $calendarId): void
    {
        Gate::forUser($user)->authorize('viewAny', [Activity::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $calendarId): void {
            $connection = CalendarConnection::query()->where('user_id', $user->id)->where('status', CalendarConnection::ACTIVE)->firstOrFail();

            if ($connection->calendar_id === $calendarId) {
                return;
            }

            $provider = $this->sync->provider($connection);
            $calendars = $this->sync->call($connection, fn (string $token): array => $provider->calendars($token));
            $chosen = collect($calendars)->first(fn (RemoteCalendar $calendar): bool => $calendar->id === $calendarId);

            if (! $chosen instanceof RemoteCalendar) {
                throw ValidationException::withMessages(['calendar' => __('Välj en av dina kalendrar som NONOX får skriva i.')]);
            }

            if ($connection->calendar_id !== null) {
                $this->sync->removeAll($connection);
            }

            $connection->forceFill(['calendar_id' => $chosen->id, 'calendar_name' => $chosen->name, 'sync_cursor' => null])->save();
            Audit::record('crm.calendar_chosen', $connection, ['provider' => $connection->provider->value], $company, $user);
            SyncCalendarConnection::dispatch($company->id, $connection->id);
        });
    }
}
