<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

use Carbon\CarbonImmutable;

/**
 * A balance as the bank reports it. The type is the ISO 20022 code: CLBD
 * (closing booked) is what a statement shows; ITBD, ITAV, CLAV and XPCD are
 * interim or available balances.
 */
final readonly class ProviderBalance
{
    public function __construct(
        public string $type,
        public int $amountOre,
        public string $currency,
        public CarbonImmutable $date,
    ) {}
}
