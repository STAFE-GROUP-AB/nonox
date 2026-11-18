<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
use App\Domain\Budget\ForecastEngine;
use App\Domain\Budget\ForecastSettings;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Makes a rolling forecast for a fiscal year from a cut-off month (ADR-081
 * gap 2 scope 5): the outcome up to the cut-off and, after it, each row by
 * its method (ForecastEngine). Made from an earlier forecast it keeps that
 * forecast's methods and the months typed by hand that are still after the
 * new cut-off. The forecast is a plan next to the books: it never writes a
 * voucher.
 */
class CreateForecast
{
    /**
     * @param  array<string, mixed>  $input  fiscal_year_id, name, cutoff ('Y-m'), revenue_method, cost_method, average_months, growth, source_version_id
     */
    public function __invoke(User $user, Company $company, array $input): BudgetVersion
    {
        Gate::forUser($user)->authorize('create', [BudgetVersion::class, $company]);

        return CurrentCompany::run($company, function () use ($user, $company, $input): BudgetVersion {
            $validated = Validator::make($input, [
                'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where('company_id', $company->id)],
                'name' => ['required', 'string', 'max:60'],
                'cutoff' => ['required', 'string', 'max:10'],
                'source_version_id' => ['nullable', 'integer', Rule::exists('budget_versions', 'id')->where('company_id', $company->id)->where('kind', BudgetKind::Forecast->value)],
            ], [], [
                'fiscal_year_id' => __('räkenskapsår'),
                'name' => __('namn'),
                'cutoff' => __('brytmånad'),
                'source_version_id' => __('prognos att utgå från'),
            ])->validate();

            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->findOrFail((int) $validated['fiscal_year_id']);
            $cutoff = ForecastInput::cutoff($fiscalYear, $validated['cutoff']);
            $settings = ForecastInput::settings($input);
            $source = isset($validated['source_version_id']) ? BudgetVersion::query()->findOrFail((int) $validated['source_version_id']) : null;
            $overrides = [];

            if ($source !== null) {
                $settings = new ForecastSettings($settings->revenue, $settings->costs, $settings->averageMonths, $settings->growthBasisPoints, ForecastSettings::fromArray($source->method)->rowMethods);
                $overrides = $this->carriedOverrides($source, $fiscalYear, $cutoff);
            }

            ForecastInput::checkBudget($settings, $fiscalYear);

            return DB::transaction(function () use ($user, $company, $validated, $fiscalYear, $cutoff, $settings, $source, $overrides): BudgetVersion {
                // Two forecasts made at once for the same year: the second waits here, so only the first becomes active.
                FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->first();

                $forecast = ForecastEngine::compute($fiscalYear, $cutoff, $settings, $overrides);
                $keys = array_column($forecast['rows'], 'key');
                $settings = new ForecastSettings($settings->revenue, $settings->costs, $settings->averageMonths, $settings->growthBasisPoints, array_intersect_key($settings->rowMethods, array_flip($keys)), $forecast['budget_version_id']);

                $version = BudgetVersion::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'name' => trim((string) $validated['name']),
                    'kind' => BudgetKind::Forecast,
                    'forecast_cutoff' => ForecastInput::cutoffDate($cutoff),
                    'source_version_id' => $source?->id,
                    'method' => $settings->toArray(),
                    'created_by' => $user->id,
                    // The first forecast of a year is the one "Rullande 12 månader" reads.
                    'is_active' => ! BudgetVersion::query()->where('fiscal_year_id', $fiscalYear->id)->where('kind', BudgetKind::Forecast->value)->where('is_active', true)->exists(),
                ]);

                $lines = ForecastEngine::lines($forecast['rows']);
                $count = BudgetLines::insert($version, $lines);

                Audit::record('budget.forecast_created', $version, [
                    'fiscal_year_id' => $fiscalYear->id,
                    'kind' => $version->kind->value,
                    'cutoff' => $cutoff,
                    ...self::auditSettings($settings),
                    'source_version_id' => $source?->id,
                    'rows' => count($keys),
                    'lines' => $count,
                    'overrides' => count(array_filter($lines, fn (array $line): bool => $line['is_override'])),
                ], $company, $user);

                return $version;
            });
        });
    }

    /**
     * @return array{revenue_method: string, cost_method: string, average_months: int, growth_bp: int, row_methods: int, budget_version_id: ?int}
     */
    public static function auditSettings(ForecastSettings $settings): array
    {
        return [
            'revenue_method' => $settings->revenue->value,
            'cost_method' => $settings->costs->value,
            'average_months' => $settings->averageMonths,
            'growth_bp' => $settings->growthBasisPoints,
            'row_methods' => count($settings->rowMethods),
            'budget_version_id' => $settings->budgetVersionId,
        ];
    }

    /**
     * The earlier forecast's months typed by hand that are after the new
     * cut-off and inside this fiscal year (matched by calendar month).
     *
     * @return array<string, array<string, int>>
     */
    private function carriedOverrides(BudgetVersion $source, FiscalYear $fiscalYear, string $cutoff): array
    {
        $months = BudgetMonths::of($fiscalYear);
        $overrides = [];

        foreach (BudgetScope::lines($source->id)->where('is_override', true)->toBase()->get(['account_id', 'cost_center_id', 'project_id', 'period', 'amount_ore']) as $line) {
            $month = substr((string) $line->period, 0, 7);

            if ($month <= $cutoff || ! in_array($month, $months, true)) {
                continue;
            }

            $key = ForecastEngine::key((int) $line->account_id, $line->cost_center_id === null ? null : (int) $line->cost_center_id, $line->project_id === null ? null : (int) $line->project_id);
            $overrides[$key][$month] = (int) $line->amount_ore;
        }

        return $overrides;
    }
}
