<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Budget\BudgetCsv;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
use App\Models\BudgetVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A budget version's grid, and the grid as a CSV file. Route model binding
 * goes through the company scope, so another company's version answers 404.
 */
class BudgetController
{
    public function show(BudgetVersion $budgetVersion): View
    {
        Gate::authorize('view', $budgetVersion);

        return view('accounting.budget', ['version' => $budgetVersion->load('fiscalYear')]);
    }

    public function csv(BudgetVersion $budgetVersion): StreamedResponse
    {
        Gate::authorize('view', $budgetVersion);

        $budgetVersion->load('fiscalYear');
        $months = BudgetMonths::of($budgetVersion->fiscalYear);
        $rows = [];

        $lines = BudgetScope::lines($budgetVersion->id)
            ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'budget_lines.cost_center_id')
            ->leftJoin('projects', 'projects.id', '=', 'budget_lines.project_id')
            ->orderBy('accounts.number')->orderBy('cost_centers.code')->orderBy('projects.code')
            ->select('accounts.number', 'accounts.name', 'cost_centers.code as cost_center', 'projects.code as project', 'budget_lines.period', 'budget_lines.amount_ore')
            ->toBase()
            ->get();

        foreach ($lines as $line) {
            $key = "{$line->number}|{$line->cost_center}|{$line->project}";
            $rows[$key] ??= ['number' => (int) $line->number, 'name' => (string) $line->name, 'cost_center' => (string) $line->cost_center, 'project' => (string) $line->project, 'amounts' => []];
            $rows[$key]['amounts'][substr((string) $line->period, 0, 7)] = (int) $line->amount_ore;
        }

        $csv = BudgetCsv::write($months, array_values($rows));
        $name = 'budget_'.Str::slug($budgetVersion->name).'_'.$budgetVersion->fiscalYear->start_date->format('Y').'.csv';

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
