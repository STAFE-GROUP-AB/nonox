<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records billable time on a work order (ADR-074 §3): hours for work and
 * machines, trips or km for travel. Billable time, not pay time: it never
 * becomes a payroll time report by itself. The rate comes from the work
 * order; only a manager may set another rate or mark time as not billable.
 * An entry with a client-made id is stored once (one request per action,
 * safe to repeat).
 */
class RecordTime
{
    use GuardsWorkOrders;

    /**
     * @param  array<string, mixed>  $input  labour_kind_id, work_date, hours ("1,5" or "1:30") or quantity (trips/km),
     *                                       change_order_id, note, billable and rate (managers only), source, id (uuid, optional)
     */
    public function __invoke(User $user, Company $company, WorkOrder $workOrder, array $input): TimeEntry
    {
        $this->guardReport($user, $company, $workOrder);

        $validated = Validator::make($input, [
            'id' => ['nullable', 'uuid'],
            'labour_kind_id' => ['required', 'integer'],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today()->addDay()->toDateString()],
            'hours' => ['nullable', 'string', 'max:10'],
            'quantity' => ['nullable', 'string', 'max:12'],
            'change_order_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'billable' => ['nullable', 'boolean'],
            'rate' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', Rule::in(TimeEntry::SOURCES)],
        ], [
            'labour_kind_id.required' => __('Välj typ av arbete.'),
            'work_date.before_or_equal' => __('Tid kan inte registreras framåt i tiden.'),
        ], [
            'work_date' => __('datum'),
        ])->validate();

        $manager = $user->hasTeamPermission($company, WorkOrdersModule::MANAGE);

        return DB::transaction(fn (): TimeEntry => CurrentCompany::run($company, function () use ($validated, $user, $company, $workOrder, $manager): TimeEntry {
            if (isset($validated['id']) && ($existing = TimeEntry::query()->find($validated['id'])) instanceof TimeEntry) {
                return $existing;
            }

            $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! $workOrder->status->acceptsReports()) {
                throw ValidationException::withMessages(['work_order' => __('Arbetsordern är :status. Tid kan bara registreras när den är planerad eller pågår.', ['status' => mb_strtolower($workOrder->status->label())])]);
            }

            $kind = LabourKind::query()->where('is_active', true)->find((int) $validated['labour_kind_id'])
                ?? throw ValidationException::withMessages(['labour_kind_id' => __('Välj typ av arbete.')]);

            $minutes = null;
            $quantity = null;

            if ($kind->unit->isTime()) {
                $minutes = Input::minutes($validated['hours'] ?? null, 'hours');

                if ($minutes === null || $minutes <= 0 || $minutes > 24 * 60) {
                    throw ValidationException::withMessages(['hours' => __('Skriv tiden i timmar, mellan 0 och 24, till exempel 1,5 eller 1:30.')]);
                }
            } else {
                $quantity = Input::milli($validated['quantity'] ?? null, 'quantity', __('Skriv antalet, till exempel 1 eller 12,5.'));

                if ($quantity === null || $quantity <= 0) {
                    throw ValidationException::withMessages(['quantity' => __('Skriv antalet :unit, till exempel 1.', ['unit' => $kind->unit->label()])]);
                }
            }

            $changeOrder = $this->changeOrder($workOrder, $validated['change_order_id'] ?? null);
            $rate = $manager && filled($validated['rate'] ?? null) ? (int) Input::money($validated['rate'], 'rate') : $workOrder->rateFor($kind);

            $entry = TimeEntry::create([
                'id' => $validated['id'] ?? null,
                'work_order_id' => $workOrder->id,
                'change_order_id' => $changeOrder?->id,
                'user_id' => $user->id,
                'labour_kind_id' => $kind->id,
                'work_date' => $validated['work_date'],
                'minutes' => $minutes,
                'quantity_milli' => $quantity,
                'billable' => $manager ? (bool) ($validated['billable'] ?? true) : true,
                'rate_ore' => $rate,
                'note' => $validated['note'] ?? null,
                'source' => $validated['source'] ?? 'WEB',
                'entered_at' => CarbonImmutable::now(),
                'created_by' => $user->id,
            ]);

            if ($workOrder->status === WorkOrderStatus::Planned) {
                $workOrder->forceFill(['status' => WorkOrderStatus::InProgress])->save();
            }

            Audit::record('work_orders.time_recorded', $entry, ['work_order_id' => $workOrder->id, 'change_order_id' => $changeOrder?->id, 'labour_kind_id' => $kind->id, 'minutes' => $minutes, 'quantity_milli' => $quantity], $company, $user);

            return $entry;
        }));
    }

    private function changeOrder(WorkOrder $workOrder, mixed $id): ?ChangeOrder
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ChangeOrder::query()->where('work_order_id', $workOrder->id)->find((int) $id)
            ?? throw ValidationException::withMessages(['change_order_id' => __('ÄTA:t finns inte på arbetsordern.')]);
    }
}
