<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/**
 * The SQL of a dimension: the expression that gives the group's key and the
 * one that gives its label. Both are written in code (literal strings); the
 * only value that ever reaches them is the member's own list of cost centre or
 * project ids, as one bound parameter shared by every `?` in the two
 * expressions (an array literal, "{1,2}").
 */
final class DimensionSql
{
    /**
     * @param  literal-string  $key
     * @param  literal-string  $label
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $binding = null,
    ) {}

    /**
     * @param  literal-string  $expression
     * @return list<string> one value for each `?` in the expression
     */
    public function bindingsFor(string $expression): array
    {
        return $this->binding === null ? [] : array_fill(0, substr_count($expression, '?'), $this->binding);
    }
}
