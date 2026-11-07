<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The size figures of one fiscal year (S1-R9.2): the average number of
 * employees in hundredths of a full-time equivalent (null when not known),
 * the balance sheet total and the net turnover in öre. `source` is
 * `ledger` for a year kept in NONOX, `entered` for a year a person entered.
 * A fiscal year begins on the first day of a month and ends on the last day
 * of a month.
 */
final readonly class K3SizeFigures
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?int $employeesFteHundredths,
        public int $balanceSheetTotalOre,
        public int $netTurnoverOre,
        public string $source = 'entered',
    ) {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('A fiscal year ends after it begins.');
        }
    }

    /** The number of calendar months the year covers. */
    public function months(): int
    {
        return ($this->end->year * 12 + $this->end->month) - ($this->start->year * 12 + $this->start->month) + 1;
    }

    /**
     * The net turnover adjusted to twelve months (BFNAR 2006:11 point 5 c),
     * rounded half up to the öre. The balance sheet total is never adjusted.
     */
    public function annualisedNetTurnoverOre(): int
    {
        $months = $this->months();
        $twelve = K3Rules::int('net_turnover_prorated_to_months');

        if ($months === $twelve) {
            return $this->netTurnoverOre;
        }

        return BigDecimal::of($this->netTurnoverOre)
            ->multipliedBy($twelve)
            ->dividedBy($months, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /** "2025" for a year within one calendar year, else "2024/2025", as FiscalYear::label(). */
    public function label(): string
    {
        return $this->start->year === $this->end->year
            ? (string) $this->start->year
            : "{$this->start->year}/{$this->end->year}";
    }
}
