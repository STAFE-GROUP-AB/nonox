<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Vat\OssRates;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * How invoices and payments are booked (AGENTS.md §7 Phase 2), as signed öre
 * per account number (debit positive):
 *
 * - Fakturametoden, when issued: 1510 Kundfordringar debit, each revenue
 *   account and the output VAT per rate credit, öresavrundning on 3740.
 *   A payment then moves the amount from 1510 to the bank account.
 * - Kontantmetoden: nothing when issued. A payment books its share of the
 *   revenue, VAT and rounding directly against the bank account.
 *
 * A sale abroad (ForeignSale) is booked on the account for that kind of
 * sale instead of the rows' accounts, without VAT.
 *
 * A credit invoice has negative amounts, so the same rules reverse it.
 *
 * An invoice in another currency is booked in kronor at its booking rate
 * (ADR-025). A payment settles its share of those kronor, and the
 * difference to the kronor that reached the bank is an exchange gain on
 * 3960 or a loss on 7960; under kontantmetoden the revenue and VAT are
 * booked at the booking rate, so the VAT follows the rate of the taxable
 * event and not the payment day.
 *
 * TODO(legal-verify): under kontantmetoden, invoices still unpaid at the end
 * of the fiscal year must be booked as receivables; that belongs to bokslut.
 */
final class InvoiceBooking
{
    public const int RECEIVABLES = 1510;

    public const int ROUNDING = 3740;

    public const int BANK = 1930;

    public const int EXCHANGE_GAIN = SupplierInvoiceBooking::EXCHANGE_GAIN;

    public const int EXCHANGE_LOSS = SupplierInvoiceBooking::EXCHANGE_LOSS;

    /**
     * Sales of construction services under omvänd betalningsskyldighet.
     *
     * TODO(legal-verify): BAS 3231 and momsdeklaration box 41.
     */
    public const int REVERSE_CHARGE_CONSTRUCTION = 3231;

    /** Output VAT account per rate in basis points. */
    public const array OUTPUT_VAT = [2500 => 2611, 1200 => 2621, 600 => 2631];

    /**
     * Revenue, VAT and rounding of the invoice, credit negative.
     *
     * @param  Collection<int, InvoiceRow>  $rows  with their accounts
     * @return array<int, int>
     */
    public static function revenue(Invoice $invoice, Collection $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $number = match (true) {
                $invoice->reverse_charge => self::REVERSE_CHARGE_CONSTRUCTION,
                $invoice->foreign_sale !== null => $invoice->foreign_sale->account(),
                default => $row->account instanceof Account ? $row->account->number : 0,
            };
            $lines[$number] = ($lines[$number] ?? 0) - $row->net_ore->ore;
        }

        $totals = InvoiceCalculator::totals($rows->map(fn (InvoiceRow $row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->values()->all());

        foreach ($totals['vat_by_rate'] as $rate => $group) {
            if ($group['vat'] !== 0) {
                $account = $invoice->foreign_sale === ForeignSale::EuConsumerOss ? OssRates::VAT_ACCOUNT : self::OUTPUT_VAT[$rate];
                $lines[$account] = ($lines[$account] ?? 0) - $group['vat'];
            }
        }

        if ($invoice->rounding_ore->ore !== 0) {
            $lines[self::ROUNDING] = ($lines[self::ROUNDING] ?? 0) - $invoice->rounding_ore->ore;
        }

        return array_filter($lines);
    }

    /**
     * The voucher rows when an invoice is issued under fakturametoden.
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function issue(Invoice $invoice, Collection $rows): array
    {
        // A ROT or RUT deduction is a receivable on Skatteverket, not on the customer.
        return array_filter([self::RECEIVABLES => $invoice->total_ore->ore, TaxReduction::RECEIVABLE => $invoice->tax_reduction_ore->ore]) + self::revenue($invoice, $rows);
    }

    /**
     * The voucher rows for a payment that settles $amount öre of the invoice
     * as booked. $received is the kronor that reached $bankAccount when it
     * differs (an invoice in another currency): the bank row then carries
     * $received and the difference is an exchange gain (3960, credit) or
     * loss (7960, debit).
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function payment(Invoice $invoice, Collection $rows, int $amount, int $bankAccount, bool $cashMethod, ?int $received = null): array
    {
        $received ??= $amount;
        $difference = $amount - $received;
        $exchange = $difference === 0 ? [] : [$difference > 0 ? self::EXCHANGE_LOSS : self::EXCHANGE_GAIN => $difference];

        if (! $cashMethod) {
            return [$bankAccount => $received, self::RECEIVABLES => -$amount] + $exchange;
        }

        $revenue = self::revenue($invoice, $rows);
        $total = $invoice->total_ore->ore;
        $lines = [];

        foreach ($revenue as $account => $value) {
            $lines[$account] = BigDecimal::of($value)->multipliedBy($amount)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt();
        }

        // The rounding remainder of the shares goes to the largest line, so the voucher balances.
        $remainder = -$amount - array_sum($lines);

        if ($remainder !== 0 && $lines !== []) {
            $largest = array_keys($lines, min($lines), true)[0];
            $lines[$largest] += $remainder;
        }

        return [$bankAccount => $received] + array_filter($lines) + $exchange;
    }

    /**
     * The kronor of the invoice as booked that a payment of $foreign
     * hundredths of its currency settles: its share of the total, rounded
     * half up, and whatever is left when the payment clears the foreign
     * amount, so the receivable ends at zero.
     */
    public static function settledShare(Invoice $invoice, int $foreign): int
    {
        if ($foreign === $invoice->foreignRemaining()) {
            return $invoice->remaining()->ore;
        }

        return BigDecimal::of($invoice->total_ore->ore)->multipliedBy($foreign)->dividedBy((int) $invoice->foreign_total_minor, 0, RoundingMode::HalfUp)->toInt();
    }
}
