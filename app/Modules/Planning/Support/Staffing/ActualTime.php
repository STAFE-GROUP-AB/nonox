<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Staffing;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Where Bemanning och kostnad reads actual hours from (PLANNING_MODULE.md
 * §7). Each source answers for the current company in a fixed number of
 * queries, whatever the number of days and people.
 */
interface ActualTime
{
    /** attested | ledger */
    public function key(): string;

    /** The days in from–to this source covers, and the work on them; null when the source is not available. */
    public function between(Company $company, CarbonImmutable $from, CarbonImmutable $to): ?ActualPeriod;
}
