<?php

declare(strict_types=1);

namespace App\Support\Camt054;

use Carbon\CarbonImmutable;

/**
 * One incoming payment as the bank reported it. When the payer sent another
 * currency than the account's (a customer paying in euro to a kronor
 * account), the instructed amount and its currency are kept as well.
 */
final readonly class BankCredit
{
    public function __construct(
        public string $reference,
        public CarbonImmutable $bookingDate,
        public int $amountOre,
        public ?string $ocr,
        public ?string $message,
        public ?string $payerName,
        public ?string $foreignCurrency = null,
        public ?int $foreignAmountMinor = null,
    ) {}
}
