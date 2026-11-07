<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes the K-regelverk of one fiscal year (ADR-059, addendum A.2.1).
 * The framework belongs to the year: BFNAR 2025:2 and 2025:3 apply by the
 * year's start (S1-R1.4) and a change of framework happens at a year
 * boundary. The company column follows the latest year, so a change of the
 * latest year also changes it; it is the default for the next year.
 *
 * Refused for a closed year and for a year whose annual report has been
 * adopted: the report of that year was made under the old framework.
 * The audit entry has ids and enums only; the reason stays in its column.
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

        $year = DB::transaction(function () use ($user, $company, $fiscalYear, $framework, $reason, $confirmed): FiscalYear {
            // Serialises with the creation of a new year, so "the latest year" cannot change under us.
            CreateNextFiscalYear::lock($company->id);

            // Safe: every query below is limited to the year's own company explicitly.
            return CompanyScope::bypass(function () use ($user, $company, $fiscalYear, $framework, $reason, $confirmed): FiscalYear {
                $year = FiscalYear::query()->where('company_id', $company->id)->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

                if (! $year->isOpen()) {
                    throw ValidationException::withMessages([
                        'reporting_framework' => __('Räkenskapsåret :label är stängt. Regelverket för ett stängt år kan inte ändras.', ['label' => $year->label()]),
                    ]);
                }

                if (AnnualReport::query()->where('company_id', $company->id)->where('fiscal_year_id', $year->id)->whereNotNull('adoption_date')->exists()) {
                    throw ValidationException::withMessages([
                        'reporting_framework' => __('Årsredovisningen för :label är fastställd. Regelverket för året kan inte ändras.', ['label' => $year->label()]),
                    ]);
                }

                $before = $year->reporting_framework;
                $latest = ! FiscalYear::query()->where('company_id', $company->id)->whereDate('start_date', '>', $year->start_date->toDateString())->exists();

                if ($before === $framework) {
                    if ($latest && $company->reporting_framework !== $framework) {
                        // Only the default was out of step with the latest year; the year itself is unchanged.
                        $company->forceFill(['reporting_framework' => $framework])->save();
                    }

                    return $year;
                }

                $year->forceFill([
                    'reporting_framework' => $framework,
                    'reporting_framework_changed_at' => now(),
                    'reporting_framework_changed_by' => $user->id,
                    'reporting_framework_change_reason' => $reason,
                ])->save();

                if ($latest) {
                    $company->forceFill(['reporting_framework' => $framework])->save();
                }

                if ($framework === ReportingFramework::K2 && $confirmed && $company->k2_conditions_confirmed_at === null) {
                    // The K2 conditions were shown and confirmed (owner decision 16): not asked again in the closing.
                    $company->forceFill(['k2_conditions_confirmed_at' => now(), 'k2_conditions_confirmed_by' => $user->id])->save();
                }

                Audit::record('fiscal_year.framework_changed', $year, [
                    'fiscal_year_id' => $year->id,
                    'before' => $before->value,
                    'after' => $framework->value,
                    'company_default_changed' => $latest,
                    'framework_conditions_confirmed' => $confirmed,
                ], $company, $user);

                return $year;
            });
        });

        // The caller's model shows the saved framework too.
        $fiscalYear->setRawAttributes($year->getAttributes(), true);

        return $fiscalYear;
    }
}
