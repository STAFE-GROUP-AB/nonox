<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Budget;

/**
 * One week's staff-cost budget for an area or the whole company against the
 * estimated cost of the shifts planned in it (ADR-037 gap 2). The cost is
 * the sum of the shifts' stored estimates, so it follows the same rules:
 * only verified payroll rules, missing parts flagged (incomplete). A share
 * of the forecast has no amount when the week has no forecast at all.
 *
 * Status: OK below the threshold, NEAR from the threshold (default 95 %),
 * OVER from 100 %, UNKNOWN without an amount. Warnings never block.
 */
final readonly class BudgetLine
{
    public const string AMOUNT = 'AMOUNT';

    public const string PERCENT = 'PERCENT';

    /** The whole company without a budget of its own: the sum of the areas that have one. */
    public const string AREAS = 'AREAS';

    public const string OK = 'OK';

    public const string NEAR = 'NEAR';

    public const string OVER = 'OVER';

    public const string UNKNOWN = 'UNKNOWN';

    /**
     * @param  ?int  $areaId  null for the whole company
     * @param  ?int  $budgetOre  null when a share of the forecast has no forecast
     * @param  ?int  $forecastOre  the week's forecast sales, for a share
     * @param  int  $forecastDays  days of the week with a forecast, for a share
     * @param  ?list<int>  $areaIds  the areas an AREAS line sums
     * @param  ?list<string>  $forecastDates  for a share: the days (Y-m-d) with a forecast; the cost counts only those days
     * @param  int  $uncoveredDays  for a share: days with planned cost but no forecast, left out of the cost
     * @param  int  $uncoveredOre  the cost of those days
     * @param  ?array<int, ?list<string>>  $areaDates  for an AREAS line: each area's forecast days, null for an amount
     */
    public function __construct(
        public ?int $areaId,
        public string $kind,
        public ?int $budgetOre,
        public ?int $percentBp,
        public ?int $forecastOre,
        public int $forecastDays,
        public int $costOre,
        public bool $incomplete,
        public int $warnAtBp,
        public ?array $areaIds = null,
        public ?array $forecastDates = null,
        public int $uncoveredDays = 0,
        public int $uncoveredOre = 0,
        public ?array $areaDates = null,
    ) {}

    /** A share of the forecast as an amount, rounded half up to the öre; null without any forecast. */
    public static function shareOf(?int $forecastOre, int $percentBp): ?int
    {
        if ($forecastOre === null) {
            return null;
        }

        return intdiv(max(0, $forecastOre) * $percentBp + 5_000, 10_000);
    }

    public function status(): string
    {
        if ($this->budgetOre === null) {
            return self::UNKNOWN;
        }

        if ($this->costOre >= $this->budgetOre) {
            return $this->costOre === 0 ? self::OK : self::OVER;
        }

        return $this->costOre * 10_000 >= $this->budgetOre * $this->warnAtBp ? self::NEAR : self::OK;
    }

    public function warns(): bool
    {
        return in_array($this->status(), [self::NEAR, self::OVER], true);
    }

    public function exceeded(): bool
    {
        return $this->budgetOre !== null && $this->costOre > $this->budgetOre;
    }

    /** The cost as a share of the budget, one decimal; null without a budget amount above zero. */
    public function usedPercent(): ?float
    {
        if ($this->budgetOre === null || $this->budgetOre <= 0) {
            return null;
        }

        return round($this->costOre * 100 / $this->budgetOre, 1);
    }

    /**
     * Whether a shift in the area that starts on the day (Y-m-d) counts in
     * this line's cost: a share of the forecast counts only the days that
     * have a forecast.
     */
    public function counts(?int $areaId, string $date): bool
    {
        if (! $this->covers($areaId)) {
            return false;
        }

        $dates = $this->kind === self::AREAS ? ($this->areaDates[(int) $areaId] ?? null) : $this->forecastDates;

        return $dates === null || in_array($date, $dates, true);
    }

    /** Whether a shift in the area (null: without an area) counts against this line. */
    public function covers(?int $areaId): bool
    {
        return match (true) {
            $this->kind === self::AREAS => $areaId !== null && in_array($areaId, $this->areaIds ?? [], true),
            $this->areaId === null => true,
            default => $this->areaId === $areaId,
        };
    }
}
