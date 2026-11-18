<?php

declare(strict_types=1);

namespace App\Domain\Review;

use Carbon\CarbonImmutable;

/**
 * One item in the approval queue (ADR-067 §2). The title carries no personal
 * data (no supplier or customer name in the list); who prepared it is a
 * member of the company, named for the people who decide.
 */
final readonly class ReviewItem
{
    public function __construct(
        public string $source,
        public string $kind,
        public string $id,
        public string $title,
        public CarbonImmutable $date,
        public ?int $amountOre,
        public ReviewPreparer $preparedBy,
        public ?int $preparerId,
        public string $reason,
        public ?string $url,
        public bool $readOnly = false,
        public bool $stale = false,
        public ?int $onBehalfOf = null,
    ) {}
}
