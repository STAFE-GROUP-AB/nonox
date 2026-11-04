<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\Providers\CalendarProvider;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\Calendar\Providers\RemoteChange;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarEvent;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Two-way sync between the CRM and one connected calendar (ADR-049). Runs
 * inside CurrentCompany::run for the connection's company (the jobs set it).
 *
 * NONOX → calendar: the user's open, timed calls, meetings and tasks
 * (CalendarEntries) are written; a done, moved-away, reassigned or no
 * longer allowed activity is removed. An event is written only when the
 * activity's calendar_sequence is ahead of what was last written.
 *
 * Calendar → NONOX, only for events NONOX wrote (crm_calendar_events);
 * every other event in the calendar is read past and never stored:
 * - moved: the activity's time and length follow, unless the activity
 *   changed in NONOX since it was last written (then NONOX wins and writes
 *   it again; ADR-049, conflict policy);
 * - cancelled or deleted: the activity is closed with the note "Inställd i
 *   kalendern", except the last open step of an open deal, which stays
 *   open in NONOX (a deal never loses its next step from outside); the
 *   event map remembers the cancellation, so the event is written again
 *   only when the activity changes in NONOX.
 *
 * A grant that is gone puts the connection in ERROR: the user is asked to
 * connect again, and nothing more is tried.
 */
final class CalendarSync
{
    /** @var array<int, bool> company id => whether details are allowed, for one run */
    private array $details = [];

    /** Days after which a Microsoft delta read starts again from the start (its date window is fixed at the first read). */
    public const int WINDOW_RESTART_DAYS = 30;

    public function __construct(private readonly CalendarProviders $providers) {}

    public function provider(CalendarConnection $connection): CalendarProvider
    {
        return $this->providers->for($connection->provider);
    }

    /** Writes or removes one activity in the connection's calendar. */
    public function syncActivity(CalendarConnection $connection, ?Activity $activity, int $activityId): void
    {
        if (! $connection->isActive()) {
            return;
        }

        $mapping = CalendarEvent::query()->where('connection_id', $connection->id)->where('activity_id', $activityId)->first();
        $belongs = $activity instanceof Activity && $activity->assigned_to === $connection->user_id && CalendarEntries::allows($activity);

        if (! $belongs) {
            if ($mapping instanceof CalendarEvent) {
                $this->remove($connection, $mapping);
            }

            return;
        }

        if ($mapping instanceof CalendarEvent && $mapping->synced_sequence >= $activity->calendar_sequence) {
            return;
        }

        $this->push($connection, $activity, $mapping);
    }

    /**
     * Writes every activity that belongs in the calendar and is behind, and
     * removes what no longer belongs. Returns how many were written.
     */
    public function pushAll(CalendarConnection $connection, bool $force = false): int
    {
        if (! $connection->isActive()) {
            return 0;
        }

        $user = User::query()->find($connection->user_id);

        if (! $user instanceof User) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $activities = CalendarEntries::eligible(Activity::query())
            ->where('assigned_to', $connection->user_id)
            ->whereBetween('due_at', [$now->subDays(CalendarEntries::DAYS_BACK), $now->addDays(CalendarEntries::DAYS_AHEAD)])
            ->with(CalendarEntries::relations())
            ->orderBy('due_at')
            ->limit(2000)
            ->get()
            ->keyBy('id');
        $mappings = CalendarEvent::query()->where('connection_id', $connection->id)->get()->keyBy('activity_id');
        $written = 0;

        foreach ($activities as $activity) {
            $mapping = $mappings->get($activity->id);

            $behind = ! $mapping instanceof CalendarEvent || $mapping->synced_sequence < $activity->calendar_sequence;
            // A deal's last step cancelled in the calendar is written back only after it changes in NONOX.
            $cancelled = $mapping instanceof CalendarEvent && $mapping->cancelled_remotely_at !== null;

            if ($behind || ($force && ! $cancelled)) {
                $this->push($connection, $activity, $mapping);
                $written++;
            }
        }

        foreach ($mappings as $activityId => $mapping) {
            if (! $activities->has($activityId)) {
                $this->remove($connection, $mapping);
            }
        }

        return $written;
    }

