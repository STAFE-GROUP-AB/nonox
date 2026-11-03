<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The sales forecast (ADR-043 stage 2): per month, the open deals' value
 * weighted by their stage's probability, by the month they are expected to
 * close, next to the value actually won that month. Open deals whose close
 * date has passed are a bucket of their own (the date needs a look), as are
 * those without a date and those beyond the last month shown. Money is öre
 * throughout; a weighted sum is rounded half up to whole öre once per
 * bucket, never per deal.
 *
 * Two queries, whatever the number of deals: the open deals grouped by
 * close date and probability, and the won deals grouped by month.
 */
final class Forecast
{
    public const string ZONE = 'Europe/Stockholm';

    /**
     * @return array{
     *     months: list<array{month: string, value: int, weighted: int, count: int, won: int, won_count: int}>,
     *     overdue: array{value: int, weighted: int, count: int},
     *     undated: array{value: int, weighted: int, count: int},
     *     later: array{value: int, weighted: int, count: int}
     * }
     */
    public function for(CarbonImmutable $today, int $monthsAhead = 6, int $monthsBack = 3, ?int $ownerId = null, ?int $pipelineId = null): array
    {
        $first = $today->setTimezone(self::ZONE)->startOfMonth()->subMonthsNoOverflow($monthsBack);

        $open = Deal::query()
            ->join('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_deals.stage_id')
            ->where('crm_deals.status', DealStatus::Open->value)
            ->when($ownerId !== null, fn ($query) => $query->where('crm_deals.owner_id', $ownerId))
            ->when($pipelineId !== null, fn ($query) => $query->where('crm_deals.pipeline_id', $pipelineId))
            ->groupBy('crm_deals.expected_close_date', 'crm_pipeline_stages.probability')
            ->get([
                'crm_deals.expected_close_date as date',
                'crm_pipeline_stages.probability as probability',
                DB::raw('SUM(crm_deals.value_ore) as value'),
                DB::raw('COUNT(*) as count'),
            ])
            ->map(fn (Deal $row): array => [
                'date' => $row->getAttribute('date') === null ? null : substr((string) $row->getAttribute('date'), 0, 10),
                'probability' => (int) $row->getAttribute('probability'),
                'value' => (int) $row->getAttribute('value'),
                'count' => (int) $row->getAttribute('count'),
            ])
            ->all();

        $won = Deal::query()
            ->where('status', DealStatus::Won->value)
            ->where('won_at', '>=', $first)
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->when($pipelineId !== null, fn ($query) => $query->where('pipeline_id', $pipelineId))
            ->groupByRaw("to_char(won_at AT TIME ZONE 'Europe/Stockholm', 'YYYY-MM')")
            ->get([
                DB::raw("to_char(won_at AT TIME ZONE 'Europe/Stockholm', 'YYYY-MM') as month"),
                DB::raw('SUM(value_ore) as value'),
                DB::raw('COUNT(*) as count'),
            ])
            ->map(fn (Deal $row): array => ['month' => (string) $row->getAttribute('month'), 'value' => (int) $row->getAttribute('value'), 'count' => (int) $row->getAttribute('count')])
            ->all();

        return self::build($open, $won, $today, $monthsAhead, $monthsBack);
    }

    /**
     * The forecast from grouped rows; pure, so it can be tested on its own.
     *
     * @param  array<int, array{date: ?string, probability: int, value: int, count: int}>  $open
     * @param  array<int, array{month: string, value: int, count: int}>  $won
     * @return array{
     *     months: list<array{month: string, value: int, weighted: int, count: int, won: int, won_count: int}>,
     *     overdue: array{value: int, weighted: int, count: int},
     *     undated: array{value: int, weighted: int, count: int},
     *     later: array{value: int, weighted: int, count: int}
     * }
     */
    public static function build(array $open, array $won, CarbonImmutable $today, int $monthsAhead = 6, int $monthsBack = 3): array
    {
        $today = $today->setTimezone(self::ZONE)->startOfDay();
        $todayDate = $today->toDateString();
        $current = $today->startOfMonth();
        $last = $current->addMonthsNoOverflow(max(1, $monthsAhead) - 1)->format('Y-m');

        /** @var array<string, array{value: int, product: int, count: int, won: int, won_count: int}> $months */
        $months = [];

        for ($month = $current->subMonthsNoOverflow(max(0, $monthsBack)); $month->format('Y-m') <= $last; $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = ['value' => 0, 'product' => 0, 'count' => 0, 'won' => 0, 'won_count' => 0];
        }

        /** @var array<string, array{value: int, product: int, count: int}> $buckets */
        $buckets = [
            'overdue' => ['value' => 0, 'product' => 0, 'count' => 0],
            'undated' => ['value' => 0, 'product' => 0, 'count' => 0],
            'later' => ['value' => 0, 'product' => 0, 'count' => 0],
        ];

        foreach ($open as $row) {
            $product = $row['value'] * max(0, min(100, $row['probability']));
            $month = $row['date'] === null ? null : substr($row['date'], 0, 7);
            $bucket = match (true) {
                $row['date'] === null => 'undated',
                $row['date'] < $todayDate => 'overdue',
                $month > $last => 'later',
                default => null,
            };

            if ($bucket !== null) {
                $buckets[$bucket] = [
                    'value' => $buckets[$bucket]['value'] + $row['value'],
                    'product' => $buckets[$bucket]['product'] + $product,
                    'count' => $buckets[$bucket]['count'] + $row['count'],
                ];

                continue;
            }

            // A date this month or later, up to the last month: always one of the rows.
            $sums = $months[(string) $month];
            $months[(string) $month] = [...$sums, 'value' => $sums['value'] + $row['value'], 'product' => $sums['product'] + $product, 'count' => $sums['count'] + $row['count']];
        }

        foreach ($won as $row) {
            if (isset($months[$row['month']])) {
                $sums = $months[$row['month']];
                $months[$row['month']] = [...$sums, 'won' => $sums['won'] + $row['value'], 'won_count' => $sums['won_count'] + $row['count']];
            }
        }

        $result = [];

        foreach ($months as $month => $sums) {
            $result[] = ['month' => $month, 'value' => $sums['value'], 'weighted' => self::percentOf($sums['product']), 'count' => $sums['count'], 'won' => $sums['won'], 'won_count' => $sums['won_count']];
        }

        $bucket = fn (array $sums): array => ['value' => $sums['value'], 'weighted' => self::percentOf($sums['product']), 'count' => $sums['count']];

        return [
            'months' => $result,
            'overdue' => $bucket($buckets['overdue']),
            'undated' => $bucket($buckets['undated']),
            'later' => $bucket($buckets['later']),
        ];
    }

    /** öre × percent → öre, half up. The sums are never negative (crm_deals_value_check). */
    public static function percentOf(int $product): int
    {
        return intdiv($product + 50, 100);
    }
}
