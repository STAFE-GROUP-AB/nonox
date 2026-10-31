<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

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
 * A credit invoice has negative amounts, so the same rules reverse it.
 *
 * TODO(legal-verify): under kontantmetoden, invoices still unpaid at the end
 * of the fiscal year must be booked as receivables; that belongs to bokslut.
 */
final class InvoiceBooking
{
    public const int RECEIVABLES = 1510;

    public const int ROUNDING = 3740;

    public const int BANK = 1930;

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
            $number = $invoice->reverse_charge
                ? self::REVERSE_CHARGE_CONSTRUCTION
                : ($row->account instanceof Account ? $row->account->number : 0);
            $lines[$number] = ($lines[$number] ?? 0) - $row->net_ore->ore;
        }

        $totals = InvoiceCalculator::totals($rows->map(fn (InvoiceRow $row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->values()->all());

        foreach ($totals['vat_by_rate'] as $rate => $group) {
            if ($group['vat'] !== 0) {
                $account = self::OUTPUT_VAT[$rate];
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
        return [self::RECEIVABLES => $invoice->total_ore->ore] + self::revenue($invoice, $rows);
    }

    /**
     * The voucher rows for a payment of $amount öre to $bankAccount.
     *
     * @param  Collection<int, InvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function payment(Invoice $invoice, Collection $rows, int $amount, int $bankAccount, bool $cashMethod): array
    {
        if (! $cashMethod) {
            return [$bankAccount => $amount, self::RECEIVABLES => -$amount];
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

        return [$bankAccount => $amount] + array_filter($lines);
    }
}