    /** Reads the calendar's changes to NONOX's events. Returns how many activities changed. */
    public function pull(CalendarConnection $connection): int
    {
        if (! $connection->isActive()) {
            return 0;
        }

        $provider = $this->provider($connection);
        $cursor = $connection->sync_cursor;

        // Microsoft's delta keeps the date window of its first read (30 days back, a year ahead): start it again monthly.
        // TODO(verify-at-registration): calendarView/delta on a named calendar in Graph v1.0, and its window.
        if ($connection->provider === CalendarProviderKey::Microsoft
            && ($connection->cursor_started_at === null || $connection->cursor_started_at->lessThan(CarbonImmutable::now()->subDays(self::WINDOW_RESTART_DAYS)))) {
            $cursor = null;
        }

        try {
            $changes = $this->call($connection, fn (string $token) => $provider->changes($token, (string) $connection->calendar_id, $cursor));
        } catch (CalendarProviderException $exception) {
            if (! $exception->resync) {
                throw $exception;
            }

            // The cursor expired: read from the start. NONOX's own events are found again through the event map.
            $cursor = null;
            $changes = $this->call($connection, fn (string $token) => $provider->changes($token, (string) $connection->calendar_id, null));
        }

        if ($cursor === null) {
            $connection->cursor_started_at = CarbonImmutable::now();
        }

        $applied = 0;
        $mappings = CalendarEvent::query()
            ->where('connection_id', $connection->id)
            ->whereIn('external_id', array_map(fn (RemoteChange $change): string => $change->externalId, $changes->changes))
            ->get()
            ->keyBy('external_id');

        foreach ($changes->changes as $change) {
            $mapping = $mappings->get($change->externalId);

            // Not an event NONOX wrote: never read further, never stored.
            if (! $mapping instanceof CalendarEvent) {
                continue;
            }

            $applied += $this->apply($connection, $mapping, $change) ? 1 : 0;
        }

        $connection->forceFill(['sync_cursor' => $changes->cursor, 'last_synced_at' => CarbonImmutable::now(), 'last_error' => null])->save();

        return $applied;
    }

    /** Removes every event NONOX wrote to the calendar (disconnecting). Best effort. */
    public function removeAll(CalendarConnection $connection): void
    {
        foreach (CalendarEvent::query()->where('connection_id', $connection->id)->get() as $mapping) {
            try {
                $this->remove($connection, $mapping);
            } catch (CalendarProviderException) {
                // The grant may already be gone: nothing more can be removed.
                return;
            }
        }
    }

    /**
     * Runs a provider call with a fresh access token. A refused token is
     * refreshed once; a refused refresh puts the connection in ERROR.
     *
     * @template T
     *
     * @param  Closure(string): T  $call
     * @return T
     */
    public function call(CalendarConnection $connection, Closure $call): mixed
    {
        $provider = $this->provider($connection);

        if ($connection->token_expires_at !== null && $connection->token_expires_at->lessThan(CarbonImmutable::now()->addMinutes(2))) {
            $this->refresh($connection, $provider);
        }

        try {
            return $call($connection->access_token);
        } catch (CalendarProviderException $exception) {
            if (! $exception->reconnect) {
                throw $exception;
            }

            $this->refresh($connection, $provider);

            return $call($connection->access_token);
        }
    }

