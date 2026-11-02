<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ROT and RUT (skattereduktion för rot- och rutarbete): the customer pays the
 * labour cost less the deduction, and the company asks Skatteverket for the
 * rest. The deduction is a share of the labour cost including VAT, in whole
 * kronor, within a yearly ceiling per buyer.
 *
 * The share and the ceiling follow the year, and the date, the buyer pays:
 * the reduction is for the tax year in which the costs were paid
 * (Inkomstskattelag (1999:1229) 67 kap. 12 § p. 1, 19 §; lag (2009:194) om
 * förfarandet vid skattereduktion för hushållsarbete 7-8 §§; Skatteverket:
 * "Datumet då du betalar fakturan styr vilket år avdraget hamnar på", read
 * 2026-11-02). The invoice therefore uses its due date as the expected day
 * of payment, and the deduction is checked again against the actual day
 * when the payment is registered (enligt granskning 2026-11-02,
 * juristgranskning återstår (AUD-FAKT-10, #56)).
 *
 * TODO(legal-verify): every figure here — the shares (30 % ROT, 50 % RUT
 * from 2026-01-01), the ceilings (50 000 kr ROT, 75 000 kr ROT and RUT
 * together per person and year), rounding down to whole kronor, and which
 * costs count as labour. The ceiling is only checked against this
 * company's own invoices; the buyer may have used it elsewhere.
 */
final class TaxReduction
{
    public const string ROT = 'ROT';

    public const string RUT = 'RUT';

    /** Kundfordringar – delad faktura: what Skatteverket is asked to pay. */
    public const int RECEIVABLE = 1513;

    /**
     * Övriga kortfristiga skulder: a deduction Skatteverket has paid and that
     * a credit invoice has taken back from the buyer, owed back to
     * Skatteverket (lag (2009:194) 13-14 §§).
     *
     * TODO(legal-verify): 2890 as the account for this debt (#49).
     */
    public const int REPAYABLE = 2890;

    /** @var array<string, list<array{from: string, rate: int}>> share in basis points from a date */
    public const array RATES = [
        self::ROT => [['from' => '2026-01-01', 'rate' => 3000]],
        self::RUT => [['from' => '2026-01-01', 'rate' => 5000]],
    ];

    /** @var array<string, int> öre per buyer and calendar year */
    public const array CEILINGS = [self::ROT => 5_000_000, 'TOTAL' => 7_500_000];

    public static function label(string $type): string
    {
        return $type === self::ROT ? __('ROT-avdrag') : __('RUT-avdrag');
    }

    /** The share in basis points on a date, or null when no rule is known for it. */
    public static function rate(string $type, CarbonImmutable $date): ?int
    {
        $rate = null;

        foreach (self::RATES[$type] ?? [] as $rule) {
            if ($date->toDateString() >= $rule['from']) {
                $rate = $rule['rate'];
            }
        }

        return $rate;
    }

    /**
     * The deduction on a labour cost including VAT, rounded down to whole
     * kronor (towards zero for a credit), and kept within what is left of
     * the ceilings.
     */
    public static function amount(int $labourOre, int $rate, int $roomOre): int
    {
        $sign = $labourOre < 0 ? -1 : 1;
        $amount = intdiv(intdiv(abs($labourOre) * $rate, 10_000), 100) * 100;

        return $sign * ($sign > 0 ? min($amount, max(0, $roomOre)) : $amount);
    }

    /**
     * The deduction the invoice may carry when the buyer pays on $paidOn:
     * the share on that day on the labour cost including VAT, within what is
     * left of that year's ceilings after the buyer's other invoices from this
     * company. Null when no rule is known for the day.
     */
    public static function allowed(string $type, int $labourOre, Customer $customer, CarbonImmutable $paidOn, ?string $exceptInvoiceId = null): ?int
    {
        $rate = self::rate($type, $paidOn);

        if ($rate === null) {
            return null;
        }

        $used = self::used($customer, $paidOn->year, $exceptInvoiceId);
        $room = self::CEILINGS['TOTAL'] - array_sum($used);

        if ($type === self::ROT) {
            $room = min($room, self::CEILINGS[self::ROT] - ($used[self::ROT] ?? 0));
        }

        return self::amount($labourOre, $rate, $room);
    }

    /**
     * The deduction already on the buyer's other issued invoices (credits
     * negative) per type, for those the buyer paid, or is expected to pay,
     * in $year: the day of the last payment, or the due date while unpaid.
     * A credit invoice counts in the year of the invoice it credits.
     *
     * @return array<string, int>
     */
    public static function used(Customer $customer, int $year, ?string $exceptInvoiceId = null): array
    {
        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('tax_reduction')
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->with('payments')
            ->get();
        $years = $invoices->mapWithKeys(fn (Invoice $invoice): array => [$invoice->id => self::paymentDay($invoice)->year]);
        $used = [];

        foreach ($invoices as $invoice) {
            $original = $invoice->credits_invoice_id ?? $invoice->id;

            if ($original === $exceptInvoiceId || $invoice->id === $exceptInvoiceId || ($years[$original] ?? $years[$invoice->id]) !== $year) {
                continue;
            }

            $used[(string) $invoice->tax_reduction] = ($used[(string) $invoice->tax_reduction] ?? 0) + $invoice->tax_reduction_ore->ore;
        }

        return $used;
    }

    /** The day the buyer paid the invoice, or is expected to: its last payment, or its due date. */
    public static function paymentDay(Invoice $invoice): CarbonImmutable
    {
        /** @var Collection<int, InvoicePayment> $payments */
        $payments = $invoice->relationLoaded('payments') ? $invoice->payments : $invoice->payments()->get();
        $last = $payments->max(fn (InvoicePayment $payment): string => $payment->paid_on->toDateString());

        return is_string($last) ? CarbonImmutable::parse($last) : $invoice->due_date;
    }

    /**
     * The labour cost including VAT of the rows marked as labour.
     *
     * @param  iterable<InvoiceRow>  $rows
     */
    public static function labour(iterable $rows): int
    {
        $labour = [];

        foreach ($rows as $row) {
            if ($row->is_labour) {
                $labour[] = ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate];
            }
        }

        $totals = InvoiceCalculator::totals($labour);

        return $totals['net'] + $totals['vat'];
    }

    /**
     * The deduction checked against the day the buyer actually paid: null
     * when the invoice's deduction still holds, otherwise what it should be
     * for that day's year and rate. The invoice itself never changes; the
     * difference is the buyer's to pay if Skatteverket refuses it.
     *
     * @return ?array{year: int, allowed: int, invoiced: int}
     */
    public static function recheck(Invoice $invoice, CarbonImmutable $paidOn): ?array
    {
        if ($invoice->tax_reduction === null || $invoice->isCredit() || $invoice->tax_reduction_ore->isZero()) {
            return null;
        }

        $customer = $invoice->customer()->first();

        if (! $customer instanceof Customer) {
            return null;
        }

        $allowed = self::allowed($invoice->tax_reduction, self::labour($invoice->rows), $customer, $paidOn, $invoice->id);

        if ($allowed === null || $allowed === $invoice->tax_reduction_ore->ore) {
            return null;
        }

        return ['year' => $paidOn->year, 'allowed' => $allowed, 'invoiced' => $invoice->tax_reduction_ore->ore];
    }
}
