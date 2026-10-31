<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

/**
 * Scans an attachment for malware. Bound to ClamdScanner when a clamd
 * socket is configured (services.inbox.clamd_socket), otherwise to
 * NoVirusScanner, and then dangerous file types are blocked instead
 * (DangerousContent). Tests bind a fake.
 */
interface VirusScanner
{
    public function enabled(): bool;

    /**
     * Null when the contents are clean, otherwise the reason in Swedish.
     *
     * @throws VirusScanFailed when the scanner could not be reached
     */
    public function scan(string $contents): ?string;
}
