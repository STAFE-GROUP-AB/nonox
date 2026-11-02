<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\TimeRounding;

/**
 * How overtime and mertid are measured for an employment (A1, A2; enligt
 * AI-utredning 2026-11-02, juristgranskning återstår):
 *
 * - PERIOD: the worked time of the calculation period against its
 *   contracted time; leave on scheduled ordinary time counts as worked
 *   (arbetstidslagen 7 § andra stycket, 10 §). Used without an agreement
 *   and where the agreement averages ordinary time over a period (Gröna
 *   Riks § 9, Detaljhandeln § 6.1).
 * - DAILY: each day's time beyond that day's ordinary (scheduled) time,
 *   before and after added together, never netted against shorter days
 *   (Visita–Unionen § 11.1, Handelns tjänstemannaavtal § 4.2).
 *
 * The agreement's rounding is applied per day (Visita–Unionen up per
 * started half hour, Handelns tjänstemannaavtal down to whole half hours)
 * or per occasion with the minutes of an extension that are not overtime
 * (Gröna Riks § 12 p. 1.2 anm.: at most 10 minutes is not overtime, a part
 * of a half hour is paid as a whole), never on the period total. Pure.
 *
 * TODO(legal-verify): whether a late start offsets a later finish on the
 * same day (NONOX counts at most the day's net excess as overtime), the
 * minimum of three hours for overtime not adjoining ordinary time, and the
 * order or approval the agreements require (attesting the day is taken as
 * the approval afterwards).
 */
final readonly class OvertimeTerms
{
    public const string PERIOD = 'PERIOD';

    public const string DAILY = 'DAILY';

    public const string PER_DAY = 'DAY';

    public const string PER_OCCASION = 'OCCASION';

    /**
     * @param  ?array{unit_minutes: int, method: string, source: string}  $rounding
     */
    public function __construct(
        public string $method = self::PERIOD,
        public string $methodSource = '',
        public ?array $rounding = null,
        public string $per = self::PER_DAY,
        public int $thresholdMinutes = 0,
    ) {}

    public static function from(?AgreementRules $agreement): self
    {
        if ($agreement === null) {
            return new self;
        }

        $method = $agreement->overtimeMethod();
        $rounding = $agreement->overtimeRounding();

        return new self(
            $method['method'],
            $method['source'],
            $rounding === null ? null : ['unit_minutes' => $rounding['unit_minutes'], 'method' => $rounding['method'], 'source' => $rounding['source']],
            $rounding['per'] ?? self::PER_DAY,
            $rounding['threshold_minutes'] ?? 0,
        );
    }

    /**
     * A day's time beyond its ordinary time: the raw minutes (the day's net
     * excess over the plan), what counts after the agreement's threshold and
     * rounding, and the minutes left out by the threshold.
     *
     * @return array{raw: int, counted: int, ignored: int}
     */
    public function day(DayComparison $day): array
    {
        $raw = max(0, $day->actualMinutes() - $day->plannedMinutes());

        if ($raw === 0) {
            return ['raw' => 0, 'counted' => 0, 'ignored' => 0];
        }

        if ($this->per !== self::PER_OCCASION) {
            return ['raw' => $raw, 'counted' => TimeRounding::round($raw, $this->rounding), 'ignored' => 0];
        }

        $remaining = $raw;
        $counted = 0;
        $ignored = 0;
        $occasions = self::occasions($day);
        $occasions[] = PHP_INT_MAX; // whatever is left (a shorter break, say) is one more occasion

        foreach ($occasions as $occasion) {
            if ($remaining <= 0) {
                break;
            }

            $part = min($occasion, $remaining);
            $remaining -= $part;

            if ($part <= $this->thresholdMinutes) {
                $ignored += $part;

                continue;
            }

            $counted += TimeRounding::round($part, $this->rounding);
        }

        return ['raw' => $raw, 'counted' => $counted, 'ignored' => $ignored];
    }

    /**
     * The stretches of worked time outside the planned intervals, in
     * minutes, in time order.
     *
     * @return list<int>
     */
    public static function occasions(DayComparison $day): array
    {
        $planned = [];

        foreach ($day->planned as $interval) {
            if ($interval->isComplete()) {
                $planned[] = [(int) $interval->start?->getTimestamp(), (int) $interval->end?->getTimestamp()];
            }
        }

        usort($planned, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $actual = array_values(array_filter($day->actual, fn (WorkInterval $interval): bool => $interval->isComplete()));
        usort($actual, fn (WorkInterval $a, WorkInterval $b): int => $a->start <=> $b->start);
        $occasions = [];

        foreach ($actual as $interval) {
            $segments = [[(int) $interval->start?->getTimestamp(), (int) $interval->end?->getTimestamp()]];

            foreach ($planned as [$from, $to]) {
                $next = [];

                foreach ($segments as [$start, $end]) {
                    if ($to <= $start || $from >= $end) {
                        $next[] = [$start, $end];

                        continue;
                    }

                    if ($from > $start) {
                        $next[] = [$start, $from];
                    }

                    if ($to < $end) {
                        $next[] = [$to, $end];
                    }
                }

                $segments = $next;
            }

            foreach ($segments as [$start, $end]) {
                $minutes = intdiv($end - $start, 60);

                if ($minutes > 0) {
                    $occasions[] = $minutes;
                }
            }
        }

        return $occasions;
    }
}
