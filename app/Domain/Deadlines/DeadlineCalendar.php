<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Support\Calendar\Ics;
use Carbon\CarbonImmutable;

/**
 * Mina datum as an iCalendar feed (RFC 5545, ADR-051 stage 2): one all-day
 * event per deadline, with a UID that stays the same for the same deadline
 * in the same company, and alarms 7 days and 1 day before the start of the
 * day. No personal data beyond the company's name in the calendar's title.
 * Pure.
 */
final class DeadlineCalendar
{
    public const string PRODID = '-//NONOX//Mina datum//SV';

    /** @var list<int> days before the deadline the calendar reminds */
    public const array ALARM_DAYS = [7, 1];

    /** @param  list<Deadline>  $deadlines */
    public static function feed(string $name, int $companyId, array $deadlines, string $host, ?CarbonImmutable $now = null): string
    {
        $stamp = Ics::utc($now ?? CarbonImmutable::now());
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.Ics::text($name),
            'X-WR-TIMEZONE:'.Ics::ZONE,
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
            'X-PUBLISHED-TTL:PT12H',
        ];

        foreach ($deadlines as $deadline) {
            $description = $deadline->appliesBecause;

            if ($deadline->isRecommendation) {
                $description .= ' '.__('Det här är en rekommendation, inte ett datum i lagen.');
            }

            if ($deadline->legalVerify) {
                $description .= ' '.__('Kontrollera datumet hos :authority.', ['authority' => $deadline->checkWith()]);
            }

            array_push($lines,
                'BEGIN:VEVENT',
                'UID:'.self::uid($companyId, $deadline, $host),
                'DTSTAMP:'.$stamp,
                'DTSTART;VALUE=DATE:'.$deadline->date->format('Ymd'),
                'DTEND;VALUE=DATE:'.$deadline->date->addDay()->format('Ymd'),
                'SUMMARY:'.Ics::text($deadline->summary()),
                'DESCRIPTION:'.Ics::text(trim($description)),
                'CATEGORIES:'.Ics::text(DeadlineCategory::of($deadline)->label()),
                'CLASS:PRIVATE',
                'TRANSP:TRANSPARENT',
            );

            foreach (self::ALARM_DAYS as $days) {
                array_push($lines,
                    'BEGIN:VALARM',
                    'ACTION:DISPLAY',
                    'DESCRIPTION:'.Ics::text($deadline->summary()),
                    "TRIGGER:-P{$days}D",
                    'END:VALARM',
                );
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return Ics::join($lines);
    }

    /** Stable for the same deadline in the same company, whatever the date moves to. */
    public static function uid(int $companyId, Deadline $deadline, string $host): string
    {
        return 'deadline-'.$companyId.'-'.str_replace(':', '-', $deadline->key).'@'.$host;
    }
}
