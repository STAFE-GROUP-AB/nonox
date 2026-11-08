<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Signing\SigningFollowUp;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * What follows a signature (ADR-046), in the background so the signer's
 * page answers at once: the order (and invoice draft), the won deal, the
 * CRM activity, the notices and the e-mailed copies. Idempotent
 * (followed_up_at under a row lock), so a retry does nothing twice.
 */
class FollowUpSigning implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 3;

    public function __construct(public int $companyId, public int $requestId) {}

    public function handle(SigningFollowUp $followUp): void
    {
        CurrentCompany::run($this->companyId, fn () => $followUp->signed($this->companyId, $this->requestId));
    }

    /** After the last attempt: the signature stands; the sender makes the order by hand. */
    public function failed(): void
    {
        CurrentCompany::run($this->companyId, fn () => app(SigningFollowUp::class)->failed($this->companyId, $this->requestId));
    }
}
