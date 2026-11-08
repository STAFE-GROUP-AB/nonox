<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Support\Uninvoiced;
use Carbon\CarbonImmutable;

/**
 * The warnings a work order shows (ADR-074, rule sheet). Every one is
 * ADVICE: NONOX computes and shows, the user decides; none blocks.
 */
final class Advice
{
    /**
     * Four prisbasbelopp per year, for the personalliggare hint (SFL 39 kap.
     * 11 b §, AO-R27). 2026: 59 200 kr (förordning 2025:872).
     * TODO(legal-verify): the 2027 amount (59 600 kr per SCB, not read in SFS).
     *
     * @var array<int, int> öre
     */
    public const array PRICE_BASE_AMOUNTS = [2026 => 5_920_000, 2027 => 5_960_000];

    /**
     * A consumer for the consumer-law warnings (KtjL 1 §, AO-R1): a customer
     * without organisation and VAT number, as FullInvoice already treats
     * private persons. TODO(legal-verify): L1.
     */
    public static function isConsumer(Customer $customer): bool
    {
        return blank($customer->org_number) && blank($customer->vat_number);
    }

    /**
     * The main work recorded on an approximate price, against 115 % of it or
     * the agreed other limit (KtjL 36 §, 6 §, AO-R6, AO-R7). Main work only:
     * approved ÄTA are price supplements under 38 § and shown apart. Values
     * are including VAT, as the approximate price is. Null when the work
     * order has no approximate price or the customer is not a consumer.
     *
     * TODO(legal-verify): L4 (another agreed limit, such as HF17's 25 %),
     * L3 ("betydligt högre").
     *
     * @return ?array{approximate: int, limit: int, recorded: int, change_orders: int, exceeded: bool}
     */
    public static function approximatePrice(WorkOrder $workOrder, Customer $customer): ?array
    {
        if ($workOrder->price_form !== PriceForm::Estimate || $workOrder->approximate_price_ore === null || ! self::isConsumer($customer)) {
            return null;
        }

        $main = 0;
        $changes = 0;
        $entries = [
            ...Uninvoiced::live(TimeEntry::query()->where('work_order_id', $workOrder->id))->where('billable', true)->get(),
            ...Uninvoiced::live(MaterialEntry::query()->where('work_order_id', $workOrder->id))->where('billable', true)->get(),
        ];

        foreach ($entries as $entry) {
            $gross = self::gross($entry->netOre(), $entry instanceof MaterialEntry ? $entry->vat_rate : $workOrder->vat_rate);
            $entry->change_order_id === null ? $main += $gross : $changes += $gross;
        }

        $limit = intdiv($workOrder->approximate_price_ore->ore * (10_000 + $workOrder->limitBp()), 10_000);

        return [
            'approximate' => $workOrder->approximate_price_ore->ore,
            'limit' => $limit,
            'recorded' => $main,
            'change_orders' => $changes,
            'exceeded' => $main > $limit,
        ];
    }

    /**
     * Uninvoiced construction work per month of performance, with the last
     * day to invoice it: the end of the second calendar month after (ML 17
     * kap. 16 §, AO-R22). A month is listed from the first day of its
     * deadline month. Only for work orders marked as construction.
     *
     * @return list<array{month: string, deadline: string, overdue: bool}>
     */
    public static function invoiceDeadlines(WorkOrder $workOrder, CarbonImmutable $today): array
    {
        if (! $workOrder->is_construction) {
            return [];
        }

        $dates = [
            ...Uninvoiced::open(TimeEntry::query()->where('work_order_id', $workOrder->id))->pluck('work_date')->all(),
            ...Uninvoiced::open(MaterialEntry::query()->where('work_order_id', $workOrder->id))->pluck('work_date')->all(),
        ];
        $months = array_unique(array_map(fn (mixed $date): string => CarbonImmutable::parse((string) $date)->format('Y-m'), $dates));
        sort($months);
        $flags = [];

        foreach ($months as $month) {
            $deadline = CarbonImmutable::parse("{$month}-01")->addMonthsNoOverflow(2)->endOfMonth();

            if ($today->greaterThanOrEqualTo($deadline->startOfMonth())) {
                $flags[] = ['month' => $month, 'deadline' => $deadline->toDateString(), 'overdue' => $today->greaterThan($deadline)];
            }
        }

        return $flags;
    }

    /**
     * "Kan kräva personalliggare" (SFL 39 kap. 11 b §, AO-R27): a customer in
     * business (organisation number) and an expected value above four
     * prisbasbelopp. A private person who does not order in business has no
     * such duty, so nothing is shown.
     */
    public static function staffLedgerHint(WorkOrder $workOrder, Customer $customer, CarbonImmutable $today): bool
    {
        if (! $workOrder->is_construction || blank($customer->org_number)) {
            return false;
        }

        $expected = $workOrder->contract_sum_ore->ore ?? $workOrder->approximate_price_ore->ore ?? null;
        $base = self::PRICE_BASE_AMOUNTS[$today->year] ?? self::PRICE_BASE_AMOUNTS[max(array_keys(self::PRICE_BASE_AMOUNTS))];

        return $expected !== null && $expected > 4 * $base;
    }

    /**
     * An a conto invoice with ROT or RUT paid in one year while the work is
     * not done by 31 January the year after (Skatteverket, AO-R16, F9).
     * TODO(legal-verify): L7.
     *
     * @return list<array{invoice_number: ?int, paid_year: int}>
     */
    public static function rotAconto(WorkOrder $workOrder, CarbonImmutable $today): array
    {
        $links = WorkOrderInvoice::query()->where('work_order_id', $workOrder->id)->where('kind', WorkOrderInvoice::INSTALMENT)
            ->with(['invoice.payments'])->get();
        $warnings = [];

        foreach ($links as $link) {
            $invoice = $link->invoice;

            if (! $invoice instanceof Invoice || $invoice->tax_reduction === null || $invoice->status === InvoiceStatus::Draft || $invoice->payments->isEmpty()) {
                continue;
            }

            $paidYear = CarbonImmutable::parse((string) $invoice->payments->max(fn ($payment): string => $payment->paid_on->toDateString()))->year;
            $limit = CarbonImmutable::parse(($paidYear + 1).'-01-31')->endOfDay();
            $doneInTime = $workOrder->done_at !== null && $workOrder->done_at->lessThanOrEqualTo($limit);

            if ($today->greaterThan($limit) && ! $doneInTime) {
                $warnings[] = ['invoice_number' => $invoice->number, 'paid_year' => $paidYear];
            }
        }

        return $warnings;
    }

    private static function gross(int $net, int $vatRate): int
    {
        return $net + (int) round($net * $vatRate / 10_000);
    }
}
