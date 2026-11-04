<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

use Carbon\CarbonImmutable;

/** One changed event: moved (new start and end) or cancelled or deleted. */
final readonly class RemoteChange
{
    public function __construct(
        public string $externalId,
        public bool $cancelled,
        public ?CarbonImmutable $start = null,
        public ?CarbonImmutable $end = null,
    ) {}
}
