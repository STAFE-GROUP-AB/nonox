<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

/**
 * Used when no clamd socket is configured: nothing is scanned, and the
 * inbox blocks dangerous file types instead.
 */
final class NoVirusScanner implements VirusScanner
{
    public function enabled(): bool
    {
        return false;
    }

    public function scan(string $contents): ?string
    {
        return null;
    }
}
