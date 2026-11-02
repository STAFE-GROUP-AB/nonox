<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Domain\AgreementPricer;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\AgreementVersions;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Planning\Domain\CostEstimate;
use App\Modules\Planning\Domain\ShiftCost;
use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Puts ShiftCost's estimate on shifts (cost_estimate_ore, cost_details):
 * the employment in force on the shift's day, the agreement version that
 * covers it when NONOX Avtal is on and the version is checked, and the
 * verified payroll rules on the first day of the batch. Loads in a fixed
 * number of queries for any number of shifts. Does not save.
 */
final class ShiftCoster
{
    public function __construct(private readonly ModuleManager $modules) {}

    /** @param  iterable<Shift>  $shifts */
    public function apply(Company $company, iterable $shifts): void
    {
        $shifts = collect($shifts)->values();

        if ($shifts->isEmpty()) {
            return;
        }

        $employeeIds = $shifts->pluck('employee_id')->filter()->unique()->values();
        $employments = $employeeIds->isEmpty() ? collect() : Employment::query()->whereIn('employee_id', $employeeIds)->orderBy('start_date')->get()->groupBy('employee_id');
        $birthDates = $employeeIds->isEmpty() ? collect() : Employee::query()->whereKey($employeeIds)->pluck('birth_date', 'id');
        [$bindings, $versions, $checked] = $this->agreements($company, $employments->flatten(1));
        $firstDay = $shifts->map(fn (Shift $shift): CarbonImmutable => $shift->starts_at)->sort()->first() ?? CarbonImmutable::now();
        $rules = RuleSet::for($firstDay->startOfDay());
        $vacationBp = $this->bp($rules, 'vacation.percent_rule');
        $now = CarbonImmutable::now();

        foreach ($shifts as $shift) {
            $estimate = $shift->employee_id === null
                ? null
                : $this->estimate($shift, $employments->get($shift->employee_id, collect()), $birthDates->get($shift->employee_id), $bindings, $versions, $checked, $rules, $vacationBp);

            $shift->forceFill([
                'cost_estimate_ore' => $estimate?->totalOre(),
                'cost_details' => $estimate?->toArray() ?? ['missing' => [__('Öppet pass: ingen lön att räkna på förrän någon har passet.')]],
                'cost_calculated_at' => $now,
            ]);
        }
    }

    /**
     * @param  Collection<int, Employment>  $employments
     * @param  Collection<int, CollectiveAgreement>  $bindings
     * @param  Collection<int, AgreementRuleVersion>  $versions
     * @param  list<int>  $checked
     */
    private function estimate(Shift $shift, Collection $employments, mixed $birthDate, Collection $bindings, Collection $versions, array $checked, RuleSet $rules, ?int $vacationBp): CostEstimate
    {
        $day = $shift->starts_at->startOfDay();
        $employment = $employments->last(fn (Employment $employment): bool => $employment->start_date->lessThanOrEqualTo($day) && ($employment->end_date === null || $employment->end_date->greaterThanOrEqualTo($day)));
        [$agreement, $unverified] = $this->agreementFor($employment, $bindings, $versions, $checked, $day);

        return ShiftCost::estimate(
            start: $shift->starts_at,
            end: $shift->ends_at,
            breakMinutes: $shift->break_minutes,
            salaryForm: $employment?->salary_form,
            hourlyRateOre: $employment?->hourly_rate_ore?->ore,
            monthlySalaryOre: $employment === null || $employment->monthly_salary_ore === null ? null : intdiv($employment->monthly_salary_ore->ore * $employment->employment_rate_bp, 10000),
            weeklyHours: $employment?->weekly_hours,
            agreement: $agreement,
            agreementUnverified: $unverified,
            obIncludedInSalary: $employment?->ob_compensation === AgreementPricer::INCLUDED,
            contributionBp: $this->contributionBp($rules, $birthDate instanceof CarbonImmutable ? $birthDate : null),
            vacationBp: $vacationBp,
        );
    }

    /**
     * The usable agreement version for the employment on the day, or that
     * the employment is bound to one without a checked version.
     *
     * @param  Collection<int, CollectiveAgreement>  $bindings
     * @param  Collection<int, AgreementRuleVersion>  $versions
     * @param  list<int>  $checked
     * @return array{?AgreementRules, bool}
     */
    private function agreementFor(?Employment $employment, Collection $bindings, Collection $versions, array $checked, CarbonImmutable $day): array
    {
        $binding = $employment?->collective_agreement_id === null ? null : $bindings->get($employment->collective_agreement_id);

        if (! $binding instanceof CollectiveAgreement || $binding->agreement_key === null || $binding->variant === null) {
            return [null, false];
        }

        $version = $versions->last(fn (AgreementRuleVersion $version): bool => $version->agreement_key === $binding->agreement_key
            && $version->variant === $binding->variant
            && $version->covers($day)
            && AgreementVersions::isUsable($version, $checked));

        return $version === null ? [null, true] : [$version->agreementRules(), false];
    }

    /**
     * @param  Collection<int, Employment>  $employments
     * @return array{Collection<int, CollectiveAgreement>, Collection<int, AgreementRuleVersion>, list<int>}
     */
    private function agreements(Company $company, Collection $employments): array
    {
        $ids = $employments->pluck('collective_agreement_id')->filter()->unique()->values();

        if ($ids->isEmpty() || ! $this->modules->isEnabled(AgreementsModule::KEY, $company)) {
            return [collect(), collect(), []];
        }

        $bindings = CollectiveAgreement::query()->whereKey($ids)->get()->keyBy('id');
        $keys = $bindings->pluck('agreement_key')->filter()->unique()->values();
        $versions = $keys->isEmpty() ? collect() : AgreementRuleVersion::query()->whereIn('agreement_key', $keys)->orderBy('valid_from')->get();

        return [$bindings, $versions, AgreementVersions::companyChecked()];
    }

    /** The employer contribution rate for the employee's birth year, from verified rules only. */
    private function contributionBp(RuleSet $rules, ?CarbonImmutable $birthDate): ?int
    {
        $full = $this->bp($rules, 'employer_contribution.full');

        if ($full === null || $birthDate === null) {
            return $full;
        }

        try {
            $bands = (array) (((array) $rules->get('employer_contribution.reduced_by_birthyear'))['bands'] ?? []);
        } catch (MissingPayrollRuleException) {
            return $full;
        }

        foreach ($bands as $band) {
            $band = (array) $band;

            if ($birthDate->year >= (int) ($band['born_from'] ?? 0) && $birthDate->year <= (int) ($band['born_to'] ?? 9999) && isset($band['bp'])) {
                return (int) $band['bp'];
            }
        }

        return $full;
    }

    private function bp(RuleSet $rules, string $key): ?int
    {
        try {
            $value = (array) $rules->get($key);
        } catch (MissingPayrollRuleException) {
            return null;
        }

        return isset($value['bp']) && is_numeric($value['bp']) ? (int) $value['bp'] : null;
    }
}
