<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Domain\Company\AccountingMethod;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * How supplier invoices and their payments are booked (AGENTS.md §7 Phase 2),
 * as voucher rows with signed öre (debit positive):
 *
 * - Fakturametoden, when registered (series D): each cost row debit, with its
 *   cost centre or project, input VAT on 2641 debit, 2440 Leverantörsskulder
 *   credit with the total. A payment (series E) then moves the amount from
 *   2440 to the bank account.
 * - When the buyer accounts for the VAT (purchases from abroad, Swedish
 *   reverse charge), the VAT is calculated from the rows and booked both as
 *   output VAT (2614/2624/2634) and input VAT (2645/2647), ADR-016.
 * - A credit note has negative amounts, so the same rules reverse it.
 * - Kontantmetoden: nothing when registered. A payment books its share of the
 *   costs and the VAT directly against the bank account.
 * - Except an intra-Community acquisition of goods (VatTreatment::
 *   isReportedWhenInvoiced, ML 7 kap. 26 and 40 §§): booked when registered
 *   as under fakturametoden whatever the method; a payment only clears 2440
 *   (enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-4, #50)).
 * - An invoice in another currency is booked in kronor at the rate on the
 *   invoice. A payment settles its share of those kronor; the bank is
 *   credited with the kronor actually paid and the difference goes to 3960
 *   (gain) or 7960 (loss), ADR-025.
 *
 * TODO(legal-verify): under kontantmetoden, supplier invoices still unpaid at
 * the end of the fiscal year must be booked as liabilities; that belongs to
 * bokslut, like customer invoices (InvoiceBooking).
 */
final class SupplierInvoiceBooking
{
    public const int PAYABLES = 2440;

    public const int INPUT_VAT = 2641;

    public const int BANK = 1930;

    /** BAS 2026: Valutakursvinster på fordringar och skulder av rörelsekaraktär. */
    public const int EXCHANGE_GAIN = 3960;

    /** BAS 2026: Valutakursförluster på fordringar och skulder av rörelsekaraktär. */
    public const int EXCHANGE_LOSS = 7960;

    /** @var array<int, string> account names copied from BAS 2026 (database/data/bas/2026). */
    public const array EXCHANGE_ACCOUNT_NAMES = [
        self::EXCHANGE_GAIN => 'Valutakursvinster på fordringar och skulder av rörelsekaraktär',
        self::EXCHANGE_LOSS => 'Valutakursförluster på fordringar och skulder av rörelsekaraktär',
    ];

    /**
     * Whether the invoice is booked when registered (on 2440) rather than
     * when paid: always under fakturametoden, and under kontantmetoden for an
     * intra-Community acquisition of goods.
     */
    public static function bookedAtRegistration(SupplierInvoice $invoice, AccountingMethod $method): bool
    {
        return $method === AccountingMethod::Fakturametoden || $invoice->vat_treatment->isReportedWhenInvoiced();
    }

    /**
     * The voucher rows when the invoice is booked at registration (bookedAtRegistration).
     *
     * @param  Collection<int, SupplierInvoiceRow>  $rows  with account, cost centre and project
     * @return list<array{account: int, amount: int, description?: ?string, cost_center?: ?string, project?: ?string}>
     */
    public static function register(SupplierInvoice $invoice, Collection $rows): array
    {
        return [
            ...self::costs($invoice, $rows),
            ['account' => self::PAYABLES, 'amount' => -$invoice->total_ore->ore],
        ];
    }

    /**
     * The voucher rows for a payment that settles $amount öre of the invoice
     * as booked. $paid is the kronor that actually left $bankAccount when it
     * differs (an invoice in another currency): the bank row then carries
     * $paid and the difference is booked as an exchange gain or loss.
     *
     * @param  Collection<int, SupplierInvoiceRow>  $rows
     * @return list<array{account: int, amount: int, description?: ?string, cost_center?: ?string, project?: ?string}>
     */
    public static function payment(SupplierInvoice $invoice, Collection $rows, int $amount, int $bankAccount, bool $cashMethod, ?int $paid = null): array
    {
        $paid ??= $amount;
        $difference = $paid - $amount;
        $exchange = $difference === 0 ? [] : [[
            'account' => $difference > 0 ? self::EXCHANGE_LOSS : self::EXCHANGE_GAIN,
            'amount' => $difference,
            'description' => (string) __('Kursdifferens :currency', ['currency' => $invoice->invoice_currency]),
        ]];

        if (! $cashMethod) {
            return [
                ['account' => self::PAYABLES, 'amount' => $amount],
                ...$exchange,
                ['account' => $bankAccount, 'amount' => -$paid],
            ];
        }

        $costs = self::costs($invoice, $rows);
        $total = $invoice->total_ore->ore;
        $shares = array_map(
            fn (array $cost): int => BigDecimal::of($cost['amount'])->multipliedBy($amount)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt(),
            $costs,
        );

        // The rounding remainder of the shares goes to the largest row, so the voucher balances.
        $remainder = $amount - array_sum($shares);

        if ($remainder !== 0 && $shares !== []) {
            $shares[array_keys($shares, max($shares), true)[0]] += $remainder;
        }

        $costs = array_map(fn (array $cost, int $share): array => [...$cost, 'amount' => $share], $costs, $shares);

        return [...$costs, ...$exchange, ['account' => $bankAccount, 'amount' => -$paid]];
    }

    /**
     * The VAT the buyer accounts for, per rate in basis points: the rate on
     * the sum of that rate's rows, rounded to öre. Empty for a domestic invoice.
     *
     * @param  Collection<int, SupplierInvoiceRow>  $rows
     * @return array<int, int>
     */
    public static function buyerVat(SupplierInvoice $invoice, Collection $rows): array
    {
        if (! $invoice->vat_treatment->buyerIsLiable()) {
            return [];
        }

        $vat = [];

        foreach ($rows->groupBy(fn (SupplierInvoiceRow $row): int => (int) $row->vat_rate) as $rate => $group) {
            $sum = Money::ofOre((int) $group->sum(fn (SupplierInvoiceRow $row): int => $row->amount_ore->ore));
            $vat[(int) $rate] = $sum->percent((int) $rate)->ore;
        }

        return array_filter($vat);
    }

    /**
     * The kronor of the invoice as booked that a payment of $foreign
     * hundredths of its currency settles: its share of the total, rounded half
     * up, and whatever is left when the payment clears the foreign amount, so
     * the payables account ends at zero.
     */
    public static function settledShare(SupplierInvoice $invoice, int $foreign): int
    {
        if ($foreign === $invoice->foreignRemaining()) {
            return $invoice->remaining()->ore;
        }

        return BigDecimal::of($invoice->total_ore->ore)->multipliedBy($foreign)->dividedBy((int) $invoice->foreign_total_minor, 0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * The costs and the input VAT, debit; with the buyer liable, also the
     * output VAT, credit.
     *
     * @param  Collection<int, SupplierInvoiceRow>  $rows
     * @return list<array{account: int, amount: int, description?: ?string, cost_center?: ?string, project?: ?string}>
     */
    private static function costs(SupplierInvoice $invoice, Collection $rows): array
    {
        $lines = array_values($rows->map(fn (SupplierInvoiceRow $row): array => [
            'account' => $row->account instanceof Account ? $row->account->number : 0,
            'amount' => $row->amount_ore->ore,
            'description' => $row->description,
            'cost_center' => $row->costCenter instanceof CostCenter ? $row->costCenter->code : null,
            'project' => $row->project instanceof Project ? $row->project->code : null,
        ])->all());

        if (! $invoice->vat_treatment->buyerIsLiable() && ! $invoice->vat_ore->isZero()) {
            $lines[] = ['account' => self::INPUT_VAT, 'amount' => $invoice->vat_ore->ore, 'description' => null, 'cost_center' => null, 'project' => null];
        }

        foreach (self::buyerVat($invoice, $rows) as $rate => $vat) {
            $lines[] = ['account' => $invoice->vat_treatment->inputVatAccount(), 'amount' => $vat, 'description' => null, 'cost_center' => null, 'project' => null];
            $lines[] = ['account' => $invoice->vat_treatment->outputVatAccount($rate), 'amount' => -$vat, 'description' => null, 'cost_center' => null, 'project' => null];
        }

        return $lines;
    }
}
