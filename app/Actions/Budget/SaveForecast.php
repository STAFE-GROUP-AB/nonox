<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\ForecastEngine;
use App\Domain\Budget\ForecastSettings;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves a draft forecast and computes it again: the outcome up to the cut-off
 * is read anew from the books (a voucher posted late is counted), every month
 * after it follows its row's method, and the months typed by hand are kept
 * as they are. The cut-off may be moved to a later or earlier ended month;
 * months typed by hand that are no longer after it are dropped. Never writes
 * a voucher.
 */
class SaveForecast
{
    /**
     * @param  array<string, mixed>  $input  cutoff, revenue_method, cost_method, average_months, growth, row_methods, overrides (row key => ['Y-m' => ledger öre]), extra_rows (list of row keys)
     */
    public function __invoke(User $user, BudgetVersion $version, array $input): BudgetVersion
    {
        Gate::forUser($user)->authorize('update', $version);

        if ($version->kind !== BudgetKind::Forecast) {
            throw ValidationException::withMessages(['version' => __('Det här är ingen prognos.')]);
        }

        $company = $version->company_id;

        return CurrentCompany::run($company, function () use ($user, $company, $version, $input): BudgetVersion {
            $fiscalYear = $version->loadMissing('fiscalYear')->fiscalYear;
            $cutoff = ForecastInput::cutoff($fiscalYear, $input['cutoff'] ?? $version->forecast_cutoff?->format('Y-m'));
            $settings = ForecastInput::settings($input);
            $overrides = ForecastInput::overrides($input['overrides'] ?? [], $fiscalYear, $cutoff);
            $extraRows = array_values(array_filter(array_map('strval', is_array($input['extra_rows'] ?? null) ? $input['extra_rows'] : []), ForecastEngine::validKey(...)));

            ForecastInput::checkRows(array_values(array_unique([...$extraRows, ...array_keys($overrides), ...array_keys($settings->rowMethods)])));
            ForecastInput::checkBudget($settings, $fiscalYear);

            return DB::transaction(function () use ($user, $company, $version, $fiscalYear, $cutoff, $settings, $overrides, $extraRows): BudgetVersion {
                // Serialises two saves of the same forecast; the second one wins whole.
                BudgetVersion::query()->whereKey($version->id)->lockForUpdate()->first();

                $forecast = ForecastEngine::compute($fiscalYear, $cutoff, $settings, $overrides, $extraRows);
                $keys = array_column($forecast['rows'], 'key');
                $settings = new ForecastSettings($settings->revenue, $settings->costs, $settings->averageMonths, $settings->growthBasisPoints, array_intersect_key($settings->rowMethods, array_flip($keys)), $forecast['budget_version_id']);

                $removed = BudgetLine::query()->where('budget_version_id', $version->id)->delete();
                $lines = ForecastEngine::lines($forecast['rows']);
                $written = BudgetLines::insert($version, $lines);

                $version->update([
                    'forecast_cutoff' => ForecastInput::cutoffDate($cutoff),
                    'method' => $settings->toArray(),
                ]);
                $version->touch();

                Audit::record('budget.forecast_saved', $version, [
                    'cutoff' => $cutoff,
                    ...CreateForecast::auditSettings($settings),
                    'rows' => count($keys),
                    'lines_before' => $removed,
                    'lines_after' => $written,
                    'overrides' => count(array_filter($lines, fn (array $line): bool => $line['is_override'])),
                ], $company, $user);

                return $version;
            });
        });
    }
}
