<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * An employee's published shifts as an iCalendar file (RFC 5545): one event
 * per shift, times in UTC, the area and the planner's note. It carries the
 * company's name and the shifts, never a personal number. Pure.
 */
final class IcalFeed
{
    /**
     * @param  Collection<int, PublishedShift>  $shifts
     * @param  Collection<int|string, mixed>  $areas  area id => name
     */
    public static function render(string $company, Collection $shifts, Collection $areas, string $host): string
    {
        $now = CarbonImmutable::now()->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NONOX//Planering//SV',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text(__('Mina pass – :company', ['company' => $company])),
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($shifts as $shift) {
            $area = $shift->areaId === null ? null : $areas->get($shift->areaId);
            $summary = is_string($area) && $area !== '' ? __('Pass · :area', ['area' => $area]) : __('Pass');
            $details = [];

            if ($shift->breakMinutes > 0) {
                $details[] = __('Rast :minutes min', ['minutes' => $shift->breakMinutes]);
            }

            if (is_string($shift->note) && $shift->note !== '') {
                $details[] = $shift->note;
            }

            $description = implode("\n", $details);

            array_push($lines,
                'BEGIN:VEVENT',
                "UID:{$shift->id}@{$host}",
                "DTSTAMP:{$now}",
                'DTSTART:'.$shift->start->utc()->format('Ymd\THis\Z'),
                'DTEND:'.$shift->end->utc()->format('Ymd\THis\Z'),
                'SUMMARY:'.self::text("{$summary} ({$company})"),
            );

            if ($description !== '') {
                $lines[] = 'DESCRIPTION:'.self::text($description);
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** Escapes a TEXT value: backslash, semicolon, comma and line breaks. */
    public static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Folds a line longer than 75 octets, without splitting a UTF-8 character. */
    public static function fold(string $line): string
    {
        $folded = '';
        $current = '';

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > 75) {
                $folded .= $current."\r\n ";
                $current = '';
            }

            $current .= $character;
        }

        return $folded.$current;
    }
}
