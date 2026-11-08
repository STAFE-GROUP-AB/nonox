<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3EligibilityStatus;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes the K-regelverk from one fiscal year on (ADR-059, addendum A.2.1).
 * The framework belongs to the year: BFNAR 2025:2 and 2025:3 apply by the
 * year's start (S1-R1.4) and a change of framework happens at a year
 * boundary, so the chosen year and every later year get the new framework.
 * The company column follows the latest year and is the default for the
 * next one.
 *
 * Refused when the chosen year or a later one is closed or has an adopted
 * annual report: that report was made under the old framework. One audit
 * entry per changed year, with ids and enums only; the reason stays in the
 * year's column.
 */
class ChangeReportingFramework
{
    /**
     * @param  array{framework_confirmed?: bool}  $confirmations  the K2 (or K1) conditions, confirmed by the person
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, ReportingFramework $framework, ?string $reason = null, array $confirmations = []): FiscalYear
    {
        // The year is given as a model; its company is read without the scope so the permission check decides, not the session.
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        Gate::forUser($user)->authorize('update', $company);

        $reason = filled($reason) ? trim((string) $reason) : null;
        $confirmed = (bool) ($confirmations['framework_confirmed'] ?? false);

        Validator::make(
            ['reporting_framework' => $framework->value, 'framework_confirmed' => $confirmed, 'reason' => $reason],
            [
                'reporting_framework' => [CompanySetupRules::framework($company->legal_form)],
                'framework_confirmed' => ReportingFrameworkRules::conditions($company->legal_form, $framework) === null ? ['boolean'] : ['accepted'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            CompanySetupRules::messages(),
            ['reason' => __('anledning')],
        )->validate();

        DB::transaction(function () use ($user, $company, $fiscalYear, $framework, $reason, $confirmed): void {
            // Serialises with the creation of a new year, so the later years cannot change under us.
            CreateNextFiscalYear::lock($company->id);

            if ($framework === ReportingFramework::K2) {
                self::refuseK3RequiredYears($company, $fiscalYear);
            }

            // Safe: every query below is limited to the year's own company explicitly.
            CompanyScope::bypass(function () use ($user, $company, $fiscalYear, $framework, $reason, $confirmed): void {
                $years = FiscalYear::query()
                    ->where('company_id', $company->id)
                    ->whereDate('start_date', '>=', $fiscalYear->start_date->toDateString())
                    ->orderBy('start_date')
                    ->lockForUpdate()
                    ->get();

                if ($years->first()?->id !== $fiscalYear->id) {
                    throw ValidationException::withMessages(['reporting_framework' => __('Räkenskapsåret finns inte.')]);
                }

                self::refuseLockedYears($company, $years, $fiscalYear);

                $changed = 0;
                $latestChanged = false;
                $defaultChanges = $company->reporting_framework !== $framework;
                $latestId = $years->last()?->id;

                foreach ($years as $year) {
                    $before = $year->reporting_framework;

                    if ($before === $framework) {
                        continue;
                    }

                    $year->forceFill([
                        'reporting_framework' => $framework,
                        'reporting_framework_changed_at' => now(),
                        'reporting_framework_changed_by' => $user->id,
                        'reporting_framework_change_reason' => $reason,
                    ])->save();
                    $changed++;
                    $latestChanged = $latestChanged || $year->id === $latestId;

                    Audit::record('fiscal_year.framework_changed', $year, [
                        'fiscal_year_id' => $year->id,
                        'from_fiscal_year_id' => $fiscalYear->id,
                        'before' => $before->value,
                        'after' => $framework->value,
                        // The company column follows the latest year only.
                        'company_default_changed' => $year->id === $latestId && $defaultChanges,
                        'framework_conditions_confirmed' => $confirmed,
                    ], $company, $user);
                }

                $defaultBefore = $company->reporting_framework;

                if ($defaultBefore !== $framework) {
                    // The company column is the latest year's framework, and the latest year is in the chain.
                    $company->forceFill(['reporting_framework' => $framework])->save();

                    if (! $latestChanged) {
                        // No year entry says the default changed (the latest year already had the framework); recorded here.
                        Audit::record('company.framework_default_synced', $company, [
                            'fiscal_year_id' => $years->last()?->id,
                            'before' => $defaultBefore->value,
                            'after' => $framework->value,
                        ], $company, $user);
                    }
                }

                if ($framework === ReportingFramework::K2 && $confirmed && $company->k2_conditions_confirmed_at === null) {
                    // The K2 conditions were shown and confirmed (owner decision 16): not asked again in the closing.
                    $company->forceFill(['k2_conditions_confirmed_at' => now(), 'k2_conditions_confirmed_by' => $user->id])->save();
                }
            });
        });

        // The caller's model shows the saved framework too.
        $fiscalYear->refresh();

        return $fiscalYear;
    }

    /**
     * S1-R1: K2 is closed for every year from the chosen one on that
     * K3Eligibility finds must use K3 (Bokslut → Regelverk, ADR-059 S1-P11).
     * Run inside the lock and inside the company, never under the scope
     * bypass: the eligibility queries rely on the company scope.
     *
     * @throws ValidationException
     */
    private static function refuseK3RequiredYears(Company $company, FiscalYear $from): void
    {
        $years = CurrentCompany::run($company, fn () => FiscalYear::query()
            ->whereDate('start_date', '>=', $from->start_date->toDateString())
            ->orderBy('start_date')
            ->get());

        foreach ($years as $year) {
            $eligibility = K3Eligibility::forYear($year);

            if ($eligibility->status === K3EligibilityStatus::K3Required) {
                throw ValidationException::withMessages(['reporting_framework' => __('K2 kan inte väljas för räkenskapsåret :year. :reason', ['year' => $year->label(), 'reason' => $eligibility->reasons[0] ?? ''])]);
            }
        }
    }

