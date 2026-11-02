<?php

declare(strict_types=1);

namespace App\Modules\Planning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Time in the schedule was attested (AttestDay / AttestPeriod). Dispatched
 * after commit through PlanningWebhooks::attested(), with dates and ids
 * only, for the planning.attested webhook (ADR-032).
 */
final readonly class PeriodAttested implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $from  first day, Y-m-d
     * @param  string  $to  last day, Y-m-d
     * @param  list<int|string>  $attestationIds
     * @param  list<int>  $employeeIds
     */
    public function __construct(
        public int $companyId,
        public string $from,
        public string $to,
        public array $attestationIds,
        public array $employeeIds,
        public int $attestedMinutes,
    ) {}
}
