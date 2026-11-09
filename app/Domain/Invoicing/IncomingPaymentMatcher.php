<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Support\Camt054\BankCredit;

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
    /** @var array<string, list<Invoice>> unpaid invoices by OCR number */
    private array $byOcr = [];

    /** @var array<string, list<Invoice>> imported invoices by the number they had in the previous program */
    private array $byOldNumber = [];

    public function __construct()
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->where('kind', Invoice::INVOICE)
            ->get(['id', 'number', 'ocr', 'external_ref', 'import_source', 'total_ore', 'paid_ore', 'customer_snapshot', 'status', 'kind', 'due_date', 'invoice_currency', 'foreign_total_minor', 'foreign_paid_minor', 'exchange_rate_micros']);

        foreach ($invoices as $invoice) {
            if ($invoice->ocr !== null) {
                $this->byOcr[(string) $invoice->ocr][] = $invoice;
            }

            if ($invoice->isImported() && $invoice->external_ref !== null) {
                $this->byOldNumber[(string) $invoice->external_ref][] = $invoice;
            }
        }
    }

    /**
     * An OCR number that is on more than one unpaid invoice matches none of
     * them: guessing would book a customer's money on another's invoice.
     */
    public function match(BankCredit $credit): ?Invoice
    {
        $candidates = $credit->ocr !== null ? [$credit->ocr] : [];

        if ($credit->message !== null) {
            preg_match_all('/\b\d{2,}\b/', $credit->message, $numbers);
            $candidates = [...$candidates, ...$numbers[0]];
        }

        foreach ($candidates as $candidate) {
            foreach ([$this->byOcr, $this->byOldNumber] as $index) {
                $found = $index[$candidate] ?? [];

                if (count($found) === 1 && self::fits($found[0], $credit->amountOre, $credit->foreignCurrency, $credit->foreignAmountMinor)) {
                    return $found[0];
                }
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
