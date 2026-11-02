<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\PlanningModule;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Models\TimeProposal;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Audit\Audit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Föreslå tidrapport": hours per day from the ledger's closed shifts, for
 * people linked to a payroll employee. A manager approves the proposal,
 * which writes approved time reports with the hourly pay code; or rejects
 * it. The ledger itself is never touched and stays separate from payroll.
 * Shifts without a check-out are left out and must be corrected first.
 * While Planering is on, its attest replaces proposing; proposals already
 * made can still be approved or rejected.
 */
final class TimeProposals
{
    /**
     * @return list<TimeProposal> the proposals created, one per linked person with hours
     */
    public function propose(User $user, Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->authorize($user, $company);

        // With Planering on, time is attested against the schedule there; one source, so no day is reported twice.
        if (self::replacedByPlanning($company)) {
            throw ValidationException::withMessages(['period' => __('Planering är på: tiden attesteras mot schemat under Planering → Tid att attestera och skickas till lönen därifrån.')]);
        }

        if ($to < $from || $from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['period' => __('Välj en period på högst två månader.')]);
        }

        $people = Person::query()->whereNotNull('employee_id')->get()->keyBy('id');
        $hours = [];

        foreach (LedgerQuery::shifts(null, $from->startOfDay(), $to->endOfDay()) as $shift) {
            /** @var Shift $shift */
            $person = $shift->personId === null ? null : $people->get($shift->personId);

            if ($person === null || $shift->start === null || $shift->end === null || $shift->start < $from->startOfDay() || $shift->start > $to->endOfDay()) {
                continue;
            }

            $day = $shift->start->toDateString();
            $hours[(int) $person->employee_id][$day] = round(($hours[(int) $person->employee_id][$day] ?? 0) + (float) $shift->hours(), 2);
        }

        $covered = self::coveredDays(array_keys($hours), $from, $to);

        return DB::transaction(function () use ($user, $company, $from, $to, $hours, $covered): array {
            $created = [];

            foreach ($hours as $employeeId => $days) {
                // A day already proposed, approved or reported is never proposed again, so it cannot be paid twice.
                $days = array_diff_key($days, $covered[$employeeId] ?? []);

                if ($days === []) {
                    continue;
                }

                ksort($days);
                $proposal = TimeProposal::create([
                    'employee_id' => $employeeId,
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'days' => $days,
                    'status' => TimeProposal::PROPOSED,
                    'proposed_by' => $user->id,
                ]);
                $created[] = $proposal;
            }

            Audit::record('staff_ledger.time_proposed', null, ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'proposals' => count($created)], $company, $user);

            return $created;
        });
    }

    public function approve(User $user, Company $company, TimeProposal $proposal): int
    {
        $this->authorize($user, $company);
        // Approving writes time reports for payroll, so it takes the payroll approver's permission too.
        abort_unless($user->hasTeamPermission($company, 'payroll:approve'), 403);
        $this->ensurePending($proposal);

        $payCode = PayCode::query()->where('code', 'TIMLON')->first();

        if (! $payCode instanceof PayCode) {
            throw ValidationException::withMessages(['proposal' => __('Lönearten Timlön saknas i lönen.')]);
        }

        $covered = self::coveredDays([$proposal->employee_id], CarbonImmutable::parse((string) array_key_first($proposal->days)), CarbonImmutable::parse((string) array_key_last($proposal->days)), $proposal->id, pending: false);

        if (array_intersect_key($proposal->days, $covered[$proposal->employee_id] ?? []) !== []) {
            throw ValidationException::withMessages(['proposal' => __('Några av dagarna finns redan i en annan tidrapport. Avslå förslaget och föreslå perioden igen.')]);
        }

        return DB::transaction(function () use ($user, $company, $proposal, $payCode): int {
            foreach ($proposal->days as $date => $hours) {
                TimeReport::create([
                    'employee_id' => $proposal->employee_id,
                    'date' => $date,
                    'hours' => $hours,
                    'pay_code_id' => $payCode->id,
                    'status' => 'APPROVED',
                ]);
            }

            $proposal->forceFill(['status' => TimeProposal::APPROVED, 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()])->save();
            Audit::record('staff_ledger.time_approved', $proposal, ['days' => count($proposal->days), 'hours' => $proposal->totalHours()], $company, $user);

            return count($proposal->days);
        });
    }

    public function reject(User $user, Company $company, TimeProposal $proposal): void
    {
        $this->authorize($user, $company);
        $this->ensurePending($proposal);

        $proposal->forceFill(['status' => TimeProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()])->save();
        Audit::record('staff_ledger.time_rejected', $proposal, [], $company, $user);
    }

    /**
     * Hours in proposals not yet approved or rejected, on days between two
     * dates. Payroll shows them as waiting for approval on the run page.
     */
    public static function pendingHours(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $hours = BigDecimal::zero();
        $proposals = TimeProposal::query()
            ->where('status', TimeProposal::PROPOSED)
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->get(['id', 'days']);

        foreach ($proposals as $proposal) {
            foreach ($proposal->days as $date => $dayHours) {
                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $hours = $hours->plus(BigDecimal::of((string) $dayHours));
                }
            }
        }

        return (string) $hours;
    }

    /** Planering's attest replaces the proposals while that module is on (PLANNING_MODULE.md §6). */
    public static function replacedByPlanning(Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(PlanningModule::KEY, $company);
    }

    private function ensurePending(TimeProposal $proposal): void
    {
        if ($proposal->status !== TimeProposal::PROPOSED) {
            throw ValidationException::withMessages(['proposal' => __('Förslaget är redan behandlat.')]);
        }
    }

    private function authorize(User $user, Company $company): void
    {
        $modules = app(ModuleManager::class);
        $modules->ensureEnabled(StaffLedgerModule::KEY, $company);
        $modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'staff-ledger:manage')) {
            abort(403);
        }
    }

    /**
     * Days per employee that are already in an approved proposal (or, with
     * $pending, a pending one) or in a time report that is not rejected.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, true>>
     */
    private static function coveredDays(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptProposal = null, bool $pending = true): array
    {
        $covered = [];

        if ($employeeIds === []) {
            return $covered;
        }

        $proposals = TimeProposal::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', $pending ? [TimeProposal::PROPOSED, TimeProposal::APPROVED] : [TimeProposal::APPROVED])
            ->when($exceptProposal !== null, fn ($query) => $query->whereKeyNot($exceptProposal))
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->get(['employee_id', 'days']);

        foreach ($proposals as $proposal) {
            foreach (array_keys($proposal->days) as $day) {
                $covered[(int) $proposal->employee_id][(string) $day] = true;
            }
        }

        $reports = TimeReport::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '<>', 'REJECTED')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['employee_id', 'date']);

        foreach ($reports as $report) {
            $covered[(int) $report->employee_id][CarbonImmutable::parse($report->date)->toDateString()] = true;
        }

        return $covered;
    }
}
