<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

/**
 * One computed row of the three-way comparison, before it is stored
 * (ADR-081 E11). Quantities in thousandths of the order row's unit, amounts
 * in öre.
 */
final readonly class MatchLine
{
    /**
     * @param  list<int>  $invoiceRowIds
     * @param  list<MatchReason>  $reasons
     */
    public function __construct(
        public ?int $purchaseOrderRowId,
        public array $invoiceRowIds,
        public ?int $stockReceiptLineId,
        public int $orderedMilli,
        public int $receivedMilli,
        public int $previouslyInvoicedMilli,
        public int $invoicedMilli,
        public int $orderedOre,
        public int $receivedOre,
        public int $invoicedOre,
        public int $expectedOre,
        public int $quantityDiffMilli,
        public int $priceDiffOre,
        public MatchTolerances $tolerances,
        public MatchStatus $status,
        public array $reasons,
    ) {}

    /**
     * A hash of everything the result says, so an unchanged comparison is
     * recognised and an override follows it to a new set only when nothing
     * it judged has changed.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->purchaseOrderRowId,
            $this->invoiceRowIds,
            $this->stockReceiptLineId,
            $this->orderedMilli,
            $this->receivedMilli,
            $this->previouslyInvoicedMilli,
            $this->invoicedMilli,
            $this->orderedOre,
            $this->receivedOre,
            $this->invoicedOre,
            $this->expectedOre,
            $this->quantityDiffMilli,
            $this->priceDiffOre,
            $this->tolerances->quantityBp,
            $this->tolerances->priceBp,
            $this->tolerances->absoluteOre,
            $this->status->value,
            array_map(fn (MatchReason $reason): string => $reason->value, $this->reasons),
        ]));
    }

    /** @return array<string, mixed> the columns of purchase_match_results */
    public function attributes(): array
    {
        return [
            'purchase_order_row_id' => $this->purchaseOrderRowId,
            'supplier_invoice_row_id' => count($this->invoiceRowIds) === 1 ? $this->invoiceRowIds[0] : null,
            'invoice_row_ids' => $this->invoiceRowIds,
            'stock_receipt_line_id' => $this->stockReceiptLineId,
            'ordered_milli' => $this->orderedMilli,
            'received_milli' => $this->receivedMilli,
            'previously_invoiced_milli' => $this->previouslyInvoicedMilli,
            'invoiced_milli' => $this->invoicedMilli,
            'ordered_ore' => $this->orderedOre,
            'received_ore' => $this->receivedOre,
            'invoiced_ore' => $this->invoicedOre,
            'expected_ore' => $this->expectedOre,
            'quantity_diff_milli' => $this->quantityDiffMilli,
            'price_diff_ore' => $this->priceDiffOre,
            'quantity_tolerance_bp' => $this->tolerances->quantityBp,
            'price_tolerance_bp' => $this->tolerances->priceBp,
            'absolute_tolerance_ore' => $this->tolerances->absoluteOre,
            'status' => $this->status,
            'reasons' => array_map(fn (MatchReason $reason): string => $reason->value, $this->reasons),
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
