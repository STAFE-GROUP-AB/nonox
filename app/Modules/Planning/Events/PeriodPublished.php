<?php

declare(strict_types=1);

namespace App\Modules\Planning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A period of the schedule was published (PublishPeriod). Dispatched after
 * commit, with ids only, for the planning.published webhook (ADR-032).
 */
final readonly class PeriodPublished implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public int $publicationId,
    ) {}
}
