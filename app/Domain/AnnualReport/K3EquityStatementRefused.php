<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use DomainException;

/**
 * A K3 specification of changes in equity that cannot be drawn up (ADR-059
 * A.1 point 5, S1-P7): a change that fits none of the rows of K3 6.3, or a
 * rounding leftover that no row can take. K3 6.3 has no row for "övriga
 * förändringar", so the report is refused with a reason the user can act on
 * instead of printing a residual (S1-R8.3). The reason is Swedish and names
 * the accounts (and vouchers) behind it.
 */
final class K3EquityStatementRefused extends DomainException
{
    /**
     * @param  string  $reason  the refusal as the user sees it
     * @param  list<int>  $accounts  the BAS accounts that cause it, ascending
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $accounts = [],
    ) {
        parent::__construct($reason);
    }
}
