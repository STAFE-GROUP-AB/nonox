<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\BudgetActuals;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
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
use Illuminate\Validation\ValidationException;

/**
 * Creates a budget version for a fiscal year: empty, as a copy of another
 * version (any year, also a locked one), or from last year's outcome; a copy
 * and last year's outcome can be changed by a percentage. Months are matched
 * by calendar month (January to January), so a copy into a shorter or longer
 * year keeps only the months that exist there. The budget never writes to
 * the books.
 */
class CreateBudgetVersion
{
    public const string EMPTY = 'empty';

    public const string COPY = 'copy';

    public const string LAST_YEAR = 'last_year';

    /**
     * @param  array<string, mixed>  $input  fiscal_year_id, name, start (empty, copy, last_year), source_version_id, percent
     */
    public function __invoke(User $user, Company $company, array $input): BudgetVersion
    {
        Gate::forUser($user)->authorize('create', [BudgetVersion::class, $company]);

        return CurrentCompany::run($company, function () use ($user, $company, $input): BudgetVersion {
            $validated = Validator::make($input, [
                'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where('company_id', $company->id)],
                'name' => ['required', 'string', 'max:60'],
                'start' => ['required', Rule::in([self::EMPTY, self::COPY, self::LAST_YEAR])],
                'source_version_id' => ['nullable', 'required_if:start,'.self::COPY, 'integer', Rule::exists('budget_versions', 'id')->where('company_id', $company->id)],
                'percent' => ['nullable', 'string', 'max:10'],
            ], [], [
                'fiscal_year_id' => __('räkenskapsår'),
                'name' => __('namn'),
                'source_version_id' => __('version att kopiera'),
                'percent' => __('procent'),
            ])->validate();

            $percent = BudgetMonths::percent((string) ($validated['percent'] ?? ''));

            if ($percent === null || $percent < -10000) {
                throw ValidationException::withMessages(['percent' => __('Skriv förändringen i procent, till exempel 5 eller -2,5.')]);
            }

            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->findOrFail((int) $validated['fiscal_year_id']);
            $source = null;

            if ($validated['start'] === self::LAST_YEAR && BudgetActuals::previousYear($fiscalYear) === null) {
                throw ValidationException::withMessages(['start' => __('Det finns inget räkenskapsår före det här att hämta utfall från.')]);
            }

            if ($validated['start'] === self::COPY) {
                /** @var BudgetVersion $source */
                $source = BudgetVersion::query()->with('fiscalYear')->findOrFail((int) $validated['source_version_id']);
            }

            return DB::transaction(function () use ($user, $company, $validated, $fiscalYear, $source, $percent): BudgetVersion {
                // Two budgets created at once for the same year: the second waits here, so only the first becomes active.
                FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->first();

                $version = BudgetVersion::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'name' => trim((string) $validated['name']),
                    'kind' => BudgetKind::Budget,
                    'source_version_id' => $source?->id,
                    'created_by' => $user->id,
                    // The first budget of a year is the one the reports use.
                    'is_active' => ! BudgetVersion::query()->where('fiscal_year_id', $fiscalYear->id)->where('kind', BudgetKind::Budget->value)->where('is_active', true)->exists(),
                ]);

                $lines = match ($validated['start']) {
                    self::COPY => $this->copied($source, $fiscalYear, $percent),
                    self::LAST_YEAR => $this->lastYear($fiscalYear, $percent),
                    default => [],
                };

                $count = BudgetLines::insert($version, $lines);

                Audit::record('budget.created', $version, [
                    'fiscal_year_id' => $fiscalYear->id,
                    'kind' => $version->kind->value,
                    'start' => $validated['start'],
                    'source_version_id' => $source?->id,
                    'percent_bp' => $percent,
                    'lines' => $count,
                ], $company, $user);

                return $version;
            });
        });
    }

    /**
     * @return list<array{account_id: int, cost_center_id: ?int, project_id: ?int, period: string, amount_ore: int}>
     */
    private function copied(?BudgetVersion $source, FiscalYear $fiscalYear, int $percent): array
    {
        if ($source === null) {
            return [];
        }

        $months = BudgetMonths::of($fiscalYear);
        $offset = BudgetMonths::between($source->fiscalYear->start_date->format('Y-m'), $fiscalYear->start_date->format('Y-m'));
        $lines = [];

        foreach (BudgetScope::lines($source->id)->get(['account_id', 'cost_center_id', 'project_id', 'period', 'amount_ore']) as $line) {
            $month = BudgetMonths::shift($line->period->format('Y-m'), $offset);

            if (! in_array($month, $months, true)) {
                continue;
            }

            $lines[] = [
                'account_id' => $line->account_id,
                'cost_center_id' => $line->cost_center_id,
                'project_id' => $line->project_id,
                'period' => "{$month}-01",
                'amount_ore' => $percent === 0 ? $line->amount_ore->ore : BudgetMonths::adjusted($line->amount_ore->ore, $percent),
            ];
        }

        return $lines;
    }

    /**
     * Last year's outcome per account and month (whole account, not per cost
     * centre or project), rounded to whole kronor and changed by the percentage.
     *
     * @return list<array{account_id: int, cost_center_id: ?int, project_id: ?int, period: string, amount_ore: int}>
     */
    private function lastYear(FiscalYear $fiscalYear, int $percent): array
    {
        $previous = BudgetActuals::previousYear($fiscalYear);

        if ($previous === null) {
            return [];
        }

        $months = BudgetMonths::of($fiscalYear);
        $lines = [];

        foreach (BudgetActuals::perMonth($previous) as $accountId => $perMonth) {
            foreach ($months as $month) {
                $amount = BudgetMonths::adjusted($perMonth[BudgetMonths::shift($month, -12)] ?? 0, $percent);

                if ($amount !== 0) {
                    $lines[] = ['account_id' => (int) $accountId, 'cost_center_id' => null, 'project_id' => null, 'period' => "{$month}-01", 'amount_ore' => $amount];
                }
            }
        }

        return $lines;
    }
}
