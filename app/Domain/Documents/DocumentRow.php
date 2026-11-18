<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Carbon\CarbonImmutable;

/**
 * One line of the unified document list, ready to show.
 */
final readonly class DocumentRow
{
    public function __construct(
        public string $source,
        public string $sourceId,
        public ?string $parentId,
        public string $title,
        public ?CarbonImmutable $date,
        public ?int $size,
        public string $label,
        public ?string $subtitle,
        public string $url,
    ) {}
}
