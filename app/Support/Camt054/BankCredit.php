<?php

declare(strict_types=1);

namespace App\Support\Camt054;

use Carbon\CarbonImmutable;

/**
 * One incoming payment as the bank reported it.
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
    ) {}
}
