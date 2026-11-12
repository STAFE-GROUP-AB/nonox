<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The post-calculation (efterkalkyl, ADR-071 §7): per product and operation
 * what was calculated against what was reported, how much it varied and
 * which way it is going; which operations are bottlenecks; and what
 * the open orders are waiting for, material and work kept apart.
 *
 * It reads the order's own ledgers: planned minutes, labour entries of
 * the kinds somebody reported (entered at finish and from the terminal;
 * minutes the system filled in from the plan are not "actual"), and the
 * waiting flags. It never reads who reported, with one exception: a count
 * of how many different people reported on a product and operation, so
 * that the variation over time is hidden where one or two people did it
 * all. Even the totals can relate to a single person in a small workshop:
 * TODO(legal-verify) whether that is per-person follow-up under MBL 11 and
 * 19 §§ and GDPR art. 6.1 f and 13. The per-person analysis (`per_person_analysis`) is blocked
 * on a legal review (GDPR art. 6.1 f and 13, MBL 11 and 19 §§) and is not
 * built; nothing here is a step towards it.
 *
 * A fixed number of queries whatever the number of products or orders.
 */
final class WorkshopAnalysis
{
    public const array PERIODS = [3, 6, 12, 24];

    /** A product needs this many orders before its trend means anything. */
    public const int TREND_MIN_ORDERS = 4;

    /** Spread, series and trend are shown only when at least this many people reported on the product and operation (GDPR, MBL 11 and 19 §§). */
    public const int MIN_PEOPLE_FOR_DETAIL = 3;

    /** Percentage points the newer half must differ by to count as a trend. */
    public const int TREND_THRESHOLD = 5;

    /**
     * @return array{
     *     since: CarbonImmutable,
     *     months: int,
     *     products: list<array<string, mixed>>,
     *     overruns: list<array<string, mixed>>,
     *     queue: list<array<string, mixed>>,
     *     waiting: array{material: list<array<string, mixed>>, work: list<array<string, mixed>>},
     *     waited: array{material: array{flags: int, minutes: int}, work: array{flags: int, minutes: int}}
     * }
     */
    public static function for(int $months = 12): array
    {
        $months = in_array($months, self::PERIODS, true) ? $months : 12;
        $now = CarbonImmutable::now();
        $since = $now->subMonths($months)->startOfDay();

        $history = self::history($since);

        return [
            'since' => $since,
            'months' => $months,
            'products' => $history['products'],
            'overruns' => $history['overruns'],
            'overruns_hidden' => $history['overruns_hidden'],
            'queue' => self::queue(),
            ...self::waiting($since, $now),
        ];
    }

