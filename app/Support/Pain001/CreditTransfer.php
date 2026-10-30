<?php

declare(strict_types=1);

namespace App\Support\Pain001;

/**
 * One payment in a payment file: to a Swedish bankgiro or plusgiro number,
 * or to a bank account (clearing number and account number, for salaries),
 * with an OCR reference or a short message.
 */
final readonly class CreditTransfer
{
    public const string BANKGIRO = 'BGNR';

    public const string PLUSGIRO = 'PGNR';

    public const string BANK_ACCOUNT = 'BBAN';

    public function __construct(
        public string $endToEndId,
        public int $amountOre,
        public string $creditorName,
        public string $scheme,
        public string $accountNumber,
        public ?string $ocr = null,
        public ?string $message = null,
        public ?string $clearing = null,
    ) {}
}
