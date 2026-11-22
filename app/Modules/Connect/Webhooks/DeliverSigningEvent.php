<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Events\SigningEnvelopeChanged;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The fan-out of one signing event to the company's webhooks (ADR-046 S19),
 * off the request that signed: it carries ids only and runs in the company
 * given explicitly (AGENTS.md §6.0). Runs once (a retry could store the same
 * event twice); a failure is the job's own and never reaches a signer.
 */
final class DeliverSigningEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use RunsForCompany;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $companyId, public string $envelopeId, public int $signingRequestId, public string $change) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $dispatcher->signingChanged(new SigningEnvelopeChanged($this->companyId, $this->envelopeId, $this->signingRequestId, $this->change));
    }
}
