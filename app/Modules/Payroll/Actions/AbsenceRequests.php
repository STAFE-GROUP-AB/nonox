<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Mail\AbsenceDecidedMail;
use App\Modules\Payroll\Mail\AbsenceRequestedMail;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Branding\FirmBranding;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service absence (PAYROLL_MODULE.md B.11): an employee reports
 * sickness or care of a sick child, or applies for vacation, on Min sida;
 * payroll staff approve or refuse it. Approved absence counts in the payroll
 * run for each month it falls in; a month whose run is already approved is
 * closed, and an outdated calculation goes back to draft.
 */
class AbsenceRequests
{
    public const array TYPES = ['SICK', 'VAB', 'VACATION'];

    public function __construct(private readonly ModuleManager $modules, private readonly PayrollRuns $runs) {}

    /**
     * @param  array<string, mixed>  $input  type, date_from, date_to, note
     */
    public function request(User $user, Company $company, array $input): Absence
    {
        $this->ensureSelfService($user, $company, 'self:absence');

        $validated = Validator::make($input, [
            'type' => ['required', Rule::in(self::TYPES)],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'date_to.after_or_equal' => __('Sista dagen kan inte vara före den första.'),
        ], ['date_from' => __('första dagen'), 'date_to' => __('sista dagen')])->validate();

        $from = CarbonImmutable::parse((string) $validated['date_from']);
        $to = CarbonImmutable::parse((string) $validated['date_to']);

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['date_to' => __('Ange högst ett år åt gången.')]);
        }

        if ($validated['type'] !== 'VACATION' && $from->greaterThan(CarbonImmutable::today()->addDays(7))) {
            throw ValidationException::withMessages(['date_from' => __('Sjukdom och vård av barn anmäls när det händer, inte i förväg.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $validated): Absence {
            $employee = Employee::query()->where('user_id', $user->id)->first()
                ?? throw ValidationException::withMessages(['type' => __('Ditt konto är inte kopplat till någon anställd. Be din arbetsgivare koppla det.')]);

            if (! $employee->isEmployedToday()) {
                throw ValidationException::withMessages(['type' => __('Din anställning har upphört, så du kan inte skicka in något här längre. Kontakta din tidigare arbetsgivare.')]);
            }

            $absence = Absence::create([
                'employee_id' => $employee->id,
                'type' => $validated['type'],
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'note' => blank($validated['note'] ?? null) ? null : $validated['note'],
                'status' => 'REQUESTED',
                'requested_by' => $user->id,
            ]);

            // The type is sensitive; the audit log records that absence was requested, not why.
            Audit::record('payroll.absence_requested', $absence, ['employee_id' => $employee->id], $company, $user);

            foreach ($this->staff($company)->reject(fn (User $member): bool => $member->id === $user->id) as $member) {
                Mail::to($member->email)->send(new AbsenceRequestedMail("{$employee->first_name} {$employee->last_name}", self::label($absence->type), self::dates($absence), $company->name));
            }

            return $absence;
        });
    }

    public function cancel(User $user, Absence $absence): void
    {
        $company = Company::query()->findOrFail($absence->company_id);
        $this->ensureSelfService($user, $company, 'self:absence');

        CurrentCompany::run($company, function () use ($user, $company, $absence): void {
            $own = Employee::query()->where('user_id', $user->id)->value('id');

            if ($own === null || $absence->employee_id !== $own || $absence->status !== 'REQUESTED') {
                throw new AuthorizationException;
            }

            $absence->delete();
            Audit::record('payroll.absence_request_cancelled', null, ['employee_id' => $own], $company, $user);
        });
    }

    public function approve(User $user, Absence $absence, ?string $hours = null): Absence
    {
        $company = $this->ensureStaff($user, $absence);

        $hours = trim((string) $hours) === '' ? $this->defaultHours($company, $absence) : str_replace(',', '.', trim((string) $hours));

        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $hours) !== 1 || (float) $hours > 24) {
            throw ValidationException::withMessages(['hours' => __('Skriv timmar per dag, till exempel 8 eller 7,5.')]);
        }

        return CurrentCompany::run($company, fn (): Absence => DB::transaction(function () use ($user, $company, $absence, $hours): Absence {
            /** @var Absence $absence */
            $absence = Absence::query()->whereKey($absence->id)->lockForUpdate()->firstOrFail();

            if ($absence->status !== 'REQUESTED') {
                throw ValidationException::withMessages(['absence' => __('Ansökan är redan besvarad.')]);
            }

            if ($absence->requested_by === $user->id && ($this->modules->settings(PayrollModule::KEY, $company)['segregation_of_duties'] ?? true)) {
                throw ValidationException::withMessages(['absence' => __('Du kan inte godkänna din egen ansökan. Någon annan med ansvar för lönerna behöver göra det.')]);
            }

            $runs = PayrollRun::query()
                ->whereIn('period', $this->periods($absence->date_from, $absence->date_to))
                ->get();
            $closed = $runs->first(fn (PayrollRun $run): bool => ! $run->isOpen());

            if ($closed !== null) {
                throw ValidationException::withMessages(['absence' => __(':run är redan attesterad. Frånvaron kan inte läggas in i den.', ['run' => $closed->title()])]);
            }

            $absence->forceFill(['status' => 'APPROVED', 'hours' => $hours, 'approved_by' => $user->id, 'decided_at' => now()])->save();

            foreach ($runs as $run) {
                if ($run->status === PayrollRun::CALCULATED) {
                    $this->runs->backToDraft($run);
                }
            }

            Audit::record('payroll.absence_approved', $absence, ['employee_id' => $absence->employee_id], $company, $user);
            $this->tell($absence, $company, true);

            return $absence;
        }));
    }

    public function reject(User $user, Absence $absence, string $reason): Absence
    {
        $company = $this->ensureStaff($user, $absence);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Skriv varför, så att den anställde förstår beslutet.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $absence, $reason): Absence {
            if ($absence->status !== 'REQUESTED') {
                throw ValidationException::withMessages(['absence' => __('Ansökan är redan besvarad.')]);
            }

            $absence->forceFill(['status' => 'REJECTED', 'rejection_reason' => trim($reason), 'approved_by' => $user->id, 'decided_at' => now()])->save();
            Audit::record('payroll.absence_rejected', $absence, ['employee_id' => $absence->employee_id], $company, $user);
            $this->tell($absence, $company, false);

            return $absence;
        });
    }

    /** The employment's hours per working day: weekly hours over five, or 8. */
    public function defaultHours(Company $company, Absence $absence): string
    {
        $weekly = CurrentCompany::run($company, fn () => Employment::query()->where('employee_id', $absence->employee_id)->whereNull('end_date')->latest('start_date')->value('weekly_hours'));

        return $weekly === null || (float) $weekly <= 0 ? '8' : rtrim(rtrim(number_format((float) $weekly / 5, 2, '.', ''), '0'), '.');
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'VACATION' => __('Semester'),
            'VAB' => __('Vård av barn'),
            default => __('Sjukanmälan'),
        };
    }

    private static function dates(Absence $absence): string
    {
        return $absence->date_from->equalTo($absence->date_to)
            ? $absence->date_from->toDateString()
            : "{$absence->date_from->toDateString()} – {$absence->date_to->toDateString()}";
    }

    /**
     * The members who handle payroll runs, read with one query: the owner and
     * the roles whose payroll permissions include payroll:run.
     *
     * @return Collection<int, User>
     */
    private function staff(Company $company): Collection
    {
        $roles = array_keys(array_filter((new PayrollModule)->rolePermissions(), fn (array $permissions): bool => in_array('payroll:run', $permissions, true)));
        $ids = DB::table('team_user')->where('team_id', $company->id)->whereIn('role', $roles)->pluck('user_id');

        if (in_array('owner', $roles, true)) {
            $ids->push($company->user_id);
        }

        return User::query()->whereIn('id', $ids->unique()->all())->get();
    }

    /** Emails the decision to the employee who asked, when they have a login. */
    private function tell(Absence $absence, Company $company, bool $approved): void
    {
        $requester = $absence->requested_by === null ? null : User::query()->find($absence->requested_by);
        $employee = Employee::query()->find($absence->employee_id);

        if ($requester === null || $employee === null) {
            return;
        }

        Mail::to($requester->email)->send(new AbsenceDecidedMail($employee->first_name, self::label($absence->type), self::dates($absence), $approved, $absence->rejection_reason, $company->name, FirmBranding::emailBrand($requester, $company)));
    }

    /** @return list<string> the months the dates touch, Y-m */
    private function periods(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $periods = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $periods[] = $month->format('Y-m');
        }

        return $periods;
    }

    private function ensureSelfService(User $user, Company $company, string $permission): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, $permission) || ! ($this->modules->settings(PayrollModule::KEY, $company)['employee_self_service'] ?? true)) {
            throw new AuthorizationException;
        }
    }

    private function ensureStaff(User $user, Absence $absence): Company
    {
        $company = Company::query()->findOrFail($absence->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:run')) {
            throw new AuthorizationException;
        }

        return $company;
    }
}
