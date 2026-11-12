<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production time reported at the workshop terminal (ADR-071 §7): a person,
 * an open order (released or started), one of its operations, and hours and
 * minutes for today. Each report writes two rows in one transaction: a
 * labour entry (source TERMINAL, priced at the operation's hourly cost as
 * it is now) that production order costing reads, and a time entry that
 * says who reported it, on which device and when. Both are append-only. A
 * correction is a new pair that cancels the first; the right time is then
 * reported again.
 *
 * This is production time. It is not the staff ledger's attendance and not
 * payroll time, and nothing here reads or writes either.
 *
 * The audit entry has ids, minutes and the day, never the person: a trail
 * that lasts for ever must not become a record of who works how fast.
 */
final class RecordTerminalTime
{
    /** One report is at most 16 hours: a typo such as 80 instead of 8 is caught. */
    public const int MAX_MINUTES = 960;

    /**
     * @param  int  $hours  whole hours, 0 to 16
     * @param  int  $minutes  whole minutes, 0 to 59
     */
    public function __invoke(WorkshopDevice $device, WorkshopPerson $person, int $operationId, int $hours, int $minutes): WorkshopTimeEntry
    {
        if ($hours < 0 || $minutes < 0 || $minutes > 59 || $hours * 60 + $minutes < 1 || $hours * 60 + $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['minutes' => __('Skriv en tid mellan 1 minut och 16 timmar.')]);
        }

        return DB::transaction(function () use ($device, $person, $operationId, $hours, $minutes): WorkshopTimeEntry {
            $operation = ProductionOrderOperation::query()->with('operation')->find($operationId);

            if (! $operation instanceof ProductionOrderOperation) {
                throw ValidationException::withMessages(['operation' => __('Arbetssteget finns inte.')]);
            }

            $order = $this->openOrder($operation->production_order_id);

            return $this->write($device, $person, $order, $operation, $hours * 60 + $minutes, CarbonImmutable::today(), null);
        });
    }

    /**
     * Cancels one of the person's own earlier reports. Only while the order
     * is still open, since a finished order has fixed its labour cost, and
     * only once per report. Corrections cannot be corrected.
     */
    public function correct(WorkshopDevice $device, WorkshopPerson $person, int $entryId): WorkshopTimeEntry
    {
        return DB::transaction(function () use ($device, $person, $entryId): WorkshopTimeEntry {
            $entry = WorkshopTimeEntry::query()->where('person_id', $person->id)->find($entryId);

            if (! $entry instanceof WorkshopTimeEntry || $entry->minutes < 0) {
                throw ValidationException::withMessages(['entry' => __('Den tiden går inte att rätta.')]);
            }

            $order = $this->openOrder($entry->production_order_id);

            if (WorkshopTimeEntry::query()->where('corrects_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['entry' => __('Den tiden är redan rättad.')]);
            }

            $operation = ProductionOrderOperation::query()->with('operation')->findOrFail($entry->production_order_operation_id);

            return $this->write($device, $person, $order, $operation, -$entry->minutes, CarbonImmutable::today(), $entry);
        });
    }

    /** The order, locked so that finishing it and reporting on it cannot cross; it must be released or started. */
    private function openOrder(int $orderId): ProductionOrder
    {
        $order = ProductionOrder::query()->whereKey($orderId)->lockForUpdate()->first();

        if (! $order instanceof ProductionOrder || ! $order->status->reserves()) {
            throw ValidationException::withMessages(['order' => __('Ordern är inte öppen för tid. Fråga arbetsledaren.')]);
        }

        return $order;
    }

    private function write(WorkshopDevice $device, WorkshopPerson $person, ProductionOrder $order, ProductionOrderOperation $operation, int $minutes, CarbonImmutable $day, ?WorkshopTimeEntry $cancels): WorkshopTimeEntry
    {
        $hourly = $cancels === null
            ? ($operation->operation instanceof Operation ? $operation->operation->hourly_cost_ore : 0)
            : (int) ProductionLabourEntry::query()->whereKey($cancels->production_labour_entry_id)->value('hourly_cost_ore');

        $cost = match (true) {
            $cancels !== null => -(int) ProductionLabourEntry::query()->whereKey($cancels->production_labour_entry_id)->value('cost_ore'),
            default => Operation::labourOre($minutes, $hourly),
        };

        $labour = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'production_order_operation_id' => $operation->id,
            'source' => LabourSource::Terminal,
            'minutes' => $minutes,
            'hourly_cost_ore' => $hourly,
            'cost_ore' => $cost,
            'worked_on' => $day->toDateString(),
            'created_by' => null,
        ]);

        $entry = WorkshopTimeEntry::create([
            'person_id' => $person->id,
            'device_id' => $device->id,
            'production_order_id' => $order->id,
            'production_order_operation_id' => $operation->id,
            'production_labour_entry_id' => $labour->id,
            'minutes' => $minutes,
            'worked_on' => $day->toDateString(),
            'corrects_entry_id' => $cancels?->id,
            'entered_at' => CarbonImmutable::now(),
        ]);

        if ($cancels === null) {
            app(WaitingFlags::class)->clearForOperation($operation->id, $device);
        }

        Audit::withoutRequest(fn () => Audit::record($cancels === null ? 'manufacturing.terminal_time_entered' : 'manufacturing.terminal_time_corrected', $order, [
            'production_order_operation_id' => $operation->id,
            'minutes' => $minutes,
            'worked_on' => $day->toDateString(),
            'device_id' => $device->id,
        ], $device->company_id));

        return $entry;
    }
}
