<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Modules\Crm\Calendar\Jobs\SyncCalendarActivity;
use App\Modules\Crm\Models\Activity;

/**
 * Keeps calendars in step with CRM activities (ADR-049). Every change a
 * calendar must see raises the activity's calendar_sequence (iCalendar
 * SEQUENCE), so subscribed calendars, a re-sent invitation and connected
 * calendars replace their copy. While a calendar provider is configured, a
 * saved activity is queued for its assignee's (and former assignee's)
 * connected calendar after the transaction commits; without one, nothing
 * is queued.
 */
final class ActivityCalendarObserver
{
    /** The fields a calendar shows or decides by. */
    public const array FIELDS = ['type', 'subject', 'body', 'location', 'due_at', 'duration_minutes', 'done_at', 'account_id', 'person_id', 'deal_id', 'assigned_to'];

    public function updating(Activity $activity): void
    {
        if ($activity->isDirty(self::FIELDS) && ! $activity->isDirty('calendar_sequence')) {
            $activity->calendar_sequence = $activity->calendar_sequence + 1;
        }
    }

    public function saved(Activity $activity): void
    {
        if (! app(CalendarProviders::class)->anyConfigured()) {
            return;
        }

        if (! $activity->wasRecentlyCreated && ! $activity->wasChanged([...self::FIELDS, 'calendar_sequence'])) {
            return;
        }

        $users = array_values(array_unique(array_filter([
            $activity->assigned_to,
            $activity->wasChanged('assigned_to') ? $activity->getOriginal('assigned_to') : null,
        ], fn (mixed $id): bool => is_int($id) || is_numeric($id))));

        if ($users === []) {
            return;
        }

        SyncCalendarActivity::dispatch($activity->company_id, $activity->id, array_map(intval(...), $users))->afterCommit();
    }
}
