<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer price agreement stopped applying from a date (ADR-082 P9):
 * terminated, expired or replaced by a new version. Ids, the reason as a key
 * and the last day only; NONOX API turns it into the webhook
 * price_agreement.ended.
 */
final readonly class PriceAgreementEnded implements ShouldDispatchAfterCommit
{
    public const string TERMINATED = 'terminated';

    public const string EXPIRED = 'expired';

    public const string SUPERSEDED = 'superseded';

    public function __construct(
        public int $companyId,
        public int $priceAgreementId,
        public string $reason,
        public ?string $lastDay = null,
    ) {}
}
