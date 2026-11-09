<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kontoutdrag (ADR-075 §4): the customer's open items on a chosen date,
 * computed from the invoices, their payments and the credit invoices issued
 * by that date. Read-only and not a document the company has issued, which
 * the page and the PDF say. Amounts in kronor. A fixed number of queries
 * whatever the number of invoices.
 */
final class PortalStatement
{
    public function __construct(private readonly PortalContext $context) {}

    /**
     * @return array{date: CarbonImmutable, rows: list<array{invoice: Invoice, total: int, paid: int, credited: int, open: int, overdue: bool}>, total: int, overdue: int}
     */
    public function on(CarbonImmutable $date): array
    {
        $day = $date->toDateString();

        $invoices = Invoice::query()
            ->where('customer_id', $this->context->customer()->id)
            ->where('kind', Invoice::INVOICE)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::WrittenOff->value])
            ->whereNotNull('number')
            ->where('invoice_date', '<=', $day)
            ->with([
                'payments' => fn (HasMany $query) => $query->where('paid_on', '<=', $day),
                'creditInvoices' => fn (HasMany $query) => $query->where('status', '<>', InvoiceStatus::Draft->value)->where('invoice_date', '<=', $day),
            ])
            ->orderBy('due_date')->orderBy('number')
            ->get();

        $rows = [];

        foreach ($invoices as $invoice) {
            $paid = (int) $invoice->payments->sum(fn (InvoicePayment $payment): int => $payment->amount_ore->ore);
            $credited = (int) -$invoice->creditInvoices->sum(fn (Invoice $credit): int => $credit->total_ore->ore);
            $open = $invoice->total_ore->ore - $paid - $credited;

            if ($open <= 0) {
                continue;
            }

            $rows[] = ['invoice' => $invoice, 'total' => $invoice->total_ore->ore, 'paid' => $paid, 'credited' => $credited, 'open' => $open, 'overdue' => $invoice->due_date->lessThan($date)];
        }

        return [
            'date' => $date,
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'open')),
            'overdue' => array_sum(array_map(fn (array $row): int => $row['overdue'] ? $row['open'] : 0, $rows)),
        ];
    }
}
