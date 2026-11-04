<?php

declare(strict_types=1);

namespace App\Support\CreditData;

use Carbon\CarbonImmutable;

/**
 * What a credit reporting company says about a company (ADR-047). Every
 * field is optional: providers differ. Amounts in öre.
 */
final readonly class CreditReport
{
    public function __construct(
        public string $orgNumber,
        public CarbonImmutable $fetchedAt,
        /** The provider's own rating, as it writes it (e.g. "AAA", "3 av 5"). */
        public ?string $rating = null,
        public ?int $paymentRemarks = null,
        public ?int $turnoverOre = null,
        public ?int $turnoverYear = null,
        /** When the CEO last changed, if the provider tells. Never the person's name: the CRM does not need it. */
        public ?CarbonImmutable $ceoChangedOn = null,
    ) {}
}
