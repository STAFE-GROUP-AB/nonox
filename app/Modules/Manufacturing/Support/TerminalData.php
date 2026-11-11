<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopPeople;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use Carbon\CarbonImmutable;

/**
 * What the workshop terminal shows (ADR-071 §7). The people to tap, the
 * open production orders with their operations, and a person's own recent
 * reports. A fixed number of queries whatever the number of orders: every
 * relation is loaded up front, and the minutes are summed in two grouped
 * queries.
 *
 * Nothing about another person's time leaves here: the order list shows
 * the whole order's reported minutes, and only the signed-in person's own
 * minutes for today.
 */
final class TerminalData
{
    /** How far back a person sees their own reports. */
    public const int HISTORY_DAYS = 14;

    /**
     * @return list<array{id: int, name: string, photo: ?string}>
     */
    public static function people(): array
    {
        return array_values(WorkshopPerson::query()->active()->whereNotNull('code_verifier')->orderBy('name')->get()
            ->map(fn (WorkshopPerson $person): array => ['id' => $person->id, 'name' => $person->name, 'photo' => WorkshopPeople::photoDataUrl($person)])
            ->all());
    }

    /**
     * The orders that are released or started: product, customer, quantity,
     * due date and, per operation, standard and reported minutes, the
     * signed-in person's own minutes today and an open waiting flag.
     *
     * @return list<array<string, mixed>>
     */
    public static function orders(WorkshopPerson $person): array
    {
        $today = CarbonImmutable::today();
        $orders = ProductionOrder::query()->reserving()
            ->with(['article.stockUnit', 'salesDocument.customer', 'operations.operation'])
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('number')
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = $orders->pluck('id')->all();
        $reported = ProductionLabourEntry::query()->whereIn('production_order_id', $orderIds)
            ->groupBy('production_order_operation_id')->selectRaw('production_order_operation_id, sum(minutes) as minutes')
            ->pluck('minutes', 'production_order_operation_id');
        $mine = WorkshopTimeEntry::query()->where('person_id', $person->id)->whereIn('production_order_id', $orderIds)->whereDate('worked_on', $today)
            ->groupBy('production_order_operation_id')->selectRaw('production_order_operation_id, sum(minutes) as minutes')
            ->pluck('minutes', 'production_order_operation_id');
        $flags = WorkshopWaitingFlag::query()->open()->whereIn('production_order_id', $orderIds)->get()->keyBy('production_order_operation_id');

        return array_values($orders->map(fn (ProductionOrder $order): array => [
            'id' => $order->id,
            'reference' => $order->reference(),
            'product' => $order->article->name ?? $order->name,
            'customer' => $order->salesDocument?->customer?->name,
            'quantity' => Quantity::format($order->quantity_milli).' '.($order->article?->stockUnitCode() ?? ''),
            'due_on' => $order->due_on?->toDateString(),
            'status' => $order->status->value,
            'operations' => $order->operations->map(fn (ProductionOrderOperation $operation): array => [
                'id' => $operation->id,
                'name' => $operation->operation->name ?? __('Arbetssteg'),
                'planned_minutes' => $operation->planned_minutes,
                'reported_minutes' => (int) ($reported[$operation->id] ?? 0),
                'my_minutes_today' => (int) ($mine[$operation->id] ?? 0),
                'waiting' => ($flag = $flags->get($operation->id)) === null ? null : [
                    'kind' => $flag->kind->value,
                    'label' => $flag->kind->label(),
                    'reason' => $flag->reason,
                    'since' => $flag->raised_at->toIso8601String(),
                ],
            ])->values()->all(),
        ])->all());
    }

    /**
     * The person's own reports for the last two weeks, newest first, with
     * whether each can still be corrected.
     *
     * @return list<array<string, mixed>>
     */
    public static function mine(WorkshopPerson $person): array
    {
        $entries = WorkshopTimeEntry::query()->where('person_id', $person->id)
            ->whereDate('worked_on', '>=', CarbonImmutable::today()->subDays(self::HISTORY_DAYS - 1))
            ->with(['order.article', 'orderOperation.operation'])
            ->orderByDesc('id')->get();

        $corrected = WorkshopTimeEntry::query()->whereIn('corrects_entry_id', $entries->pluck('id'))->pluck('corrects_entry_id')->flip();

        return array_values($entries->map(fn (WorkshopTimeEntry $entry): array => [
            'id' => $entry->id,
            'reference' => $entry->order?->reference() ?? '',
            'product' => $entry->order?->article->name ?? ($entry->order->name ?? ''),
            'operation' => $entry->orderOperation?->operation->name ?? __('Arbetssteg'),
            'minutes' => $entry->minutes,
            'worked_on' => $entry->worked_on->toDateString(),
            'entered_at' => $entry->entered_at->toIso8601String(),
            'is_correction' => $entry->corrects_entry_id !== null,
            'corrected' => $corrected->has($entry->id),
            'can_correct' => $entry->corrects_entry_id === null && ! $corrected->has($entry->id) && ($entry->order?->status->reserves() ?? false),
        ])->all());
    }
}
