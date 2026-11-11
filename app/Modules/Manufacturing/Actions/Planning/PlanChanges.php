<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\Overtime;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The planner's other changes (ADR-071 §13.5, §13.6): a manual priority,
 * a new estimate of the minutes an operation still needs (it takes longer,
 * or a part was scrapped), and overtime for a person. Each is audited and
 * starts a new plan; a longer estimate raises an OVERRUN or SCRAP deviation.
 */
class PlanChanges
{
    use GuardsManufacturing;

    public const int MAX_OVERTIME_MINUTES = 12 * 60;

    public function prioritise(User $user, Company $company, ProductionOrder $order, ?int $priority): ProductionOrder
    {
        $this->guard($user, $company, 'plan');

        if ($priority !== null && ($priority < 0 || $priority > 9999)) {
            throw ValidationException::withMessages(['priority' => __('Prioriteten ska vara ett tal mellan 0 och 9999, lägst först.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $order, $priority): ProductionOrder {
            $order = ProductionOrder::query()->findOrFail($order->id);
            $before = $order->plan_priority;
            $order->forceFill(['plan_priority' => $priority])->save();

            Audit::record('manufacturing.priority_changed', $order, ['before' => $before, 'after' => $priority], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Lock);

            return $order;
        });
    }

    /** The minutes an operation still needs, as estimated now; null goes back to the planned minutes less those reported. */
    public function remaining(User $user, Company $company, ProductionOrderOperation $operation, ?int $minutes, DeviationType $type = DeviationType::Overrun): ProductionOrderOperation
    {
        $this->guard($user, $company, 'plan');

        if ($minutes !== null && ($minutes < 0 || $minutes > 1_000_000)) {
            throw ValidationException::withMessages(['minutes' => __('Ange hur många minuter som återstår.')]);
        }

        if (! in_array($type, [DeviationType::Overrun, DeviationType::Scrap], true)) {
            $type = DeviationType::Overrun;
        }

        $operation = CurrentCompany::run($company, fn (): ProductionOrderOperation => DB::transaction(function () use ($user, $company, $operation, $minutes, $type): ProductionOrderOperation {
            $operation = ProductionOrderOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $before = $operation->remaining_minutes_override;
            $operation->forceFill(['remaining_minutes_override' => $minutes])->save();

            Audit::record('manufacturing.remaining_changed', $operation, ['production_order_id' => $operation->production_order_id, 'before' => $before, 'after' => $minutes, 'type' => $type->value], $company, $user);

            return $operation;
        }));

        PlanTriggers::deviation($company->id, $type, $operation, [
            'details' => ['production_order_id' => $operation->production_order_id, 'operation_id' => $operation->operation_id, 'remaining_minutes' => $minutes],
        ], PlanTrigger::Terminal);

        return $operation;
    }

    public function overtime(User $user, Company $company, int $employeeId, CarbonImmutable $start, CarbonImmutable $end): Overtime
    {
        $this->guard($user, $company, 'plan');

        $minutes = (int) $start->diffInMinutes($end, false);

        if ($minutes <= 0 || $minutes > self::MAX_OVERTIME_MINUTES) {
            throw ValidationException::withMessages(['overtime' => __('Övertiden ska sluta efter att den börjar och vara högst tolv timmar i följd.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $employeeId, $start, $end, $minutes): Overtime {
            $employee = Employee::query()->find($employeeId) ?? throw ValidationException::withMessages(['employee_id' => __('Välj en person.')]);

            $overtime = Overtime::create([
                'employee_id' => $employee->id,
                'starts_at' => $start->setTimezone(Clock::ZONE),
                'ends_at' => $end->setTimezone(Clock::ZONE),
                'created_by' => $user->id,
            ]);

            Audit::record('manufacturing.overtime_added', $overtime, ['employee_id' => $employee->id, 'starts_at' => $start->toIso8601String(), 'minutes' => $minutes], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Lock);

            return $overtime;
        });
    }
}