    /**
     * The years a change can start from: open years without an adopted
     * annual report, where every later year is the same, oldest first.
     *
     * @return Collection<int, FiscalYear>
     */
    public static function eligibleYears(Company $company): Collection
    {
        // Safe: limited to this company explicitly; callers may run without a current company.
        return CompanyScope::bypass(function () use ($company): Collection {
            $years = FiscalYear::query()->where('company_id', $company->id)->orderByDesc('start_date')->get();
            $adopted = self::adoptedYearIds($company);
            $eligible = collect();

            foreach ($years as $year) {
                if (! $year->isOpen() || in_array($year->id, $adopted, true)) {
                    break;
                }

                $eligible->prepend($year);
            }

            return $eligible->values();
        });
    }

    /**
     * Whether choosing the framework from the year changes anything: a year
     * from it on with another framework, or a company default out of step.
     * The year must be one of the eligible years.
     *
     * @param  Collection<int, FiscalYear>  $eligible
     */
    public static function wouldChange(Company $company, ReportingFramework $framework, FiscalYear $from, Collection $eligible): bool
    {
        if ($company->reporting_framework !== $framework) {
            return true;
        }

        return self::yearsToChange($framework, $from, $eligible)->isNotEmpty();
    }

    /**
     * The eligible years from the chosen one on that do not have the framework yet.
     *
     * @param  Collection<int, FiscalYear>  $eligible
     * @return Collection<int, FiscalYear>
     */
    public static function yearsToChange(ReportingFramework $framework, FiscalYear $from, Collection $eligible): Collection
    {
        return $eligible
            ->filter(fn (FiscalYear $year): bool => $year->start_date->greaterThanOrEqualTo($from->start_date) && $year->reporting_framework !== $framework)
            ->values();
    }

    /** @return list<int> */
    private static function adoptedYearIds(Company $company): array
    {
        return array_values(AnnualReport::query()
            ->where('company_id', $company->id)
            ->whereNotNull('adoption_date')
            ->pluck('fiscal_year_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  Collection<int, FiscalYear>  $years  the chosen year and every later one
     *
     * @throws ValidationException
     */
    private static function refuseLockedYears(Company $company, Collection $years, FiscalYear $from): void
    {
        $adopted = self::adoptedYearIds($company);

        foreach ($years as $year) {
            $later = $year->id !== $from->id;

            if (! $year->isOpen()) {
                throw ValidationException::withMessages(['reporting_framework' => $later
                    ? __('Räkenskapsåret :label, som kommer efter :from, är stängt. Regelverket kan bara bytas från ett år där alla senare år är öppna.', ['label' => $year->label(), 'from' => $from->label()])
                    : __('Räkenskapsåret :label är stängt. Regelverket för ett stängt år kan inte ändras.', ['label' => $year->label()]),
                ]);
            }

            if (in_array($year->id, $adopted, true)) {
                throw ValidationException::withMessages(['reporting_framework' => $later
                    ? __('Årsredovisningen för :label, som kommer efter :from, är fastställd. Regelverket kan inte bytas från :from.', ['label' => $year->label(), 'from' => $from->label()])
                    : __('Årsredovisningen för :label är fastställd. Regelverket för året kan inte ändras.', ['label' => $year->label()]),
                ]);
            }
        }
    }
}
