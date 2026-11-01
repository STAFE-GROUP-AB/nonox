<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Carbon\CarbonImmutable;

/** How often a recurring task comes back. */
enum TaskFrequency: string
{
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Yearly = 'YEARLY';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Varje månad'),
            self::Quarterly => __('Varje kvartal'),
            self::Yearly => __('Varje år'),
        };
    }

    /**
     * The period a day belongs to, as the first month of the period (YYYY-MM):
     * the month, the quarter's first month or January.
     */
    public function periodOf(CarbonImmutable $day): string
    {
        return match ($this) {
            self::Monthly => $day->format('Y-m'),
            self::Quarterly => $day->firstOfQuarter()->format('Y-m'),
            self::Yearly => $day->format('Y').'-01',
        };
    }

    /** The last day of the period that starts with the given month. */
    public function periodEnd(string $period): CarbonImmutable
    {
        $start = CarbonImmutable::parse("{$period}-01");

        return match ($this) {
            self::Monthly => $start->endOfMonth()->startOfDay(),
            self::Quarterly => $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay(),
            self::Yearly => $start->endOfYear()->startOfDay(),
        };
    }

    /**
     * The due date: the given day of the month after the period, clamped to
     * the length of that month.
     */
    public function dueDate(string $period, int $day): CarbonImmutable
    {
        $month = $this->periodEnd($period)->addDay()->startOfMonth();

        return $month->setDay(min(max($day, 1), $month->daysInMonth));
    }

    /** A readable name of the period, for the generated task's title. */
    public function periodLabel(string $period): string
    {
        $start = CarbonImmutable::parse("{$period}-01");

        return match ($this) {
            self::Monthly => $start->translatedFormat('F Y'),
            self::Quarterly => __('kvartal :quarter :year', ['quarter' => $start->quarter, 'year' => $start->year]),
            self::Yearly => (string) $start->year,
        };
    }
}
