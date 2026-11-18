<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Datasets;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Measure;
use App\Domain\Budget\BudgetKind;
use App\Models\BudgetLine;
use App\Support\Money\Money;
use Illuminate\Database\Query\Builder;

/**
 * The lines of each fiscal year's active budget (ADR-081 gap 7, E17): never a
 * forecast, never an inactive version. A member limited to cost centres or
 * projects reads only the lines on their own units, without personnel
 * accounts (DimensionedRowsDataset).
 */
final class BudgetDataset extends DimensionedRowsDataset
{
    public function key(): string
    {
        return 'budget';
    }

    public function label(): string
    {
        return __('Budgetrader');
    }

    public function description(): string
    {
        return __('Raderna i varje räkenskapsårs aktiva budget: konto, månad, kostnadsställe och projekt. Prognoser räknas inte med.');
    }

    protected function rowTable(): string
    {
        return 'budget_lines';
    }

    protected function permission(): string
    {
        return 'budgets:view';
    }

    public function dimensions(): array
    {
        return $this->accountDimensions() + [
            'period' => $this->periodDimension('budget_lines.period'),
            'cost_center' => $this->costCenterDimension(),
            'project' => $this->projectDimension(),
        ];
    }

    public function measures(): array
    {
        return [
            'net' => new Measure('net', __('Budget, debet minus kredit'), Measure::MONEY, fn ($f) => "COALESCE(SUM(budget_lines.amount_ore) FILTER (WHERE {$f}), 0)"),
            'rows' => new Measure('rows', __('Antal budgetrader'), Measure::COUNT, fn ($f) => "COUNT(*) FILTER (WHERE {$f})"),
        ];
    }

    public function defaultRows(): array
    {
        return ['account'];
    }

    public function dateExpression(): string
    {
        return 'budget_lines.period';
    }

    public function listRoute(): string
    {
        return 'accounting.budgets.report';
    }

    public function query(AnalysisContext $context): Builder
    {
        $query = BudgetLine::query()->toBase()
            ->join('budget_versions', fn ($join) => $join->on('budget_versions.id', '=', 'budget_lines.budget_version_id')->on('budget_versions.company_id', '=', 'budget_lines.company_id'))
            ->where('budget_lines.company_id', $context->company->id)
            ->where('budget_versions.kind', BudgetKind::Budget->value)
            ->where('budget_versions.is_active', true);

        $this->joinDimensions($query);

        return $this->applyScope($query, $context);
    }

    public function detail(AnalysisContext $context, Builder $query): array
    {
        $rows = $query
            ->select('budget_lines.period', 'accounts.number AS account', 'accounts.name AS account_name', 'budget_lines.amount_ore')
            ->selectRaw("COALESCE(cost_centers.code, '') AS cost_center, COALESCE(projects.code, '') AS project, cost_centers.id AS cost_center_id, projects.id AS project_id")
            ->orderBy('budget_lines.period')->orderBy('accounts.number')->orderBy('budget_lines.id')
            ->limit(AnalysisRunner::DRILL_LIMIT + 1)
            ->get();

        $costCenters = $context->scope->costCenterIds;
        $projects = $context->scope->projectIds;
        $limited = $context->scope->restricted;

        return [
            'columns' => [__('Månad'), __('Konto'), __('Kostnadsställe'), __('Projekt'), __('Budget, debet minus kredit')],
            'rows' => $rows->map(fn (object $row): array => [
                'cells' => [
                    substr((string) $row->period, 0, 7),
                    "{$row->account} {$row->account_name}",
                    ! $limited || in_array((int) $row->cost_center_id, $costCenters, true) ? (string) $row->cost_center : '',
                    ! $limited || in_array((int) $row->project_id, $projects, true) ? (string) $row->project : '',
                    Money::ofOre((int) $row->amount_ore)->format(),
                ],
                'url' => null,
            ])->all(),
        ];
    }
}
