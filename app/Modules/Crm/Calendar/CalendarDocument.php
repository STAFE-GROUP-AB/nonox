<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Support\Calendar\Ics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CRM activities as iCalendar files (RFC 5545, ADR-049): the subscription
 * feed, one downloaded event, and a meeting invitation (RFC 5546
 * METHOD:REQUEST). Times are local Stockholm times with the zone's
 * VTIMEZONE; the UID is stable per activity and SEQUENCE and LAST-MODIFIED
 * follow the activity, so a calendar replaces its copy when it changes.
 * Pure.
 */
final class CalendarDocument
{
    public const string PRODID = '-//NONOX//CRM//SV';

    /** @param Collection<int, CalendarEntry> $entries */
    public static function feed(string $name, Collection $entries, ?CarbonImmutable $now = null): string
    {
        $lines = [
            ...self::header('PUBLISH'),
            'X-WR-CALNAME:'.Ics::text($name),
            'X-WR-TIMEZONE:'.Ics::ZONE,
            // How often a subscribing calendar should fetch it again (RFC 7986 and the de facto X- property).
            'REFRESH-INTERVAL;VALUE=DURATION:PT30M',
            'X-PUBLISHED-TTL:PT30M',
            ...Ics::timezone(),
        ];

        foreach ($entries as $entry) {
            array_push($lines, ...self::event($entry, $now));
        }

        $lines[] = 'END:VCALENDAR';

        return Ics::join($lines);
    }

    /** One event to open in a calendar program ("Lägg till i kalendern"). */
    public static function single(CalendarEntry $entry, ?CarbonImmutable $now = null): string
    {
        return Ics::join([...self::header('PUBLISH'), ...Ics::timezone(), ...self::event($entry, $now), 'END:VCALENDAR']);
    }

    /**
     * A meeting invitation from the user (ORGANIZER) to the CRM person
     * (ATTENDEE). Sent again with a higher SEQUENCE when the meeting moves,
     * which replaces the copy in the receiver's calendar.
     */
    public static function invitation(CalendarEntry $entry, string $organizerName, string $organizerEmail, string $attendeeName, string $attendeeEmail, ?CarbonImmutable $now = null): string
    {
        $event = self::event($entry, $now);
        // ORGANIZER and ATTENDEE go before END:VEVENT.
        array_splice($event, -1, 0, [
            'ORGANIZER;CN='.Ics::param($organizerName).':'.Ics::mailto($organizerEmail),
            'ATTENDEE;CN='.Ics::param($attendeeName).';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:'.Ics::mailto($attendeeEmail),
            'STATUS:CONFIRMED',
        ]);

        return Ics::join([...self::header('REQUEST'), ...Ics::timezone(), ...$event, 'END:VCALENDAR']);
    }

    /** @return list<string> */
    private static function header(string $method): array
    {
        return ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.self::PRODID, 'CALSCALE:GREGORIAN', 'METHOD:'.$method];
    }

    /** @return list<string> */
    private static function event(CalendarEntry $entry, ?CarbonImmutable $now): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$entry->uid,
            'SEQUENCE:'.$entry->sequence,
            'DTSTAMP:'.Ics::utc($now ?? CarbonImmutable::now()),
            'LAST-MODIFIED:'.Ics::utc($entry->lastModified),
            Ics::local('DTSTART', $entry->start),
            Ics::local('DTEND', $entry->end),
            'SUMMARY:'.Ics::text($entry->summary),
        ];

        if ($entry->description !== null && $entry->description !== '') {
            $lines[] = 'DESCRIPTION:'.Ics::text($entry->description);
        }

        if ($entry->location !== null && $entry->location !== '') {
            $lines[] = 'LOCATION:'.Ics::text($entry->location);
        }

        if ($entry->url !== null) {
            $lines[] = 'URL:'.str_replace(["\r", "\n"], '', $entry->url);
        }

        // Busy in the user's own calendar, private if the calendar is shared.
        $lines[] = 'CLASS:PRIVATE';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';

        return $lines;
    }
}
