<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\Notifications\ShiftRequestNotice;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\ScheduleNotifier;
use App\Modules\Planning\Support\SelfService;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An employee's request from Min sida (PLANNING_MODULE.md §6): take an open
 * shift, swap one of their shifts with a colleague's, or give one up. Only
 * published shifts that have not started, and only while the company allows
 * it: claims with allow_open_shift_claims, swaps and drops with allow_swaps.
 * A swap first goes to the colleague, who accepts or declines in Min sida
 * (answer); only an accepted swap reaches a planner. Nothing in the
 * schedule changes until a planner approves (DecideRequest);
 * the staff ledger is never touched. Audited.
 */
final class RequestShift
{
    public function __construct(private readonly SelfService $self) {}

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input): ShiftRequest
    {
        $employee = $this->self->employee($user, $company);

        $validated = Validator::make($input, [
            'kind' => ['required', Rule::in(ShiftRequest::KINDS)],
            'shift_id' => ['required', 'uuid'],
            'counterpart_shift_id' => ['exclude_unless:kind,SWAP', 'required', 'uuid', 'different:shift_id'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'counterpart_shift_id.required' => __('Välj passet du vill byta till.'),
        ], [
            'shift_id' => __('Pass'),
            'counterpart_shift_id' => __('Kollegans pass'),
            'note' => __('Meddelande'),
        ])->validate();

        $kind = (string) $validated['kind'];
        $settings = $this->self->settings($company);

        if ($kind === ShiftRequest::CLAIM && ! PlanningSettings::bool($settings, 'allow_open_shift_claims')) {
            throw ValidationException::withMessages(['kind' => __('Din arbetsgivare tar inte emot förfrågningar om öppna pass här.')]);
        }

        if ($kind !== ShiftRequest::CLAIM && ! PlanningSettings::bool($settings, 'allow_swaps')) {
            throw ValidationException::withMessages(['kind' => __('Din arbetsgivare tar inte emot byten här. Prata med din chef.')]);
        }

        return CurrentCompany::run($company, fn (): ShiftRequest => DB::transaction(function () use ($user, $company, $employee, $validated, $kind): ShiftRequest {
            $shift = $this->bookable((string) $validated['shift_id']);
            $counterpart = $kind === ShiftRequest::SWAP ? $this->bookable((string) $validated['counterpart_shift_id']) : null;

            if ($kind === ShiftRequest::CLAIM) {
                $this->ensureClaimable($employee, $shift);
            }

            if ($kind !== ShiftRequest::CLAIM && $shift->employee_id !== $employee->id) {
                throw ValidationException::withMessages(['shift_id' => __('Du kan bara byta eller lämna dina egna pass.')]);
            }

            if ($counterpart !== null && ($counterpart->employee_id === null || $counterpart->employee_id === $employee->id)) {
                throw ValidationException::withMessages(['counterpart_shift_id' => __('Välj ett pass som en kollega har.')]);
            }

            if (ShiftRequest::query()->where('shift_id', $shift->id)->where('requested_by', $employee->id)->whereIn('status', ShiftRequest::OPEN)->exists()) {
                throw ValidationException::withMessages(['shift_id' => __('Du har redan en förfrågan om passet som väntar på svar.')]);
            }

            $request = ShiftRequest::create([
                'kind' => $kind,
                'shift_id' => $shift->id,
                'requested_by' => $employee->id,
                'counterpart_employee_id' => $counterpart?->employee_id,
                'counterpart_shift_id' => $counterpart?->id,
                // A swap asks the colleague before it reaches a planner.
                'status' => $kind === ShiftRequest::SWAP ? ShiftRequest::AWAITING_COUNTERPART : ShiftRequest::PENDING,
                'note' => blank($validated['note'] ?? null) ? null : $validated['note'],
                'created_by' => $user->id,
            ]);

            Audit::record('planning.request_created', $request, [
                'kind' => $kind,
                'shift_id' => $shift->id,
                'employee_id' => $employee->id,
                'counterpart_shift_id' => $counterpart?->id,
            ], $company, $user);

            if ($counterpart !== null) {
                $this->tell($company, $request, $counterpart->employee_id, ShiftRequestNotice::ASKED, [$counterpart, $shift], "{$employee->first_name} {$employee->last_name}");
            }

            return $request;
        }));
    }

    /**
     * The colleague named in a swap accepts it, so it goes to a planner
     * (PENDING), or declines it, which closes it (DECLINED). Only that
     * colleague may answer; anyone else gets 404. The employee asking is
     * told either way. Nothing in the schedule changes here. Audited.
     */
    public function answer(User $user, Company $company, ShiftRequest $request, bool $accept, ?string $note = null): ShiftRequest
    {
        $employee = $this->self->employee($user, $company);

        if ($request->counterpart_employee_id !== $employee->id || Gate::forUser($user)->denies('answer', $request)) {
            throw (new ModelNotFoundException)->setModel(ShiftRequest::class, [$request->id]);
        }

        if ($accept && ! PlanningSettings::bool($this->self->settings($company), 'allow_swaps')) {
            throw ValidationException::withMessages(['request' => __('Din arbetsgivare tar inte emot byten här. Prata med din chef.')]);
        }

        $note = blank($note) ? null : mb_substr(trim((string) $note), 0, 500);

        return CurrentCompany::run($company, fn (): ShiftRequest => DB::transaction(function () use ($user, $company, $employee, $request, $accept, $note): ShiftRequest {
            $locked = ShiftRequest::query()->with(['shift', 'counterpartShift'])->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isAwaitingCounterpart()) {
                throw ValidationException::withMessages(['request' => __('Förfrågan är redan besvarad.')]);
            }

            if ($accept) {
                $this->ensureSwapStillPossible($locked);
            }

            $locked->forceFill([
                'status' => $accept ? ShiftRequest::PENDING : ShiftRequest::DECLINED,
                'counterpart_answered_at' => CarbonImmutable::now(),
                'counterpart_answered_by' => $user->id,
                'decision_note' => $accept ? null : $note,
            ])->save();

            Audit::record($accept ? 'planning.request_counterpart_accepted' : 'planning.request_counterpart_declined', $locked, [
                'kind' => $locked->kind,
                'shift_id' => $locked->shift_id,
                'counterpart_shift_id' => $locked->counterpart_shift_id,
                'employee_id' => $employee->id,
                'note' => $note,
            ], $company, $user);

            $this->tell($company, $locked, $locked->requested_by, $accept ? ShiftRequestNotice::ACCEPTED : ShiftRequestNotice::DECLINED, [$locked->shift], "{$employee->first_name} {$employee->last_name}", $note);

            return $locked;
        }));
    }

    /** The employee takes back a request that is still waiting. */
    public function withdraw(User $user, Company $company, ShiftRequest $request): void
    {
        $employee = $this->self->employee($user, $company);

        if ($request->requested_by !== $employee->id || Gate::forUser($user)->denies('own', $request)) {
            throw (new ModelNotFoundException)->setModel(ShiftRequest::class, [$request->id]);
        }

        CurrentCompany::run($company, function () use ($user, $company, $request): void {
            DB::transaction(function () use ($user, $company, $request): void {
                $locked = ShiftRequest::query()->lockForUpdate()->findOrFail($request->id);

                if (! $locked->isOpen()) {
                    throw ValidationException::withMessages(['request' => __('Förfrågan är redan besvarad.')]);
                }

                $locked->forceFill(['status' => ShiftRequest::WITHDRAWN])->save();
                Audit::record('planning.request_withdrawn', $locked, ['kind' => $locked->kind, 'shift_id' => $locked->shift_id], $company, $user);
            });
        });
    }

    /** Both shifts still published, not started and held by the same two people. */
    private function ensureSwapStillPossible(ShiftRequest $request): void
    {
        $now = CarbonImmutable::now();
        $other = $request->counterpartShift;

        foreach ([$request->shift, $other] as $shift) {
            if (! $shift instanceof Shift || $shift->status !== Shift::PUBLISHED || $shift->starts_at->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['request' => __('Passen har ändrats eller börjat sedan förfrågan skickades.')]);
            }
        }

        if ($request->shift->employee_id !== $request->requested_by || $other?->employee_id !== $request->counterpart_employee_id) {
            throw ValidationException::withMessages(['request' => __('Passen har ändrats eller börjat sedan förfrågan skickades.')]);
        }
    }

    /** @param  list<Shift>  $shifts */
    private function tell(Company $company, ShiftRequest $request, ?int $employeeId, string $kind, array $shifts, string $actor, ?string $reason = null): void
    {
        $employee = $employeeId === null ? null : Employee::query()->with('user')->find($employeeId);

        if (! $employee instanceof Employee) {
            return;
        }

        $areas = Area::query()->pluck('name', 'id');
        $lines = array_map(fn (Shift $shift): array => ScheduleNotifier::line($shift, $areas), $shifts);

        ScheduleNotifier::deliver($employee, new ShiftRequestNotice($kind, $request->kind, $company->id, (string) $company->name, $lines, $reason, $actor));
    }

    /** A published shift that has not started; anything else is not offered to staff. */
    private function bookable(string $id): Shift
    {
        $shift = Shift::query()->lockForUpdate()->find($id);

        if (! $shift instanceof Shift || $shift->status !== Shift::PUBLISHED) {
            throw ValidationException::withMessages(['shift_id' => __('Passet finns inte i det publicerade schemat.')]);
        }

        if ($shift->starts_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['shift_id' => __('Passet har redan börjat.')]);
        }

        return $shift;
    }

    private function ensureClaimable(Employee $employee, Shift $shift): void
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift_id' => __('Passet är inte längre öppet.')]);
        }

        if ($shift->skill_id !== null && ! EmployeeSkill::query()->where('employee_id', $employee->id)->where('skill_id', $shift->skill_id)->exists()) {
            throw ValidationException::withMessages(['shift_id' => __('Passet kräver en kompetens som inte är registrerad på dig.')]);
        }
    }
}
