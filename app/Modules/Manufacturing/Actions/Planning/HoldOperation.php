<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\LockKind;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\ProductionPlanLock;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Models\ProductionSkill;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A planner's hold on an operation (ADR-071 §13.6): lock it where the plan
 * put it (person, workstation, start), move it to another qualified person,
 * split it with a second one, or let go. The scheduler never moves locked
 * work; if the person becomes unable to do it, the next plan raises a
 * LOCK_BROKEN deviation instead of a silent move.
 */
class HoldOperation
{
    use GuardsManufacturing;

    public function lock(User $user, Company $company, ProductionPlanSlot $slot): ProductionPlanLock
    {
        $this->guard($user, $company, 'plan');

        return CurrentCompany::run($company, fn (): ProductionPlanLock => DB::transaction(function () use ($user, $company, $slot): ProductionPlanLock {
            $slot = ProductionPlanSlot::query()->findOrFail($slot->id);

            if ($slot->employee_id === null) {
                throw ValidationException::withMessages(['slot' => __('Arbetet har ingen person att låsa.')]);
            }

            $lock = $this->write($slot->production_order_operation_id, LockKind::Lock, [
                'employee_id' => $slot->employee_id,
                'workstation_id' => $slot->workstation_id,
                'starts_at' => $slot->starts_at,
                'minutes' => null,
                'run_id' => $slot->run_id,
                'created_by' => $user->id,
            ]);

            Audit::record('manufacturing.plan_locked', $lock, ['production_order_operation_id' => $lock->production_order_operation_id, 'employee_id' => $lock->employee_id, 'workstation_id' => $lock->workstation_id, 'starts_at' => $lock->starts_at?->toIso8601String()], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Lock);

            return $lock;
        }));
    }

    /** Moves an operation to another person who has the skill it needs. */
    public function assign(User $user, Company $company, ProductionOrderOperation $operation, int $employeeId): ProductionPlanLock
    {
        $this->guard($user, $company, 'plan');

        return CurrentCompany::run($company, fn (): ProductionPlanLock => DB::transaction(function () use ($user, $company, $operation, $employeeId): ProductionPlanLock {
            $operation = ProductionOrderOperation::query()->findOrFail($operation->id);
            $this->ensureQualified($operation, $employeeId);

            ProductionPlanLock::query()->where('production_order_operation_id', $operation->id)->where('kind', LockKind::Lock->value)->delete();
            $lock = $this->write($operation->id, LockKind::Assign, ['employee_id' => $employeeId, 'workstation_id' => null, 'starts_at' => null, 'minutes' => null, 'created_by' => $user->id]);

            Audit::record('manufacturing.operation_moved', $lock, ['production_order_operation_id' => $operation->id, 'employee_id' => $employeeId], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Lock);

            return $lock;
        }));
    }

    /** Gives some of an operation's minutes to a second qualified person, worked in parallel. */
    public function split(User $user, Company $company, ProductionOrderOperation $operation, int $employeeId, int $minutes): ProductionPlanLock
    {
        $this->guard($user, $company, 'plan');

        if ($minutes <= 0) {
            throw ValidationException::withMessages(['minutes' => __('Ange hur många minuter den andra personen ska ta.')]);
        }

        return CurrentCompany::run($company, fn (): ProductionPlanLock => DB::transaction(function () use ($user, $company, $operation, $employeeId, $minutes): ProductionPlanLock {
            $operation = ProductionOrderOperation::query()->findOrFail($operation->id);
            $this->ensureQualified($operation, $employeeId);

            $lock = $this->write($operation->id, LockKind::Split, ['employee_id' => $employeeId, 'workstation_id' => null, 'starts_at' => null, 'minutes' => $minutes, 'created_by' => $user->id]);

            Audit::record('manufacturing.operation_split', $lock, ['production_order_operation_id' => $operation->id, 'employee_id' => $employeeId, 'minutes' => $minutes], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Lock);

            return $lock;
        }));
    }

    public function release(User $user, Company $company, ProductionPlanLock $lock): void
    {
        $this->guard($user, $company, 'plan');

        CurrentCompany::run($company, function () use ($user, $company, $lock): void {
            $lock = ProductionPlanLock::query()->findOrFail($lock->id);
            Audit::record('manufacturing.plan_unlocked', $lock, ['production_order_operation_id' => $lock->production_order_operation_id, 'kind' => $lock->kind->value], $company, $user);
            $lock->delete();
            PlanTriggers::recompute($company->id, PlanTrigger::Lock);
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(int $orderOperationId, LockKind $kind, array $attributes): ProductionPlanLock
    {
        $lock = ProductionPlanLock::query()->firstOrNew(['production_order_operation_id' => $orderOperationId, 'kind' => $kind->value]);
        $lock->fill($attributes)->save();

        return $lock;
    }

    private function ensureQualified(ProductionOrderOperation $operation, int $employeeId): void
    {
        $employee = Employee::query()->find($employeeId) ?? throw ValidationException::withMessages(['employee_id' => __('Välj en person.')]);
        $skill = Operation::query()->whereKey($operation->operation_id)->value('required_skill_id');

        if ($skill !== null && ! ProductionSkill::query()->whereKey($skill)->whereHas('employees', fn ($query) => $query->whereKey($employee->id))->exists()) {
            throw ValidationException::withMessages(['employee_id' => __('Personen har inte kompetensen som arbetssteget kräver.')]);
        }
    }
}
