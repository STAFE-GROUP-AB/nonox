<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * An account's key figures from the ledger (ADR-043). Amounts in öre:
 * sales excluding VAT with credit invoices deducted, receivables including
 * VAT (what the customer owes).
 */
final readonly class KeyFigures
{
    public function __construct(
        public int $invoiced12Months = 0,
        public int $invoicedTotal = 0,
        public int $open = 0,
        public int $overdue = 0,
        /** Days from invoice date to payment, weighted by amount, for payments of the last 12 months; null without payments. */
        public ?float $paymentDays = null,
        /** Net sales of the last 12 months on rows whose article has a purchase price; the base of the margin. */
        public int $marginBase = 0,
        /** marginBase minus quantity × purchase price; null without such rows. */
        public ?int $margin = null,
        /** The share of the last 12 months' net sales that had a known cost, 0–1; null without sales. */
        public ?float $marginCoverage = null,
    ) {}

    /** The margin as a share of the sales it is computed on, 0–1. */
    public function marginRate(): ?float
    {
        return $this->margin === null || $this->marginBase === 0 ? null : $this->margin / $this->marginBase;
    }
}
