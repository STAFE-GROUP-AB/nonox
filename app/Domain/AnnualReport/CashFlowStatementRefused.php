<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use DomainException;

/**
 * A K3 cash flow statement that cannot be drawn up (ADR-059 A.4 S1-P8): an
 * account with a movement that no category of CashFlowAccounts covers, a
 * movement NONOX cannot yet place, a statement that does not reconcile with
 * likvida medel, a policy NONOX does not build (the direct method), or no
 * statement where ÅRL 2 kap. 1 § andra stycket requires one. The reason is
 * Swedish and names the accounts behind it; nothing is ever put on an
 * "övrigt" row instead (spec Behaviour 4).
 */
final class CashFlowStatementRefused extends DomainException
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
