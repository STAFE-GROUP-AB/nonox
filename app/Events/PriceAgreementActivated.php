<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer price agreement became active, signed at the link or activated
 * by hand (ADR-082 P9). Ids only; NONOX API turns it into the webhook
 * price_agreement.activated.
 */
final readonly class PriceAgreementActivated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public int $priceAgreementId,
    ) {}
}