    private function refresh(CalendarConnection $connection, CalendarProvider $provider): void
    {
        if ($connection->refresh_token === null) {
            $this->fail($connection);
        }

        try {
            $tokens = $provider->refresh((string) $connection->refresh_token);
        } catch (CalendarProviderException $exception) {
            if ($exception->reconnect) {
                $this->fail($connection);
            }

            throw $exception;
        }

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
        ])->save();
    }

    private function fail(CalendarConnection $connection): never
    {
        $connection->forceFill(['status' => CalendarConnection::ERROR, 'last_error' => 'reconnect'])->save();
        $company = Company::query()->find($connection->company_id);
        Audit::record('crm.calendar_connection_failed', $connection, ['provider' => $connection->provider->value], $company instanceof Company ? $company : null);

        throw new CalendarProviderException('The calendar grant is gone; the user must connect again.', reconnect: true);
    }

    private function push(CalendarConnection $connection, Activity $activity, ?CalendarEvent $mapping): void
    {
        $entry = CalendarEntries::entry($activity, $connection->include_details && $this->detailsAllowed($connection->company_id));
        $provider = $this->provider($connection);
        $externalId = $this->call($connection, fn (string $token): string => $provider->upsert($token, (string) $connection->calendar_id, $entry, $mapping?->external_id));

        if ($mapping instanceof CalendarEvent) {
            $mapping->forceFill(['external_id' => $externalId, 'synced_sequence' => $activity->calendar_sequence, 'synced_at' => CarbonImmutable::now(), 'cancelled_remotely_at' => null])->save();

            return;
        }

        CalendarEvent::create([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'activity_id' => $activity->id,
            'external_id' => $externalId,
            'synced_sequence' => $activity->calendar_sequence,
            'synced_at' => CarbonImmutable::now(),
        ]);
    }

    private function detailsAllowed(int $companyId): bool
    {
        if (! array_key_exists($companyId, $this->details)) {
            $company = Company::query()->find($companyId);
            $this->details[$companyId] = $company instanceof Company && CalendarRules::detailsAllowed($company);
        }

        return $this->details[$companyId];
    }

    private function remove(CalendarConnection $connection, CalendarEvent $mapping): void
    {
        $provider = $this->provider($connection);
        $this->call($connection, function (string $token) use ($provider, $connection, $mapping): void {
            $provider->delete($token, (string) $connection->calendar_id, $mapping->external_id);
        });
        $mapping->delete();
    }

    /** One change to an event NONOX wrote. Returns whether the activity changed. */
    private function apply(CalendarConnection $connection, CalendarEvent $mapping, RemoteChange $change): bool
    {
        return DB::transaction(function () use ($connection, $mapping, $change): bool {
            $activity = Activity::query()->whereKey($mapping->activity_id)->lockForUpdate()->first();
            $company = Company::query()->find($connection->company_id);
            $user = User::query()->find($connection->user_id);

            if (! $activity instanceof Activity || ! $activity->isOpen()) {
                $mapping->delete();

                return false;
            }

            // Changed in NONOX since it was last written: NONOX wins, and the next push writes it again.
            if ($activity->calendar_sequence > $mapping->synced_sequence) {
                return false;
            }

            // Already cancelled in the calendar and kept open in NONOX: nothing more to read until it changes in NONOX.
            if ($mapping->cancelled_remotely_at !== null) {
                return false;
            }

            if ($change->cancelled) {
                if (DealNextStep::isLastStep($activity)) {
                    // The deal keeps its next step; the map remembers the cancellation, so the event is not written back.
                    $mapping->forceFill(['cancelled_remotely_at' => CarbonImmutable::now(), 'synced_sequence' => $activity->calendar_sequence])->save();
                    Audit::record('crm.calendar_cancel_kept', $activity, ['provider' => $connection->provider->value, 'deal_id' => $activity->deal_id], $company, $user);

                    return false;
                }

                $mapping->delete();

                $activity->forceFill([
                    'done_at' => CarbonImmutable::now(),
                    'body' => trim(($activity->body === null ? '' : $activity->body."\n\n").__('Inställd i kalendern.')),
                ])->save();
                Audit::record('crm.calendar_cancelled', $activity, ['provider' => $connection->provider->value, 'type' => $activity->type->value], $company, $user);

                return true;
            }

            if ($change->start === null || $activity->due_at === null) {
                return false;
            }

            $minutes = $change->end === null ? null : (int) max(5, min(1440, $change->start->diffInMinutes($change->end)));
            $currentMinutes = $activity->duration_minutes ?? CalendarEntries::defaultMinutes($activity->type);

            if ($change->start->equalTo($activity->due_at) && ($minutes === null || $minutes === $currentMinutes)) {
                return false;
            }

            $activity->forceFill([
                'due_at' => $change->start,
                'duration_minutes' => $minutes ?? $activity->duration_minutes,
            ])->save();
            // The calendar already has this version: nothing to write back.
            $mapping->forceFill(['synced_sequence' => $activity->calendar_sequence, 'synced_at' => CarbonImmutable::now()])->save();
            Audit::record('crm.calendar_moved', $activity, ['provider' => $connection->provider->value, 'type' => $activity->type->value], $company, $user);

            return true;
        });
    }
}
