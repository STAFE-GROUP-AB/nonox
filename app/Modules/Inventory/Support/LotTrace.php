<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use Carbon\CarbonImmutable;

/**
 * The trace of one batch or serial number, both ways (ADR-081 E12): where it
 * came from (receipt, supplier, production) and where it went (pick lists
 * with their order or invoice and customer, sales through the till, scrap,
 * issues to production), the customers it reached as a recall list, and
 * what is left where. Reads the movement ledger inside the current company
 * with a fixed number of queries, whatever the length of the history.
 *
 * NONOX offers the data; it does not certify compliance with any duty to
 * trace goods (TODO(legal-verify): EU 178/2002 art. 18 for food).
 */
final class LotTrace
{
    /**
     * @return array{
     *     lot: StockLot,
     *     in: list<array{date: CarbonImmutable, quantity_milli: int, type: string, label: string, supplier: ?string, location: string}>,
     *     out: list<array{date: CarbonImmutable, quantity_milli: int, type: string, label: string, customer: ?string, source: ?string, location: string}>,
     *     customers: list<array{name: string, quantity_milli: int, last_on: CarbonImmutable}>,
     *     on_hand: list<array{location: string, quantity_milli: int}>
     * }
     */
    public static function of(StockLot $lot): array
    {
        $lot->loadMissing(['article:id,number,name,unit', 'supplier:id,name']);
        $movements = StockMovement::query()->where('lot_id', $lot->id)->with('location:id,full_code')->orderBy('occurred_on')->orderBy('id')->get();

        $receipts = StockReceipt::query()->with('supplier:id,name')
            ->whereKey($movements->where('source_type', (new StockReceipt)->getMorphClass())->pluck('source_id')->filter()->unique()->all())->get()->keyBy('id');
        $pickLists = PickList::query()->with('customer:id,name')
            ->whereKey($movements->where('source_type', (new PickList)->getMorphClass())->pluck('source_id')->filter()->unique()->all())->get()->keyBy('id');

        $in = [];
        $out = [];
        $customers = [];

        foreach ($movements as $movement) {
            $location = (string) $movement->location?->full_code;

            if ($movement->quantity_milli > 0) {
                $receipt = $movement->source_type === (new StockReceipt)->getMorphClass() ? $receipts->get((int) $movement->source_id) : null;
                $in[] = [
                    'date' => $movement->occurred_on,
                    'quantity_milli' => $movement->quantity_milli,
                    'type' => $movement->type->label(),
                    'label' => $receipt instanceof StockReceipt ? (string) $receipt->number : (string) $movement->note,
                    'supplier' => $receipt?->supplier_id === null ? $lot->supplier?->name : $receipt->supplier?->name,
                    'location' => $location,
                ];

                continue;
            }

            $pick = $movement->source_type === (new PickList)->getMorphClass() ? $pickLists->get((int) $movement->source_id) : null;
            $customer = $pick?->customer?->name;
            $out[] = [
                'date' => $movement->occurred_on,
                'quantity_milli' => -$movement->quantity_milli,
                'type' => $movement->type->label(),
                'label' => $pick instanceof PickList ? (string) __('Plocklista :number', ['number' => $pick->number]) : (string) $movement->note,
                'customer' => $customer,
                'source' => $pick?->source_label,
                'location' => $location,
            ];

            if ($customer !== null) {
                $customers[$customer] ??= ['name' => $customer, 'quantity_milli' => 0, 'last_on' => $movement->occurred_on];
                $customers[$customer]['quantity_milli'] += -$movement->quantity_milli;
                $customers[$customer]['last_on'] = $movement->occurred_on;
            }
        }

        $onHand = StockLotLevel::query()->where('lot_id', $lot->id)->where('quantity_milli', '>', 0)->with('location:id,full_code')->get()
            ->map(fn (StockLotLevel $level): array => ['location' => (string) $level->location?->full_code, 'quantity_milli' => $level->quantity_milli])->all();

        return ['lot' => $lot, 'in' => $in, 'out' => $out, 'customers' => array_values($customers), 'on_hand' => array_values($onHand)];
    }
}
