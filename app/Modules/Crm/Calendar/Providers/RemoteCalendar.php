<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

/** One of the user's own calendars that NONOX may write to. */
final readonly class RemoteCalendar
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $primary,
    ) {}
}
