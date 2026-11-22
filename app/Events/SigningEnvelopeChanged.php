<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An envelope moved on (ADR-046 S19): a recipient signed, the last one
 * signed, a recipient declined, or the last day passed. Written by the event
 * chain when it records the step, so every path that signs, declines or
 * expires an envelope announces it once, and dispatched after commit with
 * ids only. NONOX API turns it into the webhooks signing.recipient_signed,
 * signing.completed, signing.declined and signing.expired.
 */
final readonly class SigningEnvelopeChanged implements ShouldDispatchAfterCommit
{
    public const string RECIPIENT_SIGNED = 'recipient_signed';

    public const string COMPLETED = 'completed';

    public const string DECLINED = 'declined';

    public const string EXPIRED = 'expired';

    public function __construct(
        public int $companyId,
        public string $envelopeId,
        public int $signingRequestId,
        public string $change,
    ) {}
}
