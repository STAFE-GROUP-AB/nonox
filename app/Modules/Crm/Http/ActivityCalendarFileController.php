<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\User;
use App\Modules\Crm\Calendar\CalendarDocument;
use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * "Lägg till i kalendern" (ADR-049): one planned call, meeting or task as an
 * .ics file for the signed-in user's own calendar program. Another
 * company's activity, a done one and one that may not be in a calendar
 * (restricted processing) answer 404. The file carries the details: the
 * user downloads it on purpose, signed in; a person marked Kontakta inte
 * is still not named.
 */
final class ActivityCalendarFileController
{
    public function __invoke(Request $request, int $activity): Response
    {
        /** @var User $user */
        $user = $request->user();
        $record = Activity::query()->with(CalendarEntries::relations())->find($activity);

        abort_unless($record instanceof Activity && $user->can('view', $record) && CalendarEntries::allows($record), 404);

        $date = $record->due_at?->setTimezone('Europe/Stockholm')->format('Y-m-d');

        return response(CalendarDocument::single(CalendarEntries::entry($record, true)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($record->type->label()).'-'.$date.'.ics"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
