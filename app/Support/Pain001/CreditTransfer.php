<?php

declare(strict_types=1);

namespace App\Support\Pain001;

/**
 * One payment in a payment file: to a Swedish bankgiro or plusgiro number,
 * with an OCR reference or a short message.
 */
final readonly class CreditTransfer
{
    public const string BANKGIRO = 'BGNR';

    public const string PLUSGIRO = 'PGNR';

    public function __construct(
        public string $endToEndId,
        public int $amountOre,
        public string $creditorName,
        public string $scheme,
        public string $accountNumber,
        public ?string $ocr = null,
        public ?string $message = null,
    ) {}
}
