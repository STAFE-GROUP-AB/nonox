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
 *
 * For an invoice in another currency the kronor say little: the amount the
 * payer sent in the invoice's currency must not exceed what remains in it.
 * When the bank did not report one, the OCR number alone suggests the
 * invoice, and booking it settles what remains (BookIncomingPayments).
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
            ->get(['id', 'number', 'ocr', 'total_ore', 'paid_ore', 'customer_snapshot', 'status', 'kind', 'due_date', 'invoice_currency', 'foreign_total_minor', 'foreign_paid_minor', 'exchange_rate_micros'])
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

            if ($invoice instanceof Invoice && self::fits($invoice, $credit->amountOre, $credit->foreignCurrency, $credit->foreignAmountMinor)) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * Whether a payment of $amountOre kronor (and, if the bank said so,
     * $foreignAmount in $foreignCurrency) can be booked on the invoice.
     */
    public static function fits(Invoice $invoice, int $amountOre, ?string $foreignCurrency, ?int $foreignAmount): bool
    {
        $foreignRemaining = $invoice->foreignRemaining();

        if ($foreignRemaining === null) {
            return $amountOre <= $invoice->remaining()->ore;
        }

        if ($foreignCurrency === null || $foreignAmount === null) {
            return true;
        }

        return $foreignCurrency === $invoice->invoice_currency && $foreignAmount > 0 && $foreignAmount <= $foreignRemaining;
    }
}
