<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A SIE file that arrived over SFTP has been checked: either it waits as a
 * SIE import for a person to confirm, or it was rejected (ADR-032, "SFTP").
 * Dispatched after commit; the webhook event sie.uploaded is built from it.
 */
final readonly class SieFileUploaded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public string $uploadId,
    ) {}
}
