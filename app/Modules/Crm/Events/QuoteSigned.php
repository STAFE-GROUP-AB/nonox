<?php

declare(strict_types=1);

namespace App\Modules\Crm\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A quote was signed at its signing link (ADR-046). Dispatched after commit,
 * with ids only; NONOX API turns it into the webhook quote.signed.
 */
final readonly class QuoteSigned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public int $signingRequestId,
    ) {}
}
