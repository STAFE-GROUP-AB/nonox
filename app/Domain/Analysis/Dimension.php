<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use Closure;

/**
 * A way to group or filter a dataset (ADR-081 gap 7, E17). The SQL of a
 * dimension is written in code and never built from input: `sql` returns the
 * key and the label expression, and a member limited to cost centres or
 * projects gets the masked variant of them (see DatasetSupport).
 */
final class Dimension
{
    /**
     * @param  Closure(AnalysisContext): DimensionSql  $sql  the key and label expressions
     * @param  Closure(AnalysisContext): array<int|string, string>  $options  the values a filter may pick: key => label, already limited to what the member may see
     * @param  bool  $translate  the labels are fixed Swedish phrases that the English translation may replace
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly Closure $sql,
        public readonly Closure $options,
        public readonly bool $translate = false,
    ) {}
}
