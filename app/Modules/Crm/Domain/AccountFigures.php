<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The key figures of accounts, read from the ledger (ADR-043), for any
 * number of customers in three aggregate queries, whatever the number:
 *
 * - invoiced in the last 12 months and in total: issued invoices excluding
 *   VAT, credit invoices deducted;
 * - open and overdue receivable: what is left to pay on issued invoices;
 * - average payment time: payment date minus invoice date, weighted by the
 *   amount paid, for payments of the last 12 months on invoices (not
 *   refunds or recoveries of written-off amounts);
 * - contribution margin for the last 12 months: net sales on rows whose
 *   article has a purchase price, minus quantity × the article's purchase
 *   price today, and the share of the net sales that had such a price.
 *
 * Amounts in kronor whatever the invoice currency: the *_ore columns are
 * kronor. Queries run in the current company.
 */
final class AccountFigures
{
    /**
     * @param  list<int>  $customerIds
     * @return array<int, KeyFigures> by customer id; customers without figures are missing
     */
    public function forCustomers(array $customerIds, ?CarbonImmutable $today = null): array
    {
        if ($customerIds === []) {
            return [];
        }

        $today ??= CarbonImmutable::today();
        $from = $today->subYearNoOverflow()->addDay()->toDateString();
        $sales = $this->sales($customerIds, $from, $today->toDateString());
        $days = $this->paymentDays($customerIds, $from);
        $margins = $this->margins($customerIds, $from);
        $figures = [];

        foreach (array_unique([...array_keys($sales), ...array_keys($days), ...array_keys($margins)]) as $id) {
            $row = $sales[$id] ?? null;
            $margin = $margins[$id] ?? null;
            $net = (int) ($margin->net ?? 0);
            $knownNet = (int) ($margin->known_net ?? 0);
            $hasKnown = $margin !== null && $margin->known_cost !== null;

            $figures[$id] = new KeyFigures(
                invoiced12Months: (int) ($row->invoiced_12m ?? 0),
                invoicedTotal: (int) ($row->invoiced_total ?? 0),
                open: (int) ($row->open ?? 0),
                overdue: (int) ($row->overdue ?? 0),
                paymentDays: isset($days[$id]) ? round((float) $days[$id], 1) : null,
                marginBase: $knownNet,
                margin: $hasKnown ? $knownNet - (int) $margin->known_cost : null,
                marginCoverage: $net > 0 ? max(0.0, min(1.0, $knownNet / $net)) : null,
            );
        }

        return $figures;
    }

    public function forCustomer(?int $customerId, ?CarbonImmutable $today = null): KeyFigures
    {
        return $customerId === null ? new KeyFigures : ($this->forCustomers([$customerId], $today)[$customerId] ?? new KeyFigures);
    }

    /**
     * Sales and receivables per customer: one query.
     *
     * @param  list<int>  $customerIds
     * @return array<int, object{invoiced_12m: mixed, invoiced_total: mixed, open: mixed, overdue: mixed}>
     */
    public function sales(array $customerIds, string $from, string $today): array
    {
        $signed = 'CASE WHEN kind = ? THEN -ABS(net_ore) ELSE net_ore END';
        $issued = 'status = ? AND kind = ?';

        /** @var array<int, object{invoiced_12m: mixed, invoiced_total: mixed, open: mixed, overdue: mixed}> */
        return Invoice::query()
            ->toBase()
            ->whereIn('customer_id', $customerIds)
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw("COALESCE(SUM({$signed}) FILTER (WHERE invoice_date >= ?), 0) AS invoiced_12m", [Invoice::CREDIT, $from])
            ->selectRaw("COALESCE(SUM({$signed}), 0) AS invoiced_total", [Invoice::CREDIT])
            ->selectRaw("COALESCE(SUM(total_ore - paid_ore) FILTER (WHERE {$issued}), 0) AS open", [InvoiceStatus::Issued->value, Invoice::INVOICE])
            ->selectRaw("COALESCE(SUM(total_ore - paid_ore) FILTER (WHERE {$issued} AND due_date < ?), 0) AS overdue", [InvoiceStatus::Issued->value, Invoice::INVOICE, $today])
            ->get()
            ->keyBy('customer_id')
            ->all();
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, float|string>
     */
    private function paymentDays(array $customerIds, string $from): array
    {
        /** @var array<int, float|string> */
        return InvoicePayment::query()
            ->toBase()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->whereIn('invoices.customer_id', $customerIds)
            ->where('invoices.kind', Invoice::INVOICE)
            ->where('invoice_payments.amount_ore', '>', 0)
            ->where('invoice_payments.recovery', false)
            ->where('invoice_payments.paid_on', '>=', $from)
            ->groupBy('invoices.customer_id')
            ->having(DB::raw('SUM(invoice_payments.amount_ore)'), '>', 0)
            ->select('invoices.customer_id')
            ->selectRaw('SUM(invoice_payments.amount_ore * (invoice_payments.paid_on - invoices.invoice_date))::numeric / SUM(invoice_payments.amount_ore) AS days')
            ->pluck('days', 'invoices.customer_id')
            ->all();
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, object{net: mixed, known_net: mixed, known_cost: mixed}>
     */
    private function margins(array $customerIds, string $from): array
    {
        $known = 'articles.purchase_price_ore IS NOT NULL';

        /** @var array<int, object{net: mixed, known_net: mixed, known_cost: mixed}> */
        return InvoiceRow::query()
            ->toBase()
            ->join('invoices', 'invoices.id', '=', 'invoice_rows.invoice_id')
            ->leftJoin('articles', 'articles.id', '=', 'invoice_rows.article_id')
            ->whereIn('invoices.customer_id', $customerIds)
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value)
            ->where('invoices.invoice_date', '>=', $from)
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            // A credit invoice's rows carry negative quantities and amounts (SalesRowInput), so they deduct by themselves.
            ->selectRaw('SUM(invoice_rows.net_ore) AS net')
            ->selectRaw("SUM(invoice_rows.net_ore) FILTER (WHERE {$known}) AS known_net")
            ->selectRaw("ROUND(SUM(invoice_rows.quantity * articles.purchase_price_ore) FILTER (WHERE {$known})) AS known_cost")
            ->get()
            ->keyBy('customer_id')
            ->all();
    }
}
