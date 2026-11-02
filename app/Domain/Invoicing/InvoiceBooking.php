<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Company\AccountingMethod;
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
 *   revenue, VAT and rounding directly against the bank account. The
 *   payment that settles the invoice books what is left of each line, so
 *   the payments together book exactly the invoice's revenue and VAT
 *   (ML 2023:200 7 kap. 16 §, 17 kap. 24 § p. 11) (enligt granskning
 *   2026-11-02, juristgranskning återstår (AUD-FAKT-3, #52)).
 * - Except EU sales reported for the invoice's period (ForeignSale::
 *   isReportedWhenInvoiced, ML 7 kap. 29-30 §§): they are booked as under
 *   fakturametoden whatever the method, and a payment only clears 1510
 *   (enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-4, #50)).
 *
 * A sale abroad (ForeignSale) is booked on the account for that kind of
 * sale instead of the rows' accounts, without VAT. A sale to a consumer in
 * another EU country under the OSS threshold (OssThreshold) carries Swedish
 * VAT and is booked like a sale in Sweden.
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
        $sale = $invoice->bookedForeignSale();

        foreach ($rows as $row) {
            $number = match (true) {
                $invoice->reverse_charge => self::REVERSE_CHARGE_CONSTRUCTION,
                $sale !== null => $sale->account(),
                default => $row->account instanceof Account ? $row->account->number : 0,
            };
            $lines[$number] = ($lines[$number] ?? 0) - $row->net_ore->ore;
        }

        $totals = InvoiceCalculator::totals($rows->map(fn (InvoiceRow $row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->values()->all());

        foreach ($totals['vat_by_rate'] as $rate => $group) {
            if ($group['vat'] !== 0) {
                $account = $sale === ForeignSale::EuConsumerOss ? OssRates::VAT_ACCOUNT : self::OUTPUT_VAT[$rate];
                $lines[$account] = ($lines[$account] ?? 0) - $group['vat'];
            }
        }

        if ($invoice->rounding_ore->ore !== 0) {
            $lines[self::ROUNDING] = ($lines[self::ROUNDING] ?? 0) - $invoice->rounding_ore->ore;
        }

        return array_filter($lines);
    }

    /**
     * Whether the invoice is booked when issued (on 1510) rather than when
     * paid: always under fakturametoden, and under kontantmetoden for the
     * EU sales the law ties to the invoice's period.
     */
    public static function bookedAtIssue(Invoice $invoice, AccountingMethod $method): bool
    {
        return $method === AccountingMethod::Fakturametoden || $invoice->bookedForeignSale()?->isReportedWhenInvoiced() === true;
    }

    /**
     * The voucher rows when an invoice is booked at issue (bookedAtIssue).
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function issue(Invoice $invoice, Collection $rows): array
    {
        // A ROT or RUT deduction is a receivable on Skatteverket, not on the customer. On a credit, the
        // part Skatteverket has already paid is a debt to Skatteverket instead of a smaller claim.
        $repayable = $invoice->tax_reduction_repayable_ore->ore;

        return array_filter([
            self::RECEIVABLES => $invoice->total_ore->ore,
            TaxReduction::RECEIVABLE => $invoice->tax_reduction_ore->ore + $repayable,
            TaxReduction::REPAYABLE => -$repayable,
        ]) + self::revenue($invoice, $rows);
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
        $lines = ($amount === $invoice->remaining()->ore ? self::cashRest($invoice, $revenue, $amount) : null)
            ?? self::cashShare($revenue, $amount, $invoice->total_ore->ore);

        return [$bankAccount => $received] + array_filter($lines) + $exchange;
    }

    /**
     * Kontantmetoden: a part payment's share of each line, rounded half up,
     * with the rounding remainder on the largest line so the voucher balances.
     *
     * @param  array<int, int>  $revenue
     * @return array<int, int>
     */
    public static function cashShare(array $revenue, int $amount, int $total): array
    {
        $lines = [];

        foreach ($revenue as $account => $value) {
            $lines[$account] = BigDecimal::of($value)->multipliedBy($amount)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt();
        }

        $remainder = -$amount - array_sum($lines);

        if ($remainder !== 0 && $lines !== []) {
            $largest = array_keys($lines, min($lines), true)[0];
            $lines[$largest] += $remainder;
        }

        return $lines;
    }

    /**
     * Kontantmetoden: the lines of the payment that settles the invoice. It
     * books what is left of each line after the earlier payments, so that
     * together they book exactly the invoice's revenue and VAT, less what
     * its credit invoices took off (ML 2023:200 7 kap. 16 §, 17 kap. 24 §
     * p. 11; enligt granskning 2026-11-02, juristgranskning återstår
     * (AUD-FAKT-3, #52)). Null for a credit invoice, or when the earlier
     * payments do not add up to the rest (then the payment is shared
     * pro rata as before).
     *
     * @param  array<int, int>  $revenue
     * @return array<int, int>|null
     */
    private static function cashRest(Invoice $invoice, array $revenue, int $amount): ?array
    {
        if ($invoice->isCredit()) {
            return null;
        }

        $target = $revenue;
        $credits = $invoice->creditInvoices()->whereNotNull('number')->with('rows.account')->get();

        foreach ($credits as $credit) {
            foreach (self::revenue($credit, $credit->rows) as $account => $value) {
                $target[$account] = ($target[$account] ?? 0) + $value;
            }
        }

        foreach ($invoice->payments()->get() as $payment) {
            $settled = $payment->amount_ore->ore - (int) $payment->exchange_difference_ore;

            foreach (self::cashShare($revenue, $settled, $invoice->total_ore->ore) as $account => $value) {
                $target[$account] = ($target[$account] ?? 0) - $value;
            }
        }

        return array_sum($target) === -$amount ? $target : null;
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
