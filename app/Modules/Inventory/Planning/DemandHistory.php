<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Planning;

use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Models\StockMovement;
use Carbon\CarbonImmutable;

/**
 * What an article has been used for in the past, the basis of the computed
 * forecast (ADR-081 gap 5). A plain rule anyone can recompute by hand:
 *
 * - The window is the last N complete calendar months before the current
 *   month (default 3, at most 24). With today 2026-11-18 and N = 3 it is
 *   2026-08-01 to 2026-10-31.
 * - Consumption is what left stock through picks, till sales, recipe sales
 *   and production issues in the window, net of what came back through the
 *   same kinds of movement (a cancelled production order), never below 0.
 *   Transfers, scrapping, own use, work order issues, returns and counts are
 *   not demand.
 * - A week's figure is the consumption divided by the days of the window,
 *   times 7; a month's is the consumption divided by N; both rounded half up
 *   to a milli-unit.
 *
 * Runs inside the company (StockMovement is company scoped).
 */
final class DemandHistory
{
    public const int DEFAULT_MONTHS = 3;

    public const int MAX_MONTHS = 24;

    /** The movement types that count as demand. */
    public const array TYPES = [MovementType::Pick, MovementType::PosSale, MovementType::RecipeSale, MovementType::ProductionIssue];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $months,
    ) {}

    /** The window of the last $months complete months before the month $today is in. */
    public static function window(CarbonImmutable $today, int $months = self::DEFAULT_MONTHS): self
    {
        $months = max(1, min($months, self::MAX_MONTHS));
        $to = $today->startOfMonth()->startOfDay();

        return new self($to->subMonthsNoOverflow($months), $to, $months);
    }

    /** The number of days in the window. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to);
    }

    /** The last day of the window, for showing it. */
    public function lastDay(): CarbonImmutable
    {
        return $this->to->subDay();
    }

    /**
     * Consumption in the window per article, in milli-units. Articles without
     * any are left out of the answer.
     *
     * @param  list<int>  $articleIds
     * @return array<int, int>
     */
    public function consumption(array $articleIds): array
    {
        $totals = [];

        foreach (array_chunk($articleIds, 1000) as $chunk) {
            $rows = StockMovement::query()
                ->whereIn('article_id', $chunk)
                ->whereIn('type', array_map(fn (MovementType $type): string => $type->value, self::TYPES))
                ->where('occurred_on', '>=', $this->from->toDateString())
                ->where('occurred_on', '<', $this->to->toDateString())
                ->groupBy('article_id')
                ->selectRaw('article_id, -sum(quantity_milli) as consumed')
                ->get();

            foreach ($rows as $row) {
                $consumed = (int) $row->getAttribute('consumed');

                if ($consumed > 0) {
                    $totals[(int) $row->article_id] = $consumed;
                }
            }
        }

        return $totals;
    }

    /** What a week's demand is, for a consumption total in this window. */
    public function perWeek(int $consumed): int
    {
        return self::divide($consumed * 7, $this->days());
    }

    /** What a month's demand is, for a consumption total in this window. */
    public function perMonth(int $consumed): int
    {
        return self::divide($consumed, $this->months);
    }

    /** Integer division rounded half up, for non-negative numbers. */
    private static function divide(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
