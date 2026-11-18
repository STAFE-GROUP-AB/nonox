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
}
