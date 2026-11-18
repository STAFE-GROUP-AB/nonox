<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Models\BudgetLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place the budget reads a version's lines (ADR-081 principle 9).
 * Dimension scopes (gap 10, E1) limit it to the member's own cost centres
 * and projects once they exist; until then every member who may see the
 * budget sees every line.
 */
final class BudgetScope
{
    /** @return Builder<BudgetLine> */
    public static function lines(int $versionId): Builder
    {
        return BudgetLine::query()->where('budget_lines.budget_version_id', $versionId);
    }

    /**
     * The lines of several versions at once (Rullande 12 månader reads two).
     *
     * @param  list<int>  $versionIds
     * @return Builder<BudgetLine>
     */
    public static function linesOf(array $versionIds): Builder
    {
        return BudgetLine::query()->whereIn('budget_lines.budget_version_id', $versionIds);
    }

    /**
     * The lines of the active budget (kind BUDGET, not a forecast) of each of
     * these fiscal years, for the project results (ADR-081 E7). The caller
     * narrows them to the projects a member may see.
     *
     * @param  list<int>  $fiscalYearIds
     * @return Builder<BudgetLine>
     */
    public static function activeBudgetLines(array $fiscalYearIds): Builder
    {
        return BudgetLine::query()
            ->join('budget_versions', fn ($join) => $join->on('budget_versions.id', '=', 'budget_lines.budget_version_id')
                ->on('budget_versions.company_id', '=', 'budget_lines.company_id'))
            ->whereIn('budget_versions.fiscal_year_id', $fiscalYearIds)
            ->where('budget_versions.kind', BudgetKind::Budget->value)
            ->where('budget_versions.is_active', true);
    }
}
