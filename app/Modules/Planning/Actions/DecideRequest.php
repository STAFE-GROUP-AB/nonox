<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\Notifications\ShiftRequestNotice;
use App\Modules\Planning\Support\RequestChecks;
use App\Modules\Planning\Support\ScheduleNotifier;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A planner approves or rejects a request (PLANNING_MODULE.md §6); a swap
 * only once the colleague has accepted it (RequestShift::answer). Approval
 * re-runs the working-time checks on the result and returns the warnings
 * (they never block), checks that the shifts are still as the employee saw
 * them, and applies the change through SaveShift (validation, cost estimate,
 * audit). Because the planner has decided it, the changed shifts are
 * published at once and the people involved are told; other requests on the
 * same shifts are answered as no longer possible. The staff ledger is never
 * touched. Audited.
 */
final class DecideRequest
{
    use GuardsPlanning;

    public function __construct(private readonly SaveShift $save, private readonly RequestChecks $checks) {}

    /** @return list<array{message: string, source: string}> the warnings of the approved change */
    public function __invoke(User $user, Company $company, ShiftRequest $request, bool $approve, ?string $reason = null): array
    {
        $this->guard($user, $company);
        $reason = blank($reason) ? null : mb_substr(trim((string) $reason), 0, 500);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $request, $approve, $reason): array {
            $request = ShiftRequest::query()->with(['shift', 'counterpartShift', 'requester.user', 'counterpart.user'])->lockForUpdate()->findOrFail($request->id);

            if ($request->isAwaitingCounterpart()) {
                throw ValidationException::withMessages(['request' => __('Kollegan har inte svarat på bytet ännu.')]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages(['request' => __('Förfrågan är redan besvarad.')]);
            }

            if (! $approve) {
                $this->close($user, $company, $request, ShiftRequest::REJECTED, $reason);
                $this->notify($company, $user, $request->requester, ShiftRequestNotice::REJECTED, $request, [$request->shift], $reason);

                return [];
            }

            $this->ensureUnchanged($request);
            $warnings = $this->checks->for($company, collect([$request]))[$request->id] ?? [];
            $shift = $request->shift;
            $other = $request->counterpartShift;

            if ($request->kind === ShiftRequest::CLAIM) {
                $this->assign($user, $company, $shift, $request->requested_by, 'CLAIM');
            }

            if ($request->kind === ShiftRequest::DROP) {
                $this->assign($user, $company, $shift, null, null);
            }

            if ($request->kind === ShiftRequest::SWAP && $other instanceof Shift) {
                $this->assign($user, $company, $shift, $request->counterpart_employee_id, 'SWAP');
                $this->assign($user, $company, $other, $request->requested_by, 'SWAP');
            }

            $this->close($user, $company, $request, ShiftRequest::APPROVED, $reason, $warnings);

            $gained = match ($request->kind) {
                ShiftRequest::CLAIM => [$shift],
                ShiftRequest::SWAP => $other instanceof Shift ? [$other] : [],
                default => [$shift],
            };
            $this->notify($company, $user, $request->requester, ShiftRequestNotice::APPROVED, $request, $gained, $reason);

            if ($request->kind === ShiftRequest::SWAP && $request->counterpart instanceof Employee) {
                $this->notify($company, $user, $request->counterpart, ShiftRequestNotice::SWAPPED, $request, [$shift], null);
            }

            $this->closeOthers($user, $company, $request);

            return $warnings;
        }));
    }

    /** The shifts must still be published, not started, and held by the same people as when the employee asked. */
    private function ensureUnchanged(ShiftRequest $request): void
    {
        $now = CarbonImmutable::now();
        $shifts = array_filter([$request->shift, $request->counterpartShift]);

        foreach ($shifts as $shift) {
            if ($shift->status !== Shift::PUBLISHED || $shift->starts_at->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['request' => __('Passet har ändrats eller börjat sedan förfrågan skickades. Avslå förfrågan i stället.')]);
            }
        }

        $holder = match ($request->kind) {
            ShiftRequest::CLAIM => $request->shift->employee_id === null,
            ShiftRequest::DROP => $request->shift->employee_id === $request->requested_by,
            default => $request->shift->employee_id === $request->requested_by
                && $request->counterpartShift instanceof Shift
                && $request->counterpartShift->employee_id === $request->counterpart_employee_id,
        };

        if (! $holder) {
            throw ValidationException::withMessages(['request' => __('Passet har ändrats eller börjat sedan förfrågan skickades. Avslå förfrågan i stället.')]);
        }
    }

    /** Moves the shift to the employee (or makes it open) through SaveShift, then publishes it again. */
    private function assign(User $user, Company $company, Shift $shift, ?int $employeeId, ?string $source): void
    {
        ($this->save)($user, $company, [
            'employee_id' => $employeeId,
            'area_id' => $shift->area_id,
            'skill_id' => $shift->skill_id,
            'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at->toIso8601String(),
            'break_minutes' => $shift->break_minutes,
            'note' => $shift->note,
        ], $shift);

        $shift->forceFill([
            'status' => Shift::PUBLISHED,
            'published_at' => CarbonImmutable::now(),
            'published_by' => $user->id,
            'published_snapshot' => $shift->snapshot(),
            'source' => $source ?? $shift->source,
        ])->save();
    }

    /**
     * @param  list<array{message: string, source: string}>  $warnings
     */
    private function close(User $user, Company $company, ShiftRequest $request, string $status, ?string $reason, array $warnings = []): void
    {
        $request->forceFill([
            'status' => $status,
            'decided_by' => $user->id,
            'decided_at' => CarbonImmutable::now(),
            'decision_note' => $reason,
        ])->save();

        Audit::record($status === ShiftRequest::APPROVED ? 'planning.request_approved' : 'planning.request_rejected', $request, [
            'kind' => $request->kind,
            'shift_id' => $request->shift_id,
            'counterpart_shift_id' => $request->counterpart_shift_id,
            'employee_id' => $request->requested_by,
            'reason' => $reason,
            'warnings' => array_column($warnings, 'message'),
        ], $company, $user);
    }

    /** Requests on the same shifts can no longer be granted once one is. */
    private function closeOthers(User $user, Company $company, ShiftRequest $approved): void
    {
        $ids = array_values(array_filter([$approved->shift_id, $approved->counterpart_shift_id]));
        $others = ShiftRequest::query()
            ->with(['shift', 'requester.user'])
            ->whereIn('status', ShiftRequest::OPEN)
            ->whereKeyNot($approved->id)
            ->where(fn ($query) => $query->whereIn('shift_id', $ids)->orWhereIn('counterpart_shift_id', $ids))
            ->lockForUpdate()
            ->get();
        $reason = __('Passet har redan ändrats efter en annan förfrågan.');

        foreach ($others as $other) {
            $this->close($user, $company, $other, ShiftRequest::REJECTED, $reason);
            $this->notify($company, $user, $other->requester, ShiftRequestNotice::REJECTED, $other, [$other->shift], $reason);
        }
    }

    /** @param  list<Shift>  $shifts */
    private function notify(Company $company, User $actor, ?Employee $employee, string $kind, ShiftRequest $request, array $shifts, ?string $reason): void
    {
        if (! $employee instanceof Employee) {
            return;
        }

        $areas = Area::query()->pluck('name', 'id');
        $lines = array_map(fn (Shift $shift): array => ScheduleNotifier::line($shift, $areas), $shifts);

        ScheduleNotifier::deliver($employee->loadMissing('user'), new ShiftRequestNotice($kind, $request->kind, $company->id, (string) $company->name, $lines, $reason, (string) $actor->name));
    }
}
