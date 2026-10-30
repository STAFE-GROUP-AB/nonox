<?php

declare(strict_types=1);

namespace App\Domain\Accruals;

use Carbon\CarbonImmutable;

/**
 * An amount spread evenly over months from a first month. For each month the
 * plan says how much should have been released by its end; booking the
 * difference to what has been released keeps the rounding exact and lets a
 * missed month be caught up.
 */
final readonly class AccrualPlan
{
    public function __construct(
        public int $totalOre,
        public int $months,
        public string $startMonth,
    ) {}

    /** What should have been released by the end of the month (YYYY-MM). */
    public function releasedBy(string $month): int
    {
        $index = self::monthsBetween($this->startMonth, $month);

        if ($index < 0 || $this->months <= 0) {
            return 0;
        }

        return intdiv($this->totalOre * min($index + 1, $this->months), $this->months);
    }

    public function lastMonth(): string
    {
        return CarbonImmutable::parse("{$this->startMonth}-01")->addMonthsNoOverflow(max(0, $this->months - 1))->format('Y-m');
    }

    /** @return list<array{month: string, amount: int}> */
    public function schedule(): array
    {
        $rows = [];
        $before = 0;

        for ($i = 0; $i < $this->months; $i++) {
            $month = CarbonImmutable::parse("{$this->startMonth}-01")->addMonthsNoOverflow($i)->format('Y-m');
            $after = $this->releasedBy($month);
            $rows[] = ['month' => $month, 'amount' => $after - $before];
            $before = $after;
        }

        return $rows;
    }

    public static function monthsBetween(string $from, string $to): int
    {
        [$fromYear, $fromMonth] = array_map('intval', explode('-', $from));
        [$toYear, $toMonth] = array_map('intval', explode('-', $to));

        return ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);
    }
}
