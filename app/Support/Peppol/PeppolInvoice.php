<?php

declare(strict_types=1);

namespace App\Support\Peppol;

/**
 * An invoice read from an electronic invoice file. Amounts are öre in the
 * invoice currency; nothing is converted.
 */
final readonly class PeppolInvoice
{
    /**
     * @param  list<array{description: string, quantity: string, amount_ore: int, vat_rate: ?int}>  $lines  amount excluding VAT
     * @param  array<int, array{taxable_ore: int, vat_ore: int}>  $vatBreakdown  VAT percent => sums
     * @param  ?array{filename: string, mime: string, contents: string}  $embeddedDocument  the invoice image (usually a PDF) attached to the XML
     */
    public function __construct(
        public bool $isCreditNote,
        public string $invoiceNumber,
        public string $issueDate,
        public ?string $dueDate,
        public string $currency,
        public string $supplierName,
        public ?string $supplierOrgNumber,
        public ?string $supplierVatNumber,
        public ?string $supplierEmail,
        public ?string $ocr,
        public ?string $bankgiro,
        public ?string $plusgiro,
        public ?string $iban,
        public ?string $bic,
        public int $totalExclVatOre,
        public int $vatOre,
        public int $payableOre,
        public array $lines,
        public array $vatBreakdown,
        public ?array $embeddedDocument,
        public ?string $buyerReference,
        // BT-114 PayableRoundingAmount: öresavrundning, so lines + VAT + rounding = payable.
        public int $roundingOre = 0,
    ) {}
}
