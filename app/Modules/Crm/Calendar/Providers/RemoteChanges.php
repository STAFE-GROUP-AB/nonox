<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

/**
 * Changed events in a connected calendar since the last read, and where the
 * next read starts. The caller keeps only the events it wrote itself.
 */
final readonly class RemoteChanges
{
    /** @param list<RemoteChange> $changes */
    public function __construct(
        public array $changes,
        public ?string $cursor,
    ) {}
}
