<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use DomainException;

/**
 * A K3 balance sheet whose two sides differ by more than rounding to whole
 * kronor can explain (ADR-059 A.3, S1-P6 review): PrintedFigures refuses to
 * print it instead of putting the difference on Övriga skulder. The usual
 * cause is a balance on an account that no line of the schedule takes.
 */
final class UnbalancedBalanceSheet extends DomainException
{
    /**
     * @param  int  $difference  exact assets minus exact equity and liabilities, in öre
     * @param  'amount'|'previous'  $column  this year or the previous year
     */
    public function __construct(
        public readonly int $difference,
        public readonly string $column,
    ) {
        parent::__construct("The balance sheet ({$column}) does not balance: assets minus equity and liabilities is {$difference} öre.");
    }
}