    /**
     * Per product and operation over the finished orders of the period.
     *
     * @return array{products: list<array<string, mixed>>, overruns: list<array<string, mixed>>, overruns_hidden: int}
     */
    private static function history(CarbonImmutable $since): array
    {
        $orders = ProductionOrder::query()->with('article:id,name,number')
            ->where('status', ProductionOrderStatus::Finished->value)->whereDate('finished_on', '>=', $since)
            ->orderBy('finished_on')->orderBy('id')->get(['id', 'article_id', 'number', 'finished_on']);

        if ($orders->isEmpty()) {
            return ['products' => [], 'overruns' => [], 'overruns_hidden' => 0];
        }

        $operations = ProductionOrderOperation::query()->with('operation:id,name')->whereIn('production_order_id', $orders->pluck('id'))->get(['id', 'production_order_id', 'operation_id', 'planned_minutes']);
        $reported = ProductionLabourEntry::query()->whereIn('production_order_id', $orders->pluck('id'))
            ->whereIn('source', [LabourSource::Entered->value, LabourSource::Terminal->value])
            ->groupBy('production_order_operation_id')->selectRaw('production_order_operation_id, sum(minutes) as minutes')
            ->pluck('minutes', 'production_order_operation_id');
        $byOrder = $orders->keyBy('id');
        [$people, $peopleByOperation] = self::peopleCounts($orders->map(fn (ProductionOrder $order): int => $order->id)->values()->all());

        /** @var array<string, array{article: string, operation: string, rows: list<array{planned: int, actual: int, on: string}>, unreported: int}> $groups */
        $groups = [];
        /** @var array<int, array{operation_id: int, operation: string, planned: int, actual: int, over: int, orders: int, over_orders: int}> $byOperation */
        $byOperation = [];

        foreach ($operations as $operation) {
            $order = $byOrder->get($operation->production_order_id);

            if ($order === null) {
                continue;
            }

            $key = $order->article_id.'-'.$operation->operation_id;
            $groups[$key] ??= ['article' => $order->article->name ?? '', 'operation' => $operation->operation->name ?? '', 'rows' => [], 'unreported' => 0];
            $actual = (int) ($reported[$operation->id] ?? 0);

            if ($actual <= 0) {
                $groups[$key]['unreported']++;

                continue;
            }

            $groups[$key]['rows'][] = ['planned' => $operation->planned_minutes, 'actual' => $actual, 'on' => $order->finished_on?->toDateString() ?? ''];

            $byOperation[$operation->operation_id] ??= ['operation_id' => $operation->operation_id, 'operation' => $operation->operation->name ?? '', 'planned' => 0, 'actual' => 0, 'over' => 0, 'orders' => 0, 'over_orders' => 0];
            $byOperation[$operation->operation_id]['planned'] += $operation->planned_minutes;
            $byOperation[$operation->operation_id]['actual'] += $actual;
            $byOperation[$operation->operation_id]['orders']++;

            if ($actual > $operation->planned_minutes) {
                $byOperation[$operation->operation_id]['over'] += $actual - $operation->planned_minutes;
                $byOperation[$operation->operation_id]['over_orders']++;
            }
        }

        $products = [];

        foreach ($groups as $key => $group) {
            $rows = $group['rows'];

            // Nobody reported on this step: there is no actual time to compare.
            if ($rows === []) {
                continue;
            }

            $planned = array_sum(array_column($rows, 'planned'));
            $actual = array_sum(array_column($rows, 'actual'));
            $deviations = array_map(fn (array $row): int => self::percent($row['actual'] - $row['planned'], $row['planned']), $rows);

            // Where one or two people did all the reporting, the variation over time is theirs in all but name.
            $hidden = isset($people[$key]) && $people[$key] < self::MIN_PEOPLE_FOR_DETAIL;

            $products[] = [
                'article' => $group['article'],
                'operation' => $group['operation'],
                'orders' => count($rows),
                'unreported' => $group['unreported'],
                'planned_minutes' => $planned,
                'actual_minutes' => $actual,
                'deviation_percent' => self::percent($actual - $planned, $planned),
                'detail_hidden' => $hidden,
                'spread' => $hidden ? null : ['min' => min($deviations), 'median' => self::median($deviations), 'max' => max($deviations)],
                'series' => $hidden ? [] : array_slice($deviations, -12),
                'trend' => $hidden ? null : self::trend($deviations),
            ];
        }

        usort($products, fn (array $a, array $b): int => [$a['article'], $a['operation']] <=> [$b['article'], $b['operation']]);

        $overruns = array_values(array_filter($byOperation, fn (array $row): bool => $row['over'] > 0));
        usort($overruns, fn (array $a, array $b): int => $b['over'] <=> $a['over']);

        // Comparing periods of an operation one or two people do would show their work over time, as the detail above would.
        $shown = array_values(array_filter($overruns, fn (array $row): bool => ! isset($peopleByOperation[$row['operation_id']]) || $peopleByOperation[$row['operation_id']] >= self::MIN_PEOPLE_FOR_DETAIL));

        return ['products' => $products, 'overruns_hidden' => count($overruns) - count($shown), 'overruns' => array_slice(array_map(fn (array $row): array => [
            ...$row,
            'deviation_percent' => self::percent($row['actual'] - $row['planned'], $row['planned']),
        ], $shown), 0, 5)];
    }

    /**
     * How many different people reported time on each product and operation
     * of these orders, and on each operation across products, as counts
     * only: which people is never read. A person counts when their minutes
     * on the group add up to more than nothing, so a report that was
     * corrected away does not make anyone count. Operations nobody reported
     * on at the terminal (the time was entered when the order was finished)
     * have no entry, and nothing is hidden for them.
     *
     * @param  array<int, int>  $orderIds
     * @return array{0: array<string, int>, 1: array<int, int>} "article-operation" => people, and operation id => people
     */
    private static function peopleCounts(array $orderIds): array
    {
        $perPerson = fn (array $columns) => DB::table('workshop_time_entries as entries')
            ->join('production_orders as orders', 'orders.id', '=', 'entries.production_order_id')
            ->join('production_order_operations as steps', 'steps.id', '=', 'entries.production_order_operation_id')
            ->where('entries.company_id', CurrentCompany::id())
            ->whereIn('entries.production_order_id', $orderIds)
            ->whereNotNull('entries.person_id')
            ->groupBy([...$columns, 'entries.person_id'])
            ->havingRaw('sum(entries.minutes) > 0')
            ->select($columns)
            ->selectRaw('1 as reporter');

        $byProduct = [];

        foreach (DB::query()->fromSub($perPerson(['orders.article_id', 'steps.operation_id']), 'reporters')->groupBy('article_id', 'operation_id')->selectRaw('article_id, operation_id, count(*) as people')->get() as $row) {
            $byProduct[$row->article_id.'-'.$row->operation_id] = (int) $row->people;
        }

        $byOperation = [];

        foreach (DB::query()->fromSub($perPerson(['steps.operation_id']), 'reporters')->groupBy('operation_id')->selectRaw('operation_id, count(*) as people')->get() as $row) {
            $byOperation[(int) $row->operation_id] = (int) $row->people;
        }

        return [$byProduct, $byOperation];
    }

