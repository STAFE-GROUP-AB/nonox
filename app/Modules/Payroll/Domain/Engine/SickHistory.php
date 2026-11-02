<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use Carbon\CarbonImmutable;

/**
 * The sick history before a payslip, read from PayrollContext::$sickHistory:
 *
 * - spells: approved sick days before the run as calendar-day ranges. They
 *   are linked into sjuklöneperioder: a spell starting within the
 *   reinfection window after the previous one ended continues it
 *   (sjuklönelagen 7 § tredje stycket), so the day numbering follows the
 *   whole chain, not only the last absence record.
 * - periods: per sjuklöneperiod (by its first day) the karensavdrag it is
 *   due and how much approved payslips have deducted (sick_pay_periods).
 * - long_sick_dates: days paid under an agreement's deduction from day 15.
 *
 * An occasion with a karensavdrag (6 § tredje stycket) is a period in which
 * a karensavdrag was actually made, counted once also when it was taken
 * over several days, runs or a reinfection (prop. 2017/18:96 s. 69). A
 * period found in the absences that no approved payslip has recorded (sick
 * leave from before payroll was run here, or a run not yet approved) is
 * assumed to have had one.
 *
 * The older keys last_sick_period_end, last_sick_period_days and
 * karens_count_12m are still read when spells are missing.
 */
final readonly class SickHistory
{
    /**
     * @param  ?array{start: CarbonImmutable, end: CarbonImmutable, days: int}  $last  the last sjuklöneperiod before the run
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, days: int}>  $chains
     * @param  array<string, array{full: int, deducted: int}>  $periods  by first day
     * @param  list<string>  $longSickDates
     */
    private function __construct(
        public ?array $last,
        public array $chains,
        public array $periods,
        public array $longSickDates,
        public ?int $legacyCount,
    ) {}

    /** @param  array<string, mixed>  $history */
    public static function from(array $history, int $window): self
    {
        $periods = [];

        foreach ((array) ($history['periods'] ?? []) as $start => $period) {
            $period = (array) $period;
            $periods[(string) $start] = ['full' => (int) ($period['full'] ?? 0), 'deducted' => (int) ($period['deducted'] ?? 0)];
        }

        $longSickDates = array_values(array_map('strval', (array) ($history['long_sick_dates'] ?? [])));

        if (! isset($history['spells'])) {
            $last = null;

            if (isset($history['last_sick_period_end'])) {
                $end = CarbonImmutable::parse((string) $history['last_sick_period_end']);
                $days = (int) ($history['last_sick_period_days'] ?? 1);
                $last = ['start' => $end->subDays(max(0, $days - 1)), 'end' => $end, 'days' => $days];
            }

            return new self($last, [], $periods, $longSickDates, (int) ($history['karens_count_12m'] ?? 0));
        }

        $chains = self::chains((array) $history['spells'], $window);

        return new self($chains === [] ? null : $chains[count($chains) - 1], $chains, $periods, $longSickDates, null);
    }

    /**
     * The first day of the sjuklöneperiod a sick day starting a spell
     * belongs to: the previous period's when the day is within the
     * reinfection window of its end.
     */
    public function periodStartFor(CarbonImmutable $day, int $window): CarbonImmutable
    {
        if ($this->last !== null && (int) $this->last['end']->diffInDays($day) <= $window && $day->greaterThan($this->last['end'])) {
            return $this->last['start'];
        }

        return $day;
    }

    /**
     * Occasions with a karensavdrag in the twelve months back from a new
     * sjuklöneperiod's first day.
     *
     * @param  list<string>  $inRun  first days of earlier periods in this payslip that got a karensavdrag
     */
    public function occasionsBefore(CarbonImmutable $start, array $inRun): int
    {
        $from = $start->subYear();
        $in = fn (CarbonImmutable $day): bool => $day->greaterThanOrEqualTo($from) && $day->lessThan($start);

        if ($this->legacyCount !== null) {
            return $this->legacyCount + count(array_filter($inRun, fn (string $day): bool => $in(CarbonImmutable::parse($day))));
        }

        $dates = [];

        foreach ($this->periods as $day => $period) {
            if ($period['deducted'] > 0) {
                $dates[$day] = true;
            }
        }

        foreach ($this->chains as $chain) {
            if (! isset($this->periods[$chain['start']->toDateString()])) {
                $dates[$chain['start']->toDateString()] = true;
            }
        }

        foreach ($inRun as $day) {
            $dates[$day] = true;
        }

        return count(array_filter(array_keys($dates), fn (string $day): bool => $in(CarbonImmutable::parse($day))));
    }

    /** Whether an approved payslip has recorded the sjuklöneperiod starting on the day. */
    public function recorded(CarbonImmutable $start): bool
    {
        return isset($this->periods[$start->toDateString()]);
    }

    /** The part of a recorded period's karensavdrag that is not yet deducted. */
    public function karensRemaining(CarbonImmutable $start): int
    {
        $period = $this->periods[$start->toDateString()] ?? null;

        return $period === null ? 0 : max(0, $period['full'] - $period['deducted']);
    }

    public function karensFull(CarbonImmutable $start): int
    {
        return $this->periods[$start->toDateString()]['full'] ?? 0;
    }

    public function karensDeducted(CarbonImmutable $start): int
    {
        return $this->periods[$start->toDateString()]['deducted'] ?? 0;
    }

    /**
     * Spells linked into sjuklöneperioder.
     *
     * @param  array<mixed>  $spells  {from, to} date strings
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, days: int}>
     */
    private static function chains(array $spells, int $window): array
    {
        $ranges = [];

        foreach ($spells as $spell) {
            $spell = (array) $spell;
            $from = CarbonImmutable::parse((string) $spell['from'])->startOfDay();
            $to = CarbonImmutable::parse((string) $spell['to'])->startOfDay();

            if ($to->lessThan($from)) {
                continue;
            }

            $ranges[] = [$from, $to];
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // Overlapping or adjacent records are one spell of consecutive days.
        $merged = [];

        foreach ($ranges as [$from, $to]) {
            $last = array_key_last($merged);

            if ($last !== null && (int) $merged[$last][1]->diffInDays($from, false) <= 1) {
                $merged[$last][1] = $merged[$last][1]->max($to);

                continue;
            }

            $merged[] = [$from, $to];
        }

        $chains = [];

        foreach ($merged as [$from, $to]) {
            $length = (int) $from->diffInDays($to) + 1;
            $last = array_key_last($chains);

            if ($last !== null && (int) $chains[$last]['end']->diffInDays($from) <= $window) {
                $chains[$last]['end'] = $to;
                $chains[$last]['days'] += $length;

                continue;
            }

            $chains[] = ['start' => $from, 'end' => $to, 'days' => $length];
        }

        return $chains;
    }
}
