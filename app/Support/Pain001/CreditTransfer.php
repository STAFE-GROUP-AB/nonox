<?php

declare(strict_types=1);

namespace App\Support\Pain001;

/**
 * One payment in a payment file: to a Swedish bankgiro or plusgiro number,
 * or to a bank account (clearing number and account number, for salaries),
 * with an OCR reference or a short message. A payment abroad (ADR-014
 * addendum) is to an IBAN in a foreign currency: then `amountOre` is in
 * hundredths of `currency`, and `estimatedOre` the kronor expected.
 */
final readonly class CreditTransfer
{
    public const string BANKGIRO = 'BGNR';

    public const string PLUSGIRO = 'PGNR';

    public const string BANK_ACCOUNT = 'BBAN';

    /** A payment abroad to an account given as IBAN. */
    public const string IBAN = 'IBAN';

    public function __construct(
        public string $endToEndId,
        public int $amountOre,
        public string $creditorName,
        public string $scheme,
        public string $accountNumber,
        public ?string $ocr = null,
        public ?string $message = null,
        public ?string $clearing = null,
        public string $currency = 'SEK',
        public ?string $bic = null,
        public ?string $creditorCountry = null,
        public ?string $creditorTown = null,
        public ?string $creditorPostalCode = null,
        public ?string $creditorAddressLine = null,
        public ?string $regulatoryCode = null,
        public bool $sepa = false,
        public ?int $estimatedOre = null,
    ) {}

    public function isForeign(): bool
    {
        return $this->currency !== 'SEK';
    }
}
