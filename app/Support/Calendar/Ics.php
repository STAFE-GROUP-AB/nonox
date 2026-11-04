<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * The building blocks of an iCalendar file (RFC 5545): TEXT escaping
 * (3.3.11), line folding at 75 octets without splitting a UTF-8 character
 * (3.1), local times in Europe/Stockholm with the zone's own VTIMEZONE
 * (3.6.5), and UTC stamps. Pure; the CRM's calendar (ADR-049) uses it, the
 * same rules as Planering's schedule link (ADR-037).
 */
final class Ics
{
    public const string ZONE = 'Europe/Stockholm';

    /** Escapes a TEXT value: backslash, semicolon, comma and line breaks. */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Control characters other than the line break are not allowed in TEXT.
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $value);
    }

    /** Folds a line longer than 75 octets, without splitting a UTF-8 character. */
    public static function fold(string $line): string
    {
        $folded = '';
        $current = '';
        // A continuation line starts with a space, which counts towards its 75 octets.
        $limit = 75;

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = 74;
            }

            $current .= $character;
        }

        return $folded.$current;
    }

    /**
     * The lines joined with CRLF and folded, ending with CRLF.
     *
     * @param  list<string>  $lines
     */
    public static function join(array $lines): string
    {
        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** A UTC DATE-TIME: 20261105T080000Z. */
    public static function utc(CarbonImmutable $time): string
    {
        return $time->utc()->format('Ymd\THis\Z');
    }

    /** A local DATE-TIME property in Stockholm time: DTSTART;TZID=Europe/Stockholm:20261105T090000. */
    public static function local(string $name, CarbonImmutable $time): string
    {
        return $name.';TZID='.self::ZONE.':'.$time->setTimezone(self::ZONE)->format('Ymd\THis');
    }

    /**
     * Europe/Stockholm: CET (+01:00), and CEST (+02:00) from the last Sunday
     * of March at 02:00 to the last Sunday of October at 03:00 (the EU rule
     * since 1996).
     *
     * @return list<string>
     */
    public static function timezone(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.self::ZONE,
            'X-LIC-LOCATION:'.self::ZONE,
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /** A mailto: address for ORGANIZER and ATTENDEE, with characters that would end the value removed. */
    public static function mailto(string $email): string
    {
        return 'mailto:'.str_replace([';', ':', ',', '"', "\r", "\n"], '', $email);
    }

    /** A parameter value (CN=…): quoted, without the characters a quoted value cannot hold. */
    public static function param(string $value): string
    {
        return '"'.str_replace(['"', "\r", "\n"], ['', ' ', ' '], $value).'"';
    }
}