    /**
     * The minutes still to do on open orders, per operation, against the
     * operation's daily capacity: the most queued days first, then the
     * operations without a capacity by minutes.
     *
     * @return list<array<string, mixed>>
     */
    private static function queue(): array
    {
        $open = ProductionOrder::query()->reserving()->pluck('id');

        if ($open->isEmpty()) {
            return [];
        }

        $operations = ProductionOrderOperation::query()->whereIn('production_order_id', $open)->get(['id', 'production_order_id', 'operation_id', 'planned_minutes']);
        $reported = ProductionLabourEntry::query()->whereIn('production_order_id', $open)
            ->groupBy('production_order_operation_id')->selectRaw('production_order_operation_id, sum(minutes) as minutes')
            ->pluck('minutes', 'production_order_operation_id');
        $catalogue = Operation::query()->whereIn('id', $operations->pluck('operation_id')->unique())->get()->keyBy('id');

        /** @var array<int, array{remaining: int, orders: int}> $perOperation */
        $perOperation = [];

        foreach ($operations as $operation) {
            $remaining = max(0, $operation->planned_minutes - (int) ($reported[$operation->id] ?? 0));

            if ($remaining === 0) {
                continue;
            }

            $perOperation[$operation->operation_id] ??= ['remaining' => 0, 'orders' => 0];
            $perOperation[$operation->operation_id]['remaining'] += $remaining;
            $perOperation[$operation->operation_id]['orders']++;
        }

        $rows = [];

        foreach ($perOperation as $operationId => $row) {
            $capacity = $catalogue->get($operationId)?->daily_capacity_minutes;

            $rows[] = [
                'operation' => $catalogue->get($operationId)->name ?? '',
                'remaining_minutes' => $row['remaining'],
                'orders' => $row['orders'],
                'capacity_minutes' => $capacity,
                'days' => $capacity === null || $capacity <= 0 ? null : round($row['remaining'] / $capacity, 1),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['days'] !== null, $b['days'] ?? 0, $b['remaining_minutes']] <=> [$a['days'] !== null, $a['days'] ?? 0, $a['remaining_minutes']]);

        return $rows;
    }

    /**
     * What open orders wait for now, and how long operations have waited in
     * the period, material and work apart.
     *
     * @return array{waiting: array{material: list<array<string, mixed>>, work: list<array<string, mixed>>}, waited: array{material: array{flags: int, minutes: int}, work: array{flags: int, minutes: int}}}
     */
    private static function waiting(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $flags = WorkshopWaitingFlag::query()
            ->with(['order:id,number,article_id,status,finished_on,cancelled_on', 'order.article:id,name', 'orderOperation:id,operation_id', 'orderOperation.operation:id,name'])
            ->where(fn ($query) => $query->whereNull('cleared_at')->orWhere('raised_at', '>=', $since))
            ->orderBy('raised_at')->get();

        $waiting = ['material' => [], 'work' => []];
        $waited = ['material' => ['flags' => 0, 'minutes' => 0], 'work' => ['flags' => 0, 'minutes' => 0]];

        foreach ($flags as $flag) {
            $bucket = $flag->kind === WaitingKind::Material ? 'material' : 'work';
            $order = $flag->order;

            if ($order === null) {
                continue;
            }

            $closedOn = $order->status->isOpen() ? null : ($order->finished_on ?? $order->cancelled_on)?->endOfDay();
            $until = $flag->cleared_at ?? $closedOn ?? $now;
            $minutes = max(0, (int) $flag->raised_at->diffInMinutes($until));

            if ($flag->cleared_at === null && $order->status->reserves()) {
                $waiting[$bucket][] = [
                    'reference' => $order->reference(),
                    'product' => $order->article->name ?? '',
                    'operation' => $flag->orderOperation->operation->name ?? '',
                    'reason' => $flag->reason,
                    'since' => $flag->raised_at,
                    'minutes' => $minutes,
                ];
            }

            if ($flag->raised_at >= $since) {
                $waited[$bucket]['flags']++;
                $waited[$bucket]['minutes'] += $minutes;
            }
        }

        return ['waiting' => $waiting, 'waited' => $waited];
    }

    /** Whole percent, rounded half away from zero; 0 when there was nothing planned. */
    private static function percent(int $difference, int $planned): int
    {
        return $planned <= 0 ? 0 : (int) round($difference * 100 / $planned);
    }

    /** @param  list<int>  $values */
    private static function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Whether the product's orders go the right way: the newer half of the
     * deviations against the older half, in percentage points. Too few
     * orders say nothing.
     *
     * @param  list<int>  $deviations  oldest first
     * @return array{direction: string, change: int}|null
     */
    private static function trend(array $deviations): ?array
    {
        if (count($deviations) < self::TREND_MIN_ORDERS) {
            return null;
        }

        $half = intdiv(count($deviations), 2);
        $older = array_slice($deviations, 0, $half);
        $newer = array_slice($deviations, -$half);
        $change = (int) round(array_sum($newer) / count($newer) - array_sum($older) / count($older));

        return [
            'direction' => match (true) {
                $change <= -self::TREND_THRESHOLD => 'better',
                $change >= self::TREND_THRESHOLD => 'worse',
                default => 'steady',
            },
            'change' => $change,
        ];
    }
}
