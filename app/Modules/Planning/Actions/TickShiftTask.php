<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftTask;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\SelfService;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ticks a task on a shift off, or takes the tick back (ADR-037 gap 4). Two
 * people may: the employee the shift was published to (in Min sida) and a
 * planner (on the schedule). A tick can be made from the start of the shift's
 * day until the end of the day after the shift ends, and undone only on the
 * day it was made. The tick holds the time and the user and is shown without
 * a name; the audit log holds ids and who ticked (employee or planner), never
 * the task text. A work aid, not an egenkontroll or a ledger record.
 */
final class TickShiftTask
{
    public const string BY_EMPLOYEE = 'EMPLOYEE';

    public const string BY_PLANNER = 'PLANNER';

    public function __construct(private readonly ModuleManager $modules, private readonly SelfService $self) {}

    public function __invoke(User $user, Company $company, ShiftTask $task, bool $done): ShiftTask
    {
        $this->modules->ensureEnabled(PlanningModule::KEY, $company);

        return DB::transaction(function () use ($user, $company, $task, $done): ShiftTask {
            $task = ShiftTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $shift = Shift::query()->findOrFail($task->shift_id);
            $by = $this->actor($user, $company, $shift);

            if ($shift->status === Shift::CANCELLED) {
                throw ValidationException::withMessages(['task' => __('Passet är inställt.')]);
            }

            $now = CarbonImmutable::now(LocalTimestamp::ZONE);
            $shown = $by === self::BY_EMPLOYEE ? ($shift->published_snapshot ?? $shift->snapshot()) : $shift->snapshot();
            $start = CarbonImmutable::parse((string) $shown['starts_at'])->setTimezone(LocalTimestamp::ZONE);
            $end = CarbonImmutable::parse((string) $shown['ends_at'])->setTimezone(LocalTimestamp::ZONE);

            if ($now->lessThan($start->startOfDay()) || $now->greaterThan($end->addDay()->endOfDay())) {
                throw ValidationException::withMessages(['task' => __('Uppgiften kan bockas av från passets dag och till dagen efter att passet slutat.')]);
            }

            if ($done === $task->isDone()) {
                return $task;
            }

            if (! $done && $task->done_at?->toDateString() !== $now->toDateString()) {
                throw ValidationException::withMessages(['task' => __('En avbockad uppgift kan bara ångras samma dag som den bockades av.')]);
            }

            $task->forceFill($done ? ['done_at' => $now, 'done_by' => $user->id] : ['done_at' => null, 'done_by' => null])->save();

            Audit::record($done ? 'planning.shift_task_done' : 'planning.shift_task_undone', $task, ['shift_id' => $shift->id, 'by' => $by], $company, $user);

            return $task;
        });
    }

    /** @return self::BY_* */
    private function actor(User $user, Company $company, Shift $shift): string
    {
        if (Gate::forUser($user)->allows('manage', [Shift::class, $company])) {
            return self::BY_PLANNER;
        }

        $employee = $this->self->employee($user, $company);
        $shown = $shift->published_snapshot;

        if ($shown === null || (int) ($shown['employee_id'] ?? 0) !== $employee->id) {
            throw new AuthorizationException;
        }

        return self::BY_EMPLOYEE;
    }
}
