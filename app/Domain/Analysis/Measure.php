<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use Closure;

/**
 * A number a dataset can add up. Every measure is additive (a sum or a count),
 * so totals are the sum of what is shown. The SQL is a function of the period
 * condition, so one query gives both the period and its comparison.
 */
final class Measure
{
    public const string MONEY = 'money';

    public const string COUNT = 'count';

    /**
     * @param  Closure(literal-string): literal-string  $aggregate  the SQL, given the period condition
     * @param  (Closure(literal-string): literal-string)|null  $suppressed  SQL (boolean), given the period condition: the cell is hidden when true (small groups of people)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $kind,
        public readonly Closure $aggregate,
        public readonly ?Closure $suppressed = null,
    ) {}
}
