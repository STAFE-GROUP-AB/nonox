<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Planning\Domain\BasisTerms;
use App\Modules\Planning\Domain\DayBasis;
use App\Modules\Planning\Domain\DayComparison;
use App\Modules\Planning\Domain\Deviation;
use App\Modules\Planning\Domain\OvertimeTerms;
use App\Modules\Planning\Domain\PayrollBasis;
use App\Modules\Planning\Domain\PlanVsActual;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Domain\WorkingTimeChecks;
use App\Modules\Planning\Domain\WorkInterval;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Models\TimeProposal;
use App\Modules\StaffLedger\StaffLedgerModule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Everything "Tid att attestera" shows for a period, in a fixed number of
 * queries whatever the number of people and days: the people employed, the
 * published shifts, the staff ledger's shifts (read only), approved
 * absence, the attestations, time reports that already cover a day, and
 * the payroll runs of the months. Days after today are not attested.
 */
final class AttestData
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @return array{rows: list<AttestRow>, employees: Collection<int, Employee>, ledger: bool}
     */
    public function load(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null): array
    {
        $zone = LocalTimestamp::ZONE;
        $today = CarbonImmutable::today($zone);
        $shownFrom = CarbonImmutable::parse($from->toDateString(), $zone);
        $shownTo = CarbonImmutable::parse($to->toDateString(), $zone)->min($today);
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        // Whole calculation periods (calendar weeks by default) are read, so overtime and mertid can be counted per period;
        // also the four-week periods used for employments without a collective agreement (PlanningSettings::periodWeeks).
        $weeks = max(1, PlanningSettings::int($settings, 'overtime_period_weeks'));
        $capped = PlanningSettings::periodWeeks($settings, false);
        $from = PlanningSettings::periodStart($settings, $shownFrom)->min(PlanningSettings::periodStart($settings, $shownFrom, $capped));
        $to = PlanningSettings::periodStart($settings, $shownTo)->addWeeks($weeks)->subDay()
            ->max(PlanningSettings::periodStart($settings, $shownTo, $capped)->addWeeks($capped)->subDay())
            ->min($today);
        $ledgerOn = $this->modules->isEnabled(StaffLedgerModule::KEY, $company);

        if ($shownTo->lessThan($shownFrom)) {
            return ['rows' => [], 'employees' => collect(), 'ledger' => $ledgerOn];
        }

        $employees = Employee::query()
            ->when($employeeId !== null, fn (Builder $query) => $query->whereKey($employeeId))
            ->whereHas('employments', fn ($query) => self::overlapping($query, $shownFrom, $shownTo))
            ->with(['employments' => fn ($query) => self::overlapping($query, $from, $to)->orderBy('start_date')])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        if ($employees->isEmpty()) {
            return ['rows' => [], 'employees' => $employees, 'ledger' => $ledgerOn];
        }

        $ids = array_values(array_map('intval', $employees->modelKeys()));
        $planned = $this->planned($from, $to, $ids);
        [$ledger, $proposals] = $ledgerOn ? $this->ledger($from, $to, $ids) : [[], []];
        $absences = $this->absences($from, $to, $ids);
        $attestations = Attestation::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (Attestation $attestation): string => "{$attestation->employee_id}|{$attestation->date->toDateString()}");
        $reported = $this->reported($from, $to, $ids, $attestations, $proposals);
        $locked = $this->lockedMonths($from, $to);
        $agreements = new EmploymentAgreements($this->modules, $company, $employees->flatMap(fn (Employee $employee): Collection => $employee->employments));
        $tolerance = PlanningSettings::int($settings, 'attest_tolerance_minutes');
        $rounding = PlanningSettings::int($settings, 'check_in_rounding_minutes');
        $rows = [];

        foreach ($employees as $employee) {
            $own = $attestations->filter(fn (Attestation $attestation): bool => $attestation->employee_id === $employee->id);
            $manual = [];

            foreach ($own as $attestation) {
                if ($attestation->status === Attestation::OPEN && $attestation->actual_override !== null) {
                    $manual[$attestation->date->toDateString()] = array_map(fn (array $interval): WorkInterval => WorkInterval::fromArray($interval), $attestation->actual_override);
                }
            }

            $inLedger = array_key_exists($employee->id, $ledger);
            $days = PlanVsActual::compare($planned[$employee->id] ?? [], $inLedger ? $ledger[$employee->id] : null, $manual, $absences[$employee->id] ?? [], $tolerance, $rounding);

            foreach ($own as $attestation) {
                $days[$attestation->date->toDateString()] ??= new DayComparison($attestation->date->setTimezone($zone)->startOfDay(), [], [], DayComparison::SOURCE_PLAN, []);
            }

            ksort($days);

            foreach ($days as $date => $day) {
                $date = (string) $date;

                if ($date < $from->toDateString() || $date > $to->toDateString()) {
                    continue;
                }

                $key = "{$employee->id}|{$date}";
                $attestation = $attestations->get($key);
                $lock = $locked[substr($date, 0, 7)] ?? null;

                if ($attestation !== null && $attestation->status !== Attestation::OPEN) {
                    $rows[] = self::frozen($employee, $attestation, $lock);

                    continue;
                }

                if (! $day->planned && ! $day->actual && $day->deviations === [] && $attestation === null) {
                    continue;
                }

                $extra = [];

                if ($ledgerOn && ! $inLedger) {
                    $extra[] = Deviation::info(Deviation::NOT_IN_LEDGER, __('Inte kopplad till någon i personalliggaren: den planerade tiden används.'));
                }

                if (isset($reported[$key])) {
                    $extra[] = Deviation::blocking(Deviation::ALREADY_REPORTED, $reported[$key]);
                }

                $basis = PayrollBasis::for($day, $this->terms($employee, $day->date, $agreements, isset($absences[$employee->id][$date])));
                $rows[] = new AttestRow($employee, $day->date, $day, $basis, [...$day->deviations, ...$basis->deviations, ...$extra], $attestation, $lock);
            }
        }

        $rows = $this->withWeeks($rows, $settings, $today, $agreements, $this->leaveDays($absences, $from, $to, $ids));
        $rows = array_values(array_filter($rows, fn (AttestRow $row): bool => $row->date->greaterThanOrEqualTo($shownFrom) && $row->date->lessThanOrEqualTo($shownTo)));

        return ['rows' => $rows, 'employees' => $employees, 'ledger' => $ledgerOn];
    }

    /**
     * Overtime and mertid per calculation period (PayrollBasis::period): a
     * calendar week, or the number of weeks in the settings (a rotating
     * schedule's cycle), at most four weeks for an employment no collective
     * agreement binds (arbetstidslagen 5 § andra stycket), measured the way
     * the agreement says (OvertimeTerms). Put on the period's last day. A
     * period counts only when it is over and every day in it is attested;
     * until then the last day says the period is waiting. Monthly-paid staff
     * get it on the pay codes the company chose; hourly-paid staff only when
     * the agreement gives them a supplement (Gröna Riks, Detaljhandeln), on
     * the agreement's supplement-only code, since the hourly pay already
     * pays the hour. When a monthly-paid employee may take comp time, the
     * period also says what it would earn as leave, and whether it is paid
     * or taken as comp time (chosen on the last day, else the employment's
     * default).
     *
     * @param  list<AttestRow>  $rows
     * @param  array<string, mixed>  $settings
     * @param  array<int, array<string, true>>  $leave  employee => dates with approved absence or comp leave
     * @return list<AttestRow>
     */
    private function withWeeks(array $rows, array $settings, CarbonImmutable $today, EmploymentAgreements $agreements, array $leave): array
    {
        $rules = (array) ($settings['working_time_rules'] ?? []);
        $ordinary = (array) ($rules['ordinary_week'] ?? []);
        $fullTime = (int) (is_numeric($ordinary['value'] ?? null) ? $ordinary['value'] : 40) * 60;
        $configured = max(1, PlanningSettings::int($settings, 'overtime_period_weeks'));
        $periods = [];

        foreach ($rows as $index => $row) {
            $length = PlanningSettings::periodWeeks($settings, self::employmentOn($row->employee, $row->date)?->collective_agreement_id !== null);
            $periods[$row->employee->id.'|'.$length.'|'.PlanningSettings::periodStart($settings, $row->date, $length)->toDateString()][] = $index;
        }

        foreach ($periods as $key => $indexes) {
            $last = $indexes[array_key_last($indexes)];
            $carrier = $rows[$last];
            $employment = self::employmentOn($carrier->employee, $carrier->date);

            if ($employment === null) {
                continue;
            }

            [$agreement] = $agreements->on($employment, $carrier->date);
            $hourly = $employment->salary_form === 'HOURLY';

            if ($employment->salary_form !== 'MONTHLY' && ! ($hourly && $agreement?->hasHourlySupplements() === true)) {
                continue;
            }

            [, $length, $start] = explode('|', (string) $key);
            $length = (int) $length;
            $label = $length > 1 ? __('Perioden') : __('Veckan');
            $periodStart = CarbonImmutable::parse($start, LocalTimestamp::ZONE);
            $sunday = $periodStart->addWeeks($length)->subDay();
            $complete = $sunday->lessThan($today);
            $worked = 0;
            $planned = 0;
            $days = [];

            foreach ($indexes as $index) {
                $worked += $rows[$index]->comparison->actualMinutes();
                $planned += $rows[$index]->comparison->plannedMinutes();
                $complete = $complete && ! $rows[$index]->isOpen();
                $days[] = $rows[$index]->comparison;
            }

            $contracted = self::contractedMinutes($employment, $fullTime) * $length;
            $sent = $carrier->attestation?->details['week'] ?? null;

            if (is_array($sent)) {
                $rows[$last] = $carrier->withWeek(null, [Deviation::info(Deviation::EXTRA_TIME, ($sent['comp_time_minutes'] ?? 0) > 0
                    ? __(':period: övertid och mertid är sparad som komptid (:hours).', ['period' => $label, 'hours' => WorkingTimeChecks::hours($sent['comp_time_minutes'])])
                    : ($length > 1 ? __('Periodens övertid och mertid är skickad till lönen med den här dagen.') : __('Veckans övertid och mertid är skickad till lönen med den här dagen.')))]);

                continue;
            }

            if (! $complete) {
                if ($worked > min($planned, $contracted)) {
                    $rows[$last] = $carrier->withWeek(null, [Deviation::info(Deviation::WEEK_PENDING, $length > 1 ? __('Perioden: hittills :minutes min utöver schemat. Övertid och mertid räknas mot veckoarbetstiden gånger periodens veckor när hela perioden är attesterad – väntar på hela perioden.', ['minutes' => $worked - min($planned, $contracted)]) : __('Veckan: hittills :minutes min utöver schemat. Övertid och mertid räknas mot veckoarbetstiden när hela veckan är attesterad – väntar på hela veckan.', ['minutes' => $worked - min($planned, $contracted)]))]);
                }

                continue;
            }

            [$overtimeCode, $extraTimeCode, $codeNotes] = self::periodCodes($settings, $hourly ? $agreement : null, $carrier->date);
            $included = $employment->overtime_compensation === 'INCLUDED_IN_SALARY';
            $week = PayrollBasis::period($days, $leave[$carrier->employee->id] ?? [], OvertimeTerms::from($agreement), $contracted, $fullTime * $length, $overtimeCode, $extraTimeCode, $included, $length);
            $deviations = $week->hasExtraTime() ? [...$week->deviations, ...$codeNotes] : $week->deviations;
            $maximum = $agreement?->calculationPeriod($employment->salary_form);

            if ($length > 1 && $maximum !== null && $length > $maximum['max_weeks']) {
                $deviations[] = new Deviation(Deviation::EXTRA_TIME, __('Beräkningsperioden är :weeks veckor, men :agreement medger högst :max veckor utan lokalt avtal (:source).', ['weeks' => $length, 'agreement' => $agreement->shortName(), 'max' => $maximum['max_weeks'], 'source' => $maximum['source']]));
            }

            if ($length < $configured && $week->hasExtraTime()) {
                $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __('Utan kollektivavtal räknas övertid och mertid över högst :max veckor (arbetstidslagen 5 § andra stycket), inte :weeks veckor som i inställningarna.', ['max' => $length, 'weeks' => $configured]));
            }

            if (! $hourly && $week->hasExtraTime() && ! $included) {
                $options = self::compTimeOptions($week, $agreement, $overtimeCode, $extraTimeCode, $settings);
                $chosen = $carrier->attestation?->details['week_choice'] ?? null;
                $choice = in_array($chosen, [WeekBasis::PAY, WeekBasis::COMP_TIME], true)
                    ? $chosen
                    : ($employment->overtime_compensation === 'COMP_TIME' ? WeekBasis::COMP_TIME : WeekBasis::PAY);
                $week = $week->withCompTime($options, $choice);

                if ($choice === WeekBasis::COMP_TIME) {
                    $deviations = [Deviation::info(Deviation::EXTRA_TIME, __(':period: :worked min övertid och mertid sparas som :hours komptid när dagen skickas till lönen.', ['period' => $label, 'worked' => $week->overtimeMinutes + $week->extraTimeMinutes, 'hours' => WorkingTimeChecks::hours($week->compTimeMinutes())]))];
                }
            }

            if ($deviations !== [] || $week->hasExtraTime()) {
                $rows[$last] = $carrier->withWeek($week, $deviations);
            }
        }

        return array_values($rows);
    }

    /**
     * The pay codes a period's overtime and mertid go on: the company's
     * choice, or for an hourly-paid employee (an agreement given) the
     * agreement's supplement-only code for that choice, when it has a rate
     * on the day. What could not be mapped is said.
     *
     * @param  array<string, mixed>  $settings
     * @return array{?string, ?string, list<Deviation>}
     */
    private static function periodCodes(array $settings, ?AgreementRules $hourlyAgreement, CarbonImmutable $day): array
    {
        $overtime = PlanningSettings::code($settings, 'overtime_pay_code');
        $extra = PlanningSettings::code($settings, 'extra_time_pay_code');

        if ($hourlyAgreement === null) {
            return [$overtime, $extra, []];
        }

        $notes = [];
        $codes = [];

        foreach ([$overtime, $extra] as $code) {
            $supplement = $code === null ? null : $hourlyAgreement->hourlySupplement($code);

            if ($supplement !== null && $hourlyAgreement->rate($supplement['code'], $day) !== null) {
                $codes[] = $supplement['code'];

                continue;
            }

            $codes[] = null;

            if ($code !== null) {
                $notes[] = Deviation::info(Deviation::EXTRA_TIME, __(':agreement har inget tillägg för timavlönade för :code den här dagen; timlönen betalar redan tiden.', ['agreement' => $hourlyAgreement->shortName(), 'code' => $code]));
            }
        }

        return [$codes[0], $codes[1], $notes];
    }

    /**
     * Dates with approved absence (from payroll) or comp leave taken, per
     * employee: leave placed on ordinary time counts as worked when overtime
     * is measured (arbetstidslagen 7 § andra stycket).
     *
     * @param  array<int, array<string, true>>  $absences
     * @param  list<int>  $ids
     * @return array<int, array<string, true>>
     */
    private function leaveDays(array $absences, CarbonImmutable $from, CarbonImmutable $to, array $ids): array
    {
        $taken = CompTimeEntry::query()
            ->whereIn('employee_id', $ids)
            ->where('kind', CompTimeEntry::TAKEN)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['employee_id', 'date']);

        foreach ($taken as $entry) {
            $absences[$entry->employee_id][$entry->date->toDateString()] = true;
        }

        return $absences;
    }

    /**
     * What the period's overtime and mertid would earn as comp time: per
     * kind, the minutes times the agreement's factor for the code the kind
     * is sent on, else the company's own factor.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{kind: string, code: ?string, worked: int, factor: string, minutes: int, source: string}>
     */
    private static function compTimeOptions(WeekBasis $week, ?AgreementRules $agreement, ?string $overtimeCode, ?string $extraTimeCode, array $settings): array
    {
        $factors = $agreement?->compTime();
        $options = [];

        foreach ([['OVERTIME', $overtimeCode, $week->overtimeMinutes], ['EXTRA_TIME', $extraTimeCode, $week->extraTimeMinutes]] as [$kind, $code, $worked]) {
            if ($worked <= 0) {
                continue;
            }

            $fromAgreement = $code !== null && $factors !== null && isset($factors['hours_per_hour'][$code]);
            $factor = $fromAgreement ? $factors['hours_per_hour'][$code] : PlanningSettings::compTimeFactor($settings);
            $options[] = [
                'kind' => $kind,
                'code' => $code,
                'worked' => $worked,
                'factor' => $factor,
                'minutes' => (int) round($worked * (float) $factor),
                'source' => $fromAgreement ? $factors['source'] : (string) __('Företagets egen faktor (Planering → Inställningar)'),
            ];
        }

        return $options;
    }

    /** The contracted weekly time: the employment's weekly hours, else the ordinary week by the employment rate. */
    private static function contractedMinutes(Employment $employment, int $fullTime): int
    {
        if ($employment->weekly_hours !== null && is_numeric($employment->weekly_hours) && (float) $employment->weekly_hours > 0) {
            return (int) round((float) $employment->weekly_hours * 60);
        }

        return intdiv($fullTime * $employment->employment_rate_bp, 10000);
    }

    private static function employmentOn(Employee $employee, CarbonImmutable $day): ?Employment
    {
        $employment = $employee->employments->last(fn (Employment $employment): bool => $employment->start_date->lessThanOrEqualTo($day)
            && ($employment->end_date === null || $employment->end_date->greaterThanOrEqualTo($day)));

        return $employment instanceof Employment ? $employment : null;
    }

    /**
     * A day as it was attested.
     */
    private static function frozen(Employee $employee, Attestation $attestation, ?string $lock): AttestRow
    {
        $details = $attestation->details ?? [];
        $intervals = function (string $key) use ($details): array {
            $list = [];

            foreach ((array) ($details[$key] ?? []) as $interval) {
                $list[] = WorkInterval::fromArray((array) $interval);
            }

            return $list;
        };
        $deviations = array_map(fn (array $deviation): Deviation => new Deviation($deviation['code'], $deviation['message'], $deviation['blocking'], $deviation['informational']), $attestation->deviations ?? []);
        $date = CarbonImmutable::parse($attestation->date->toDateString(), LocalTimestamp::ZONE);

        return new AttestRow(
            $employee,
            $date,
            new DayComparison($date, $intervals('planned'), $intervals('actual'), (string) ($details['source'] ?? DayComparison::SOURCE_PLAN), []),
            new DayBasis($attestation->basis ?? [], (array) ($details['ob'] ?? []), []),
            $deviations,
            $attestation,
            $attestation->status === Attestation::SENT ? null : $lock,
        );
    }

    private function terms(Employee $employee, CarbonImmutable $day, EmploymentAgreements $agreements, bool $absence): BasisTerms
    {
        $employment = self::employmentOn($employee, $day);

        if ($employment === null) {
            return new BasisTerms(null);
        }

        [$agreement, $unverified] = $agreements->on($employment, $day);

        return new BasisTerms(
            salaryForm: $employment->salary_form,
            employmentRateBp: $employment->employment_rate_bp,
            hourlyRateOre: $employment->hourly_rate_ore?->ore,
            monthlySalaryOre: $employment->monthly_salary_ore?->ore,
            agreement: $agreement,
            agreementUnverified: $unverified,
            obIncluded: $employment->ob_compensation === 'INCLUDED',
            absence: $absence,
        );
    }

    /**
     * Published shifts per employee. A published shift changed since keeps
     * what the employee was shown until it is published again; drafts
     * never published are not part of the plan.
     *
     * @param  list<int>  $ids
     * @return array<int, list<WorkInterval>>
     */
    private function planned(CarbonImmutable $from, CarbonImmutable $to, array $ids): array
    {
        $planned = [];
        $shifts = ShiftQuery::startingOn($from, $to)
            ->whereIn('status', [Shift::PUBLISHED, Shift::DRAFT])
            ->whereNotNull('employee_id')
            ->orderBy('starts_at')
            ->get();

        foreach ($shifts as $shift) {
            if ($shift->status === Shift::PUBLISHED) {
                $planned[(int) $shift->employee_id][] = new WorkInterval($shift->starts_at, $shift->ends_at, $shift->break_minutes);

                continue;
            }

            $snapshot = $shift->published_snapshot;

            if ($shift->published_at === null || ! is_array($snapshot) || ! is_numeric($snapshot['employee_id'] ?? null)) {
                continue;
            }

            $planned[(int) $snapshot['employee_id']][] = WorkInterval::fromArray($snapshot);
        }

        return array_intersect_key($planned, array_flip($ids));
    }

    /**
     * The staff ledger's shifts per employee linked to a person in it, and
     * the days a ledger proposal not yet decided covers. Read only.
     *
     * @param  list<int>  $ids
     * @return array{array<int, list<WorkInterval>>, array<string, true>}
     */
    private function ledger(CarbonImmutable $from, CarbonImmutable $to, array $ids): array
    {
        $people = Person::query()->whereIn('employee_id', $ids)->pluck('employee_id', 'id');
        $ledger = [];

        foreach ($people as $employeeId) {
            $ledger[(int) $employeeId] ??= [];
        }

        if ($people->isNotEmpty()) {
            foreach (LedgerQuery::shifts(null, $from->startOfDay(), $to->endOfDay()) as $shift) {
                $employeeId = $shift->personId === null ? null : $people->get($shift->personId);

                if ($employeeId !== null) {
                    $ledger[(int) $employeeId][] = new WorkInterval($shift->start?->setTimezone(LocalTimestamp::ZONE), $shift->end?->setTimezone(LocalTimestamp::ZONE));
                }
            }
        }

        $pending = [];
        $proposals = TimeProposal::query()
            ->whereIn('employee_id', $ids)
            ->where('status', TimeProposal::PROPOSED)
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->get(['employee_id', 'days']);

        foreach ($proposals as $proposal) {
            foreach (array_keys($proposal->days) as $day) {
                $pending["{$proposal->employee_id}|{$day}"] = true;
            }
        }

        return [$ledger, $pending];
    }

    /**
     * Dates with approved absence per employee.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, true>>
     */
    private function absences(CarbonImmutable $from, CarbonImmutable $to, array $ids): array
    {
        $days = [];
        $absences = Absence::query()
            ->whereIn('employee_id', $ids)
            ->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString())
            ->get(['employee_id', 'date_from', 'date_to']);

        foreach ($absences as $absence) {
            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($absence->date_to->min($to)); $day = $day->addDay()) {
                $days[$absence->employee_id][$day->toDateString()] = true;
            }
        }

        return $days;
    }

    /**
     * Days already covered by a time report that is not Planering's own, or
     * by a staff-ledger proposal waiting for a decision, so nothing is paid
     * twice.
     *
     * @param  list<int>  $ids
     * @param  Collection<string, Attestation>  $attestations
     * @param  array<string, true>  $proposals  employee|date covered by a pending ledger proposal
     * @return array<string, string> employee|date => why
     */
    private function reported(CarbonImmutable $from, CarbonImmutable $to, array $ids, Collection $attestations, array $proposals): array
    {
        $own = $attestations->flatMap(fn (Attestation $attestation): array => $attestation->time_report_ids ?? [])->flip();
        $reported = [];
        $reports = TimeReport::query()
            ->whereIn('employee_id', $ids)
            ->where('status', '<>', 'REJECTED')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'employee_id', 'date']);

        foreach ($reports as $report) {
            if (! $own->has($report->id)) {
                $reported["{$report->employee_id}|{$report->date->toDateString()}"] = __('Dagen finns redan i en tidrapport i lönen (till exempel godkänd från personalliggaren). Den skickas inte en gång till.');
            }
        }

        foreach (array_keys($proposals) as $key) {
            $reported[$key] ??= __('Ett tidrapportförslag från personalliggaren väntar för dagen. Avslå det under Personalliggare → Tidrapporter först.');
        }

        return $reported;
    }

    /**
     * Months whose regular payroll run is attested: a day there is not sent
     * again; a correction goes into the next run.
     *
     * @return array<string, string> Y-m => why
     */
    private function lockedMonths(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $locked = [];
        $runs = PayrollRun::query()
            ->where('type', 'REGULAR')
            ->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])
            ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->pluck('period');

        foreach ($runs as $period) {
            $locked[(string) $period] = __('Lönen för :month är redan attesterad. Rättelser läggs i nästa lönekörning.', ['month' => CarbonImmutable::parse("{$period}-01")->translatedFormat('F Y')]);
        }

        return $locked;
    }

    /**
     * @param  Builder<Employment>|HasMany<Employment, Employee>  $query
     * @return Builder<Employment>|HasMany<Employment, Employee>
     */
    private static function overlapping($query, CarbonImmutable $from, CarbonImmutable $to)
    {
        return $query->whereDate('start_date', '<=', $to->toDateString())
            ->where(fn ($inner) => $inner->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()));
    }
}
