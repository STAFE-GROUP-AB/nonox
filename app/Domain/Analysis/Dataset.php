<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * One dataset of the analysis view (ADR-081 gap 7, E17): a fixed set of named
 * dimensions and measures over company-scoped tables, and the permission it
 * needs. No dataset reads SQL, column names or expressions from input.
 */
interface Dataset
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** Whether the member may use the dataset: its permission, and for a limited member only a dataset that applies the scope. */
    public function allows(User $user, Company $company): bool;

    /** @return array<string, Dimension> */
    public function dimensions(): array;

    /** @return array<string, Measure> */
    public function measures(): array;

    /** @return list<string> the dimension keys shown when nothing is chosen */
    public function defaultRows(): array;

    /**
     * SQL expression of the date the period is read from.
     *
     * @return literal-string
     */
    public function dateExpression(): string;

    /** The tables, joins and the scope; no columns, no period. */
    public function query(AnalysisContext $context): Builder;

    /** Whether a cell can be opened into the underlying records. */
    public function drillable(): bool;

    /**
     * The records behind a cell: the query is already narrowed to the cell and
     * the period. At most DRILL_LIMIT + 1 records are read.
     *
     * @return array{columns: array<int, string>, rows: array<int, array{cells: array<int, string>, url: ?string}>}
     */
    public function detail(AnalysisContext $context, Builder $query): array;

    /** The route name of the list page the records live on, when there is one. */
    public function listRoute(): ?string;
}
