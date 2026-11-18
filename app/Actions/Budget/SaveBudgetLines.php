<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Models\Account;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves the whole grid of a draft budget version: the given rows replace the
 * version's lines. A row is one result account, optionally with one cost
 * centre and one project; its amounts are per month in ledger sign (debit
 * positive). Zero months are not stored. Six queries plus one INSERT per 500
 * lines, whatever the size of the grid.
 */
class SaveBudgetLines
{
    /**
     * @param  list<array{account_id: int, cost_center_id: ?int, project_id: ?int, amounts: array<string, int>}>  $rows
     */
    public function __invoke(User $user, BudgetVersion $version, array $rows): BudgetVersion
    {
        Gate::forUser($user)->authorize('update', $version);

        // A forecast's months up to the cut-off are the books' outcome; it is saved by SaveForecast only.
        if ($version->kind === BudgetKind::Forecast) {
            throw ValidationException::withMessages(['version' => __('En prognos ändras i prognosens eget rutnät.')]);
        }

        $company = $version->company_id;

        return CurrentCompany::run($company, function () use ($user, $company, $version, $rows): BudgetVersion {
            $months = BudgetMonths::of($version->loadMissing('fiscalYear')->fiscalYear);
            $this->validate($rows, $months);

            return DB::transaction(function () use ($user, $company, $version, $rows): BudgetVersion {
                // Serialises two saves of the same version; the second one wins whole.
                BudgetVersion::query()->whereKey($version->id)->lockForUpdate()->first();

                $removed = BudgetLine::query()->where('budget_version_id', $version->id)->delete();
                $lines = [];

                foreach ($rows as $row) {
                    foreach ($row['amounts'] as $month => $amount) {
                        $lines[] = [
                            'account_id' => $row['account_id'],
                            'cost_center_id' => $row['cost_center_id'],
                            'project_id' => $row['project_id'],
                            'period' => "{$month}-01",
                            'amount_ore' => $amount,
                        ];
                    }
                }

                $written = BudgetLines::insert($version, $lines);
                $version->touch();

                Audit::record('budget.lines_saved', $version, [
                    'rows' => count($rows),
                    'lines_before' => $removed,
                    'lines_after' => $written,
                ], $company, $user);

                return $version;
            });
        });
    }

    /**
     * @param  list<array{account_id: int, cost_center_id: ?int, project_id: ?int, amounts: array<string, int>}>  $rows
     * @param  list<string>  $months
     */
    private function validate(array $rows, array $months): void
    {
        $accounts = Account::query()->whereIn('id', array_column($rows, 'account_id'))->pluck('number', 'id');
        $costCenters = CostCenter::query()->whereIn('id', array_filter(array_column($rows, 'cost_center_id')))->pluck('id', 'id');
        $projects = Project::query()->whereIn('id', array_filter(array_column($rows, 'project_id')))->pluck('id', 'id');
        $seen = [];

        foreach ($rows as $index => $row) {
            $number = $accounts[$row['account_id']] ?? null;

            if ($number === null || ! BudgetMonths::isBudgetable((int) $number)) {
                throw ValidationException::withMessages(["rows.{$index}" => __('Budgeten gäller resultatkonton (3000–8998).')]);
            }

            if ($row['cost_center_id'] !== null && ! isset($costCenters[$row['cost_center_id']])) {
                throw ValidationException::withMessages(["rows.{$index}" => __('Kostnadsstället finns inte.')]);
            }

            if ($row['project_id'] !== null && ! isset($projects[$row['project_id']])) {
                throw ValidationException::withMessages(["rows.{$index}" => __('Projektet finns inte.')]);
            }

            $key = "{$row['account_id']}|{$row['cost_center_id']}|{$row['project_id']}";

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["rows.{$index}" => __('Samma konto, kostnadsställe och projekt finns redan på en annan rad.')]);
            }

            $seen[$key] = true;

            foreach (array_keys($row['amounts']) as $month) {
                if (! in_array((string) $month, $months, true)) {
                    throw ValidationException::withMessages(["rows.{$index}" => __('Månaden :month ingår inte i räkenskapsåret.', ['month' => $month])]);
                }
            }
        }
    }
}
