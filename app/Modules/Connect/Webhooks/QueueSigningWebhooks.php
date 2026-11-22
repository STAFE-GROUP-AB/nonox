<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Events\SigningEnvelopeChanged;
use Throwable;

/**
 * The after-commit listener of SigningEnvelopeChanged (ADR-046 S19). It only
 * queues a small job with ids, and never throws: an after-commit listener runs
 * inside the signer's request and the hourly reminder run, so a queue that is
 * down must not turn a signature into an error page, skip the next step's
 * mail or stop the run. The failure is reported and the event is lost for the
 * webhooks only; the signing itself is untouched.
 */
final class QueueSigningWebhooks
{
    public function handle(SigningEnvelopeChanged $event): void
    {
        try {
            DeliverSigningEvent::dispatch($event->companyId, $event->envelopeId, $event->signingRequestId, $event->change);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
