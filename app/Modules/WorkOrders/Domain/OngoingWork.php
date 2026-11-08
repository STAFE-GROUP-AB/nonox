<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\ReportingFramework;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\VoucherRow;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Support\Uninvoiced;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pågående arbeten at the balance sheet date (ADR-074 §7, AO-R30–R33):
 * per open work order the figures and a proposed adjustment, which a
 * person reviews and turns into draft vouchers. Nothing is ever booked
 * here; the proposal is reversed on the first day of the next year.
 *
 * - Löpande räkning (K2 6.13–6.14, K3 23.17): recorded work and material
 *   not invoiced on the balance sheet date is revenue (1620 "Upparbetad
 *   men ej fakturerad intäkt"); amounts invoiced beyond the work recorded
 *   are a liability (2420 "Förskott från kunder"). F7a.
 * - Fast pris (K2 6.15–6.24, K3 23.17, 23.31): the contract sum including
 *   ÄTA counted into the job (AO-R32), the costs booked on the work
 *   order's project, and a degree of completion from the costs against
 *   the estimated total cost the user enters. Main rule: revenue to date
 *   less invoiced, on 1620 or 2450. Alternative rule and K3
 *   färdigställandemetoden: invoiced amounts out of revenue to 2450; the
 *   capitalisable costs are shown, without a voucher, until L12 names the
 *   change account. F7b.
 *
 * TODO(legal-verify): L12 (BAS 2026 contains 1470, 1620, 2420 and 2450 per
 * the quote check; their texts and the change account are open) and L13
 * (which proposals per framework; K1 gets figures only).
 */
final class OngoingWork
{
    public const int UNBILLED = 1620;

    public const int ADVANCES = 2420;

    public const int BILLED_AHEAD = 2450;

    public const int WORK_IN_PROGRESS = 1470;

    /**
     * @param  array<int, int>  $estimatedCosts  work order id => estimated total cost in öre (fixed price)
     * @return list<array{work_order: WorkOrder, kind: string, figures: array<string, int|null>, rows: list<array{account: int, debit_ore: int, credit_ore: int}>, note: ?string}>
     */
    public static function at(Company $company, CarbonImmutable $date, array $estimatedCosts = []): array
    {
        return CurrentCompany::run($company, function () use ($company, $date, $estimatedCosts): array {
            $method = WorkOrdersSettings::fixedPriceMethod($company);
            $k1 = $company->reporting_framework === ReportingFramework::K1;
            $workOrders = WorkOrder::query()
                ->whereDate('created_at', '<=', $date->toDateString())
                ->where(fn ($query) => $query->whereNotIn('status', [WorkOrderStatus::Closed->value, WorkOrderStatus::Cancelled->value])
                    ->orWhere('closed_at', '>', $date->endOfDay()))
                ->where('status', '<>', WorkOrderStatus::Cancelled->value)
                ->orderBy('number')
                ->get();

            if ($workOrders->isEmpty()) {
                return [];
            }

            $ids = $workOrders->pluck('id')->all();
            $time = Uninvoiced::live(TimeEntry::query()->whereIn('work_order_id', $ids))->where('billable', true)->whereDate('work_date', '<=', $date->toDateString())->get()->groupBy('work_order_id');
            $material = Uninvoiced::live(MaterialEntry::query()->whereIn('work_order_id', $ids))->where('billable', true)->whereDate('work_date', '<=', $date->toDateString())->get()->groupBy('work_order_id');
            $issued = Invoice::query()->whereIn('id', WorkOrderInvoice::query()->select('invoice_id')->whereIn('work_order_id', $ids))
                ->where('status', '<>', InvoiceStatus::Draft->value)->whereDate('invoice_date', '<=', $date->toDateString())->get(['id', 'net_ore', 'invoice_date'])->keyBy('id');
            $links = WorkOrderInvoice::query()->whereIn('work_order_id', $ids)->get()->groupBy('work_order_id');
            $changeOrders = ChangeOrder::query()->whereIn('work_order_id', $ids)->get()->groupBy('work_order_id');
            $projectCosts = self::projectCosts($workOrders->pluck('project_id')->filter()->map(fn (mixed $id): int => (int) $id)->values()->all(), $date);
            $result = [];

            foreach ($workOrders as $workOrder) {
                $entries = collect([...($time->get($workOrder->id) ?? collect())->all(), ...($material->get($workOrder->id) ?? collect())->all()]);
                $invoiceIds = ($links->get($workOrder->id) ?? collect())->pluck('invoice_id')->all();
                $invoiced = (int) $issued->only($invoiceIds)->sum(fn (Invoice $invoice): int => $invoice->net_ore->ore);
                $revenue = $workOrder->reverse_charge ? InvoiceBooking::REVERSE_CHARGE_CONSTRUCTION : (SaveArticle::DEFAULT_ACCOUNTS[$workOrder->vat_rate] ?? 3001);

                $result[] = $workOrder->price_form === PriceForm::Fixed
                    ? self::fixed($workOrder, $entries, $changeOrders->get($workOrder->id) ?? collect(), $invoiced, (int) ($projectCosts[$workOrder->project_id ?? 0] ?? 0), $estimatedCosts[$workOrder->id] ?? null, $method, $k1, $revenue)
                    : self::timeAndMaterial($workOrder, $entries, $changeOrders->get($workOrder->id) ?? collect(), $links->get($workOrder->id) ?? collect(), $issued, $invoiced, $k1, $revenue);
            }

            return array_values(array_filter($result, fn (array $line): bool => array_filter($line['figures'], fn (?int $value): bool => (int) $value !== 0) !== []));
        });
    }

    /**
     * Main work and the ÄTA that may be invoiced (review P2-5): entries on
     * the main work and on decided ÄTA on löpande räkning at their value;
     * a decided fixed-price ÄTA at its amount, not its entries. ÄTA that are
     * not approved are not revenue yet.
     *
     * @param  Collection<int, TimeEntry|MaterialEntry>  $entries
     * @param  Collection<int, ChangeOrder>  $changeOrders
     * @param  Collection<int, WorkOrderInvoice>  $links
     * @param  Collection<string, Invoice>  $issued
     * @return array{work_order: WorkOrder, kind: string, figures: array<string, int|null>, rows: list<array{account: int, debit_ore: int, credit_ore: int}>, note: ?string}
     */
    private static function timeAndMaterial(WorkOrder $workOrder, Collection $entries, Collection $changeOrders, Collection $links, Collection $issued, int $invoiced, bool $k1, int $revenue): array
    {
        $decided = $changeOrders->filter(fn (ChangeOrder $changeOrder): bool => $changeOrder->status->allowsInvoicing())->keyBy('id');
        $byEntries = $entries->filter(fn (TimeEntry|MaterialEntry $entry): bool => $entry->change_order_id === null
            || ($decided->has($entry->change_order_id) && $decided->get($entry->change_order_id)?->price_form !== PriceForm::Fixed));
        $fixed = $decided->filter(fn (ChangeOrder $changeOrder): bool => $changeOrder->price_form === PriceForm::Fixed);
        $fixedBilled = $fixed->filter(fn (ChangeOrder $changeOrder): bool => $links->contains(fn (WorkOrderInvoice $link): bool => $issued->has($link->invoice_id) && in_array($changeOrder->id, (array) $link->change_order_ids, true)));

        $performed = (int) $byEntries->sum(fn (TimeEntry|MaterialEntry $entry): int => $entry->netOre()) + (int) $fixed->sum(fn (ChangeOrder $changeOrder): int => $changeOrder->netOre());
        $billed = (int) $byEntries->filter(fn (TimeEntry|MaterialEntry $entry): bool => $entry->invoice_id !== null && $issued->has($entry->invoice_id))->sum(fn (TimeEntry|MaterialEntry $entry): int => $entry->netOre())
            + (int) $fixedBilled->sum(fn (ChangeOrder $changeOrder): int => $changeOrder->netOre());
        $unbilled = $performed - $billed;
        $ahead = max(0, $invoiced - $billed);
        $rows = [];

        if (! $k1 && $unbilled > 0) {
            $rows[] = ['account' => self::UNBILLED, 'debit_ore' => $unbilled, 'credit_ore' => 0];
            $rows[] = ['account' => $revenue, 'debit_ore' => 0, 'credit_ore' => $unbilled];
        }

        if (! $k1 && $ahead > 0) {
            $rows[] = ['account' => $revenue, 'debit_ore' => $ahead, 'credit_ore' => 0];
            $rows[] = ['account' => self::ADVANCES, 'debit_ore' => 0, 'credit_ore' => $ahead];
        }

        return [
            'work_order' => $workOrder,
            'kind' => 'TIME_AND_MATERIAL',
            'figures' => ['performed' => $performed, 'unbilled' => $unbilled, 'invoiced' => $invoiced, 'ahead' => $ahead],
            'rows' => $rows,
            'note' => $k1 ? (string) __('K1: siffrorna visas utan förslag till verifikation.') : null,
        ];
    }

    /**
     * @param  Collection<int, TimeEntry|MaterialEntry>  $entries
     * @param  Collection<int, ChangeOrder>  $changeOrders
     * @return array{work_order: WorkOrder, kind: string, figures: array<string, int|null>, rows: list<array{account: int, debit_ore: int, credit_ore: int}>, note: ?string}
     */
    private static function fixed(WorkOrder $workOrder, Collection $entries, Collection $changeOrders, int $invoiced, int $costs, ?int $estimated, FixedPriceMethod $method, bool $k1, int $revenue): array
    {
        // ÄTA on a fixed-price job count into it (AO-R32): fixed ÄTA by their amounts, the others by the work recorded on them.
        $decided = $changeOrders->filter(fn (ChangeOrder $changeOrder): bool => $changeOrder->status->allowsInvoicing());
        $changes = (int) $decided->sum(fn (ChangeOrder $changeOrder): int => $changeOrder->price_form === PriceForm::Fixed
            ? $changeOrder->netOre()
            : (int) $entries->where('change_order_id', $changeOrder->id)->sum(fn (TimeEntry|MaterialEntry $entry): int => $entry->netOre()));
        $contract = (int) $workOrder->contract_sum_ore?->ore + $changes;
        $degree = $estimated !== null && $estimated > 0 ? min(10_000, intdiv($costs * 10_000, $estimated)) : null;
        $earned = $degree === null ? null : intdiv($contract * $degree, 10_000);
        $rows = [];
        $note = null;

        if ($k1) {
            $note = (string) __('K1: siffrorna visas utan förslag till verifikation.');
        } elseif ($method === FixedPriceMethod::NotSet) {
            $note = (string) __('Välj metod för fastprisuppdrag under Arbetsorder → Inställningar för att få ett förslag.');
        } elseif ($method->recognisesProgress() && $earned === null) {
            $note = (string) __('Skriv den beräknade totala kostnaden för att räkna ut färdigställandegraden.');
        } elseif ($method->recognisesProgress()) {
            $difference = $earned - $invoiced;
            $rows = $difference >= 0
                ? [['account' => self::UNBILLED, 'debit_ore' => $difference, 'credit_ore' => 0], ['account' => $revenue, 'debit_ore' => 0, 'credit_ore' => $difference]]
                : [['account' => $revenue, 'debit_ore' => -$difference, 'credit_ore' => 0], ['account' => self::BILLED_AHEAD, 'debit_ore' => 0, 'credit_ore' => -$difference]];
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['debit_ore'] + $row['credit_ore'] > 0));
        } else {
            if ($invoiced > 0) {
                $rows = [['account' => $revenue, 'debit_ore' => $invoiced, 'credit_ore' => 0], ['account' => self::BILLED_AHEAD, 'debit_ore' => 0, 'credit_ore' => $invoiced]];
            }

            $note = (string) __('Kostnader som får tas upp som pågående arbete (1470) visas som siffra. Motkontot för förändringen är inte bekräftat än, så det blir ingen verifikation för den.');
        }

        // A probable loss is expensed at once (K2 6.19, review P3-7): advice only, never a proposal. TODO(legal-verify): L13.
        if ($estimated !== null && $estimated > $contract) {
            $loss = (string) __('Den beräknade totala kostnaden är :loss högre än priset med ÄTA. En befarad förlust ska kostnadsföras direkt (K2 6.19); förslaget tar inte med den, så bedöm den med din redovisningskonsult.', ['loss' => Money::ofOre($estimated - $contract)->format()]);
            $note = $note === null ? $loss : "{$note} {$loss}";
        }

        return [
            'work_order' => $workOrder,
            'kind' => 'FIXED',
            'figures' => ['contract' => $contract, 'change_orders' => $changes, 'costs' => $costs, 'estimated' => $estimated, 'degree_bp' => $degree, 'earned' => $earned, 'invoiced' => $invoiced],
            'rows' => $rows,
            'note' => $note,
        ];
    }

    /**
     * The costs booked on each project up to the date: posted rows on cost
     * accounts 4000–7999, debit less credit.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, int>
     */
    private static function projectCosts(array $projectIds, CarbonImmutable $date): array
    {
        if ($projectIds === []) {
            return [];
        }

        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->whereIn('voucher_rows.project_id', $projectIds)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
            ->whereBetween('accounts.number', [4000, 7999])
            ->groupBy('voucher_rows.project_id')
            ->selectRaw('voucher_rows.project_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS cost')
            ->toBase()
            ->pluck('cost', 'project_id')
            ->map(fn (mixed $cost): int => (int) $cost)
            ->all();
    }
}
