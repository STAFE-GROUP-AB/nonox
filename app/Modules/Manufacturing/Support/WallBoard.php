<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Domain\WaitsFor;
use App\Modules\Manufacturing\Models\Downtime;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanBoard;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the workshop wall screen shows (ADR-071, M7): today's planned work
 * per production order, the orders the plan says will be late, what is to
 * be delivered in the coming working days, and the operations that wait or
 * the workstations that are stopped. Read from the latest plan run through
 * PlanBoard, in a fixed number of queries whatever the number of orders.
 *
 * The screen is seen by everyone in the workshop, visitors included, so it
 * names nobody: no person, no absence, no load or time per person, and no
 * customer (a private customer's name is personal data, and the customer
 * list is the company's own). The free-text reason of a waiting flag is
 * left out too, since it could name a person (ADR-071 §7, M5 review).
 */
final class WallBoard
{
    /** Production orders shown under "I dag"; the rest is counted. */
    public const int WORK_LIMIT = 12;

    /** Rows in each of the other lists; the rest is counted. */
    public const int LIST_LIMIT = 8;

    /** How many working days ahead "Ska levereras" looks, today included. */
    public const int DELIVERY_WORKING_DAYS = 5;

    /** How often the screen asks again, in seconds. */
    public const int REFRESH_SECONDS = 30;

    public function __construct(private readonly PlanBoard $board) {}

    /** @return array<string, mixed> */
    public function build(Company $company, ?CarbonImmutable $now = null): array
    {
        return CurrentCompany::run($company, function () use ($company, $now): array {
            $now = ($now ?? CarbonImmutable::now())->setTimezone(Clock::ZONE);
            $today = $now->startOfDay();
            $run = $this->board->latestRun($company);
            $base = [
                'company' => $company->name,
                'now' => $now->toIso8601String(),
                'today' => $today->toDateString(),
                'refresh_seconds' => self::REFRESH_SECONDS,
                'run' => $run === null ? null : ['id' => $run->id, 'at' => $run->created_at->setTimezone(Clock::ZONE)->toIso8601String()],
            ];

            $flags = WorkshopWaitingFlag::query()->open()
                ->with(['order:id,number,name,article_id,quantity_milli', 'orderOperation:id,operation_id', 'orderOperation.operation:id,name'])
                ->orderBy('raised_at')->orderBy('id')
                ->get();
            $downtimes = Downtime::query()
                ->with('workstation:id,name')
                ->where('starts_at', '<', $today->addDay())
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->orderBy('starts_at')->orderBy('id')
                ->get();

            if ($run === null) {
                $articles = $this->articles($flags->pluck('order.article_id')->filter()->all());

                return [...$base, 'work' => [], 'work_more' => 0, 'late' => [], 'late_more' => 0, 'deliveries' => [], 'deliveries_more' => 0, ...$this->stops($flags, $downtimes, $articles, $today)];
            }

            $calendar = PlanningSettings::for($company)->calendar();
            $forecasts = $this->board->forecasts($company, $run, withCustomers: false);
            $slots = $this->board->slots($company, $run, $today, $today->addDay(), withPeople: false);
            $articles = $this->articles([
                ...$forecasts->pluck('order.article_id')->all(),
                ...$slots->pluck('order.article_id')->all(),
                ...$flags->pluck('order.article_id')->all(),
            ]);
            $byOrder = $forecasts->keyBy('production_order_id');

            $work = $this->work($slots, $byOrder, $articles, $now);
            $late = $forecasts->filter(fn (ProductionPlanForecast $forecast): bool => $forecast->status === ForecastStatus::Red)->values();
            $until = $calendar->isWorkingDay($today)
                ? $calendar->addWorkingDays($today, self::DELIVERY_WORKING_DAYS - 1)
                : $calendar->addWorkingDays($today, self::DELIVERY_WORKING_DAYS);
            $deliveries = $forecasts
                ->filter(fn (ProductionPlanForecast $forecast): bool => $forecast->promised_on !== null
                    && $forecast->promised_on->toDateString() >= $today->toDateString()
                    && $forecast->promised_on->toDateString() <= $until->toDateString())
                ->sortBy(fn (ProductionPlanForecast $forecast): array => [$forecast->promised_on?->toDateString(), $forecast->status->rank(), $forecast->order->number ?? 0])
                ->values();

            return [
                ...$base,
                'work' => array_slice($work, 0, self::WORK_LIMIT),
                'work_more' => max(0, count($work) - self::WORK_LIMIT),
                'late' => $late->take(self::LIST_LIMIT)->map(fn (ProductionPlanForecast $forecast): array => [
                    ...$this->order($forecast->order, $articles),
                    'promised_on' => $forecast->promised_on?->toDateString(),
                    'forecast_on' => $forecast->forecast_delivery_on?->toDateString(),
                    'days_late' => $forecast->promised_on !== null && $forecast->forecast_delivery_on !== null
                        ? $calendar->workingDaysBetween($forecast->promised_on->toDateString(), $forecast->forecast_delivery_on->toDateString())
                        : null,
                    'waits_for' => $forecast->waits_for === WaitsFor::Nothing ? null : $forecast->waits_for->label(),
                ])->values()->all(),
                'late_more' => max(0, $late->count() - self::LIST_LIMIT),
                'deliveries' => $deliveries->take(self::LIST_LIMIT)->map(fn (ProductionPlanForecast $forecast): array => [
                    ...$this->order($forecast->order, $articles),
                    'promised_on' => $forecast->promised_on?->toDateString(),
                    'forecast_on' => $forecast->forecast_delivery_on?->toDateString(),
                    'status' => $forecast->status->value,
                    'status_label' => $forecast->status->label(),
                ])->values()->all(),
                'deliveries_more' => max(0, $deliveries->count() - self::LIST_LIMIT),
                ...$this->stops($flags, $downtimes, $articles, $today),
            ];
        });
    }

    /**
     * Today's slots, grouped per production order in the order they start,
     * with each operation's working time today and whether it is going on
     * now. The slot's person is not read.
     *
     * @param  Collection<int, ProductionPlanSlot>  $slots
     * @param  Collection<int, ProductionPlanForecast>  $forecasts  keyed by production order id
     * @param  Collection<int, Article>  $articles
     * @return list<array<string, mixed>>
     */
    private function work(Collection $slots, Collection $forecasts, Collection $articles, CarbonImmutable $now): array
    {
        $day = $now->toDateString();
        $minute = Clock::minute($now);
        $orders = [];

        foreach ($slots as $slot) {
            $today = array_values(array_filter($slot->segments, fn (array $segment): bool => Clock::date((int) $segment[0]) === $day));

            if ($today === []) {
                continue;
            }

            $start = (int) min(array_column($today, 0));
            $end = (int) max(array_column($today, 1));
            $id = $slot->production_order_id;
            $forecast = $forecasts->get($id);

            // The forecast's order has every column, the quantity included; the slot's has only a few.
            $orders[$id] ??= [
                ...$this->order($forecast->order ?? $slot->order, $articles),
                'status' => $forecast?->status->value,
                'status_label' => $forecast?->status->label(),
                'starts' => $start,
                'operations' => [],
            ];
            $orders[$id]['starts'] = min($orders[$id]['starts'], $start);
            $orders[$id]['operations'][] = [
                'name' => $slot->orderOperation->operation->name ?? '',
                'workstation' => $slot->workstation?->name,
                'from' => Clock::at($start)->format('H:i'),
                'to' => Clock::at($end)->format('H:i'),
                'now' => $start <= $minute && $minute < $end,
                'done' => $end <= $minute,
            ];
        }

        usort($orders, fn (array $a, array $b): int => [$a['starts'], $a['reference']] <=> [$b['starts'], $b['reference']]);

        return array_map(function (array $order): array {
            unset($order['starts']);

            return $order;
        }, $orders);
    }

    /**
     * Operations flagged as waiting at the terminal, without their reason,
     * and the workstations stopped today.
     *
     * @param  Collection<int, WorkshopWaitingFlag>  $flags
     * @param  Collection<int, Downtime>  $downtimes
     * @param  Collection<int, Article>  $articles
     * @return array{waiting: list<array<string, mixed>>, waiting_more: int, stopped: list<array<string, mixed>>}
     */
    private function stops(Collection $flags, Collection $downtimes, Collection $articles, CarbonImmutable $today): array
    {
        $when = fn (CarbonImmutable $at): string => $at->setTimezone(Clock::ZONE)->toDateString() === $today->toDateString()
            ? $at->setTimezone(Clock::ZONE)->format('H:i')
            : $at->setTimezone(Clock::ZONE)->toDateString();

        return [
            'waiting' => array_values($flags->take(self::LIST_LIMIT)->map(fn (WorkshopWaitingFlag $flag): array => [
                ...$this->order($flag->order, $articles),
                'operation' => $flag->orderOperation->operation->name ?? '',
                'kind' => $flag->kind->value,
                'kind_label' => $flag->kind->label(),
                'since' => $when($flag->raised_at),
            ])->all()),
            'waiting_more' => max(0, $flags->count() - self::LIST_LIMIT),
            'stopped' => array_values($downtimes->map(fn (Downtime $downtime): array => [
                'workstation' => $downtime->workstation->name ?? '',
                'reason' => $downtime->reason->label(),
                'from' => $when($downtime->starts_at),
                'until' => $downtime->ends_at === null ? null : $when($downtime->ends_at),
            ])->all()),
        ];
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @return array{reference: string, product: string, quantity: string}
     */
    private function order(?ProductionOrder $order, Collection $articles): array
    {
        if ($order === null) {
            return ['reference' => '', 'product' => '', 'quantity' => ''];
        }

        $article = $articles->get($order->article_id);
        $quantity = $order->getAttributes()['quantity_milli'] ?? null;

        return [
            'reference' => $order->reference(),
            'product' => $article->name ?? $order->name,
            'quantity' => $quantity === null ? '' : trim(Quantity::format((int) $quantity).' '.($article?->stockUnitCode() ?? '')),
        ];
    }

    /**
     * The products' names and stock units, in one query.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Article>
     */
    private function articles(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids, fn (mixed $id): bool => is_int($id))));

        if ($ids === []) {
            return collect();
        }

        return Article::query()->with('stockUnit')->whereIn('id', $ids)->get(['id', 'name', 'unit', 'stock_unit_id'])->keyBy('id');
    }
}
