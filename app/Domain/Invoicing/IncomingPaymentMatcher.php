<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Support\Camt054\BankCredit;
use Illuminate\Support\Collection;

/**
 * Suggests which unpaid invoice an incoming payment is for: the invoice whose
 * OCR number the payer gave, or, when the payer wrote a message, a whole
 * number in it that is an invoice's OCR number. The amount must not exceed what remains on the
 * invoice. Nothing is booked here; the user confirms every suggestion.
 */
final class IncomingPaymentMatcher
{
    /** @var Collection<string, Invoice> unpaid invoices by OCR number */
    private Collection $byOcr;

    public function __construct()
    {
        $this->byOcr = Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->where('kind', Invoice::INVOICE)
            ->whereNotNull('ocr')
            ->get(['id', 'number', 'ocr', 'total_ore', 'paid_ore', 'customer_snapshot', 'status', 'kind', 'due_date'])
            ->keyBy('ocr');
    }

    public function match(BankCredit $credit): ?Invoice
    {
        $candidates = $credit->ocr !== null ? [$credit->ocr] : [];

        if ($credit->message !== null) {
            preg_match_all('/\b\d{2,}\b/', $credit->message, $numbers);
            $candidates = [...$candidates, ...$numbers[0]];
        }

        foreach ($candidates as $ocr) {
            $invoice = $this->byOcr->get($ocr);

            if ($invoice instanceof Invoice && $credit->amountOre <= $invoice->remaining()->ore) {
                return $invoice;
            }
        }

        return null;
    }
}
