<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Planning;

use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Models\DemandForecast;
use Carbon\CarbonImmutable;

/**
 * The forecast as weekly buckets, the form the MRP engine reads (ADR-081
 * gap 5, E16): for each article and each week of the horizon the expected
 * demand in milli-units.
 *
 * - A week figure is used as it is.
 * - A month figure is spread over the days of the month, so that each week
 *   gets the share of the days it holds; the shares are cut with a running
 *   total, so the weeks of a month add up to the month's figure exactly.
 * - Where a week has its own figure, it wins over the month's share for that
 *   week (the person has been more specific).
 *
 * One query, whatever the number of articles. Runs inside the company.
 */
final class ForecastDemand
{
    /**
     * @param  list<int>  $articleIds
     * @param  CarbonImmutable  $firstMonday  the Monday the first bucket starts on
     * @param  int  $weeks  the number of weekly buckets, at most 52
     * @return array<int, array<string, int>> article id => (Monday as Y-m-d => milli-units), every article and every week present
     */
    public function weekly(array $articleIds, CarbonImmutable $firstMonday, int $weeks): array
    {
        $weeks = max(1, min($weeks, ForecastPeriodKind::Week->maxCount()));
        $first = ForecastPeriodKind::Week->startOf($firstMonday);
        $buckets = [];

        for ($index = 0; $index < $weeks; $index++) {
            $buckets[] = $first->addWeeks($index);
        }

        $lastDay = $buckets[$weeks - 1]->addDays(6);
        $result = [];

        foreach ($articleIds as $id) {
            $result[$id] = array_fill_keys(array_map(fn (CarbonImmutable $monday): string => $monday->toDateString(), $buckets), 0);
        }

        if ($articleIds === []) {
            return $result;
        }

        $rows = DemandForecast::query()
            ->whereIn('article_id', $articleIds)
            ->where(fn ($query) => $query
                ->where(fn ($week) => $week->where('period_kind', ForecastPeriodKind::Week->value)
                    ->whereBetween('period_start', [$first->toDateString(), $lastDay->toDateString()]))
                ->orWhere(fn ($month) => $month->where('period_kind', ForecastPeriodKind::Month->value)
                    ->whereBetween('period_start', [$first->startOfMonth()->toDateString(), $lastDay->toDateString()])))
            ->get(['article_id', 'period_kind', 'period_start', 'quantity_milli']);

        $own = [];

        foreach ($rows as $row) {
            if ($row->period_kind === ForecastPeriodKind::Week) {
                $key = $row->period_start->toDateString();
                $result[$row->article_id][$key] = $row->quantity_milli;
                $own[$row->article_id][$key] = true;
            }
        }

        foreach ($rows as $row) {
            if ($row->period_kind !== ForecastPeriodKind::Month) {
                continue;
            }

            $monthStart = $row->period_start;
            $monthEnd = $monthStart->endOfMonth()->startOfDay();
            $length = $monthStart->daysInMonth;

            foreach ($buckets as $monday) {
                $key = $monday->toDateString();

                if (isset($own[$row->article_id][$key])) {
                    continue;
                }

                $from = $monday->greaterThan($monthStart) ? $monday : $monthStart;
                $sunday = $monday->addDays(6);
                $to = $sunday->lessThan($monthEnd) ? $sunday : $monthEnd;

                if ($from->greaterThan($to)) {
                    continue;
                }

                $upTo = intdiv($row->quantity_milli * $to->day, $length);
                $before = intdiv($row->quantity_milli * ($from->day - 1), $length);
                $result[$row->article_id][$key] += $upTo - $before;
            }
        }

        return $result;
    }
}
