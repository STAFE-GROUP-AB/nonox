<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\CompTimeLedger;
use App\Support\Audit\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Comp time (komptid, gap G8): leave taken, a payout or an adjustment of an
 * employee's balance, registered by payroll staff; and the comp time
 * Planering earns when an attested period's overtime or mertid is taken as
 * time instead of money. A month whose regular run is attested is closed; a
 * calculated run for the month goes back to draft. Audited.
 */
class CompTimeEntries
{
    public const array MANUAL_KINDS = [CompTimeEntry::TAKEN, CompTimeEntry::PAID_OUT, CompTimeEntry::ADJUSTMENT];

    public function __construct(private readonly ModuleManager $modules, private readonly PayrollRuns $runs, private readonly SeedPayCodes $seed) {}

    /**
     * @param  array<string, mixed>  $input  employee_id, kind, date, hours, note
     */
    public function record(User $user, Company $company, array $input): CompTimeEntry
    {
        $this->authorize($user, $company);

        $data = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(self::MANUAL_KINDS)],
            'date' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required', 'regex:/^-?\d{1,4}([.,]\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'hours.regex' => __('Ange timmar med högst två decimaler, till exempel 7,5.'),
        ], ['date' => __('datum'), 'hours' => __('timmar'), 'kind' => __('typ')])->validate();

        $employee = Employee::query()->findOrFail((int) $data['employee_id']);
        $date = CarbonImmutable::parse((string) $data['date']);
        $minutes = BigDecimal::of(str_replace(',', '.', (string) $data['hours']))->multipliedBy(60)->toScale(0, RoundingMode::HalfUp)->toInt();

        if ($minutes === 0 || ($data['kind'] !== CompTimeEntry::ADJUSTMENT && $minutes < 0)) {
            throw ValidationException::withMessages(['hours' => __('Ange fler timmar än noll.')]);
        }

        $minutes = $data['kind'] === CompTimeEntry::ADJUSTMENT ? $minutes : -$minutes;

        return DB::transaction(function () use ($user, $company, $employee, $date, $minutes, $data): CompTimeEntry {
            $this->ensureOpen($date);
            // One balance at a time per employee, so two registrations cannot both spend the same hours.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if ($minutes < 0 && CompTimeLedger::balance($employee->id) + $minutes < 0) {
                throw ValidationException::withMessages(['hours' => __('Saldot räcker inte: :name har :hours timmar komptid.', ['name' => "{$employee->first_name} {$employee->last_name}", 'hours' => CompTimeLedger::hours(CompTimeLedger::balance($employee->id))])]);
            }

            ($this->seed)($company);

            $entry = CompTimeEntry::create([
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'kind' => (string) $data['kind'],
                'minutes' => $minutes,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            Audit::record('payroll.comp_time_recorded', $entry, ['employee_id' => $employee->id, 'kind' => $entry->kind, 'date' => $date->toDateString(), 'minutes' => $minutes], $company, $user);
            $this->outdate($date);

            return $entry;
        });
    }

    /**
     * Comp time earned for overtime or mertid taken as time, from Planering.
     * The caller authorizes and runs it inside its transaction.
     */
    public function earn(User $user, Company $company, int $employeeId, CarbonImmutable $date, int $workedMinutes, string $factor, ?string $payCode, string $sourceType, int $sourceId): CompTimeEntry
    {
        $minutes = BigDecimal::of($workedMinutes)->multipliedBy($factor)->toScale(0, RoundingMode::HalfUp)->toInt();
        $entry = CompTimeEntry::create([
            'employee_id' => $employeeId,
            'date' => $date->toDateString(),
            'kind' => CompTimeEntry::EARNED,
            'minutes' => max(1, $minutes),
            'worked_minutes' => $workedMinutes,
            'factor' => $factor,
            'pay_code' => $payCode,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $user->id,
        ]);

        Audit::record('payroll.comp_time_earned', $entry, ['employee_id' => $employeeId, 'date' => $date->toDateString(), 'worked_minutes' => $workedMinutes, 'factor' => $factor, 'minutes' => $entry->minutes, 'pay_code' => $payCode], $company, $user);

        return $entry;
    }

    /**
     * Removes the comp time a source earned (an attestation opened again in
     * Planering). Refused when it has been spent so the balance would go
     * below zero. The caller authorizes and runs it inside its transaction.
     */
    public function removeEarned(User $user, Company $company, string $sourceType, int $sourceId): int
    {
        $entries = CompTimeEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->get();

        foreach ($entries as $entry) {
            if (CompTimeLedger::balance($entry->employee_id) - $entry->minutes < 0) {
                throw ValidationException::withMessages(['comp_time' => __('Komptiden från dagen är redan uttagen eller utbetald. Registrera en justering under Lön → Komptid i stället.')]);
            }

            Audit::record('payroll.comp_time_removed', $entry, ['employee_id' => $entry->employee_id, 'minutes' => $entry->minutes, 'source_type' => $sourceType, 'source_id' => $sourceId], $company, $user);
            $entry->delete();
        }

        return $entries->count();
    }

    public function delete(User $user, Company $company, CompTimeEntry $entry): void
    {
        $this->authorize($user, $company);

        if ($entry->kind === CompTimeEntry::EARNED) {
            throw ValidationException::withMessages(['entry' => __('Intjänad komptid tas bort genom att dagen öppnas igen under Planering → Tid att attestera.')]);
        }

        DB::transaction(function () use ($user, $company, $entry): void {
            $this->ensureOpen($entry->date);

            if ($entry->minutes > 0 && CompTimeLedger::balance($entry->employee_id) - $entry->minutes < 0) {
                throw ValidationException::withMessages(['entry' => __('Saldot skulle bli negativt. Ta bort uttaget eller utbetalningen först.')]);
            }

            Audit::record('payroll.comp_time_deleted', $entry, ['employee_id' => $entry->employee_id, 'kind' => $entry->kind, 'date' => $entry->date->toDateString(), 'minutes' => $entry->minutes], $company, $user);
            $entry->delete();
            $this->outdate($entry->date);
        });
    }

    private function authorize(User $user, Company $company): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:manage')) {
            throw new AuthorizationException(__('Du saknar behörighet att ändra komptid.'));
        }
    }

    private function ensureOpen(CarbonImmutable $date): void
    {
        $closed = PayrollRun::query()
            ->where('type', 'REGULAR')
            ->where('period', $date->format('Y-m'))
            ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->exists();

        if ($closed) {
            throw ValidationException::withMessages(['date' => __('Lönen för :month är redan attesterad. Välj ett datum i en öppen månad.', ['month' => $date->translatedFormat('F Y')])]);
        }
    }

    /** A calculated run for the month no longer matches. */
    private function outdate(CarbonImmutable $date): void
    {
        PayrollRun::query()
            ->where('type', 'REGULAR')
            ->where('status', PayrollRun::CALCULATED)
            ->where('period', $date->format('Y-m'))
            ->lockForUpdate()
            ->get()
            ->each(fn (PayrollRun $run) => $this->runs->backToDraft($run));
    }
}
