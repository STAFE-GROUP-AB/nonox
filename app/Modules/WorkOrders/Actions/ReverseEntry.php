<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\MaterialSource;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a time or material entry by a reversing entry with a reason, as
 * vouchers are corrected (ADR-074 §3). The original stays; both then count
 * as nothing. An invoiced entry cannot be reversed, only credited through a
 * credit invoice; an entry on a draft invoice is released by deleting the
 * draft first. Stock taken out comes back. A field worker may reverse
 * their own entries; a manager any.
 */
class ReverseEntry
{
    use GuardsWorkOrders;

    public function __construct(private readonly StockLedger $ledger) {}

    public function __invoke(User $user, Company $company, TimeEntry|MaterialEntry $entry, string $reason): TimeEntry|MaterialEntry
    {
        $workOrder = CurrentCompany::run($company, fn (): WorkOrder => WorkOrder::query()->findOrFail($entry->work_order_id));
        $this->guardReport($user, $company, $workOrder);

        if (! $user->hasTeamPermission($company, WorkOrdersModule::MANAGE) && $entry->user_id !== $user->id) {
            throw ValidationException::withMessages(['entry' => __('Du kan bara backa dina egna poster.')]);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Skriv varför posten backas.')]);
        }

        return DB::transaction(fn (): TimeEntry|MaterialEntry => CurrentCompany::run($company, function () use ($user, $company, $entry, $reason, $workOrder): TimeEntry|MaterialEntry {
            $entry = $entry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->isReversal() || $entry->isReversed()) {
                throw ValidationException::withMessages(['entry' => __('Posten är redan backad.')]);
            }

            if ($entry->invoice_id !== null) {
                throw ValidationException::withMessages(['entry' => __('Posten finns på en faktura. Ta bort fakturautkastet, eller kreditera fakturan, i stället.')]);
            }

            $reversal = $entry->replicate(['id', 'invoice_id', 'reversed_by_id', 'created_at', 'updated_at']);
            $reversal->forceFill([
                'reverses_id' => $entry->id,
                'reversal_reason' => mb_substr($reason, 0, 255),
                'user_id' => $user->id,
                'created_by' => $user->id,
                'entered_at' => CarbonImmutable::now(),
            ]);

            if ($reversal instanceof TimeEntry) {
                $reversal->forceFill(['minutes' => ! $entry instanceof TimeEntry || $entry->minutes === null ? null : -$entry->minutes, 'quantity_milli' => $entry->quantity_milli === null ? null : -$entry->quantity_milli]);
            } else {
                $reversal->forceFill(['quantity_milli' => -$entry->quantity_milli]);
            }

            $reversal->save();
            $entry->forceFill(['reversed_by_id' => $reversal->id])->save();

            if ($entry instanceof MaterialEntry && $entry->source === MaterialSource::Stock && $entry->article_id !== null && $entry->stock_location_id !== null) {
                $this->ledger->post($company, $user, MovementType::WorkOrderIssue, [[
                    'article_id' => $entry->article_id,
                    'location_id' => $entry->stock_location_id,
                    'quantity_milli' => $entry->quantity_milli,
                    'unit_cost_ore' => $entry->unit_cost_ore,
                ]], CarbonImmutable::today(), $reversal, __('Arbetsorder :number', ['number' => $workOrder->number]));
            }

            Audit::record('work_orders.entry_reversed', $reversal, ['work_order_id' => $workOrder->id, 'reverses_id' => $entry->id, 'kind' => $entry instanceof TimeEntry ? 'time' : 'material'], $company, $user);

            return $reversal;
        }));
    }
}
