<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Domain\Invoicing\TaxReduction;
use App\Models\Article;
use App\Models\Invoice;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Domain\LabourKindType;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderBuyer;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Collects the rows of an invoice made from a work order and turns them
 * into SaveDraftInvoice's input (ADR-074 §5, §6). Labour rows are marked
 * as ROT/RUT labour only for kinds of type LABOUR on a work order with a
 * tax reduction, and never on an ÄTA that opts out (AO-R13, AO-R17).
 */
final class InvoiceDraft
{
    /** The part of the contract sum this invoice takes, excluding VAT. */
    public int $contractNet = 0;

    /** @var list<int> fixed-price ÄTA whose amounts this invoice takes */
    public array $changeOrderIds = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<string> */
    private array $timeIds = [];

    /** @var list<string> */
    private array $materialIds = [];

    private int $labourMinutes = 0;

    /** @var array<int, string> the earlier invoices that report the hours when this one has none of its own */
    private array $hoursReportedOn = [];

    public function __construct(private readonly WorkOrder $workOrder) {}

    /**
     * The contract sum not yet invoiced and its labour part, excluding VAT.
     * Each invoice's labour part is its share of the contract's labour, so
     * an a conto invoice carries ROT on its own labour share (AO-R16) and the
     * final invoice the rest (L14: remainder rows only).
     *
     * @return array{net: int, labour: int}
     */
    public static function contractRemaining(WorkOrder $workOrder): array
    {
        $sum = (int) $workOrder->contract_sum_ore?->ore;
        $labour = (int) $workOrder->contract_labour_ore?->ore;
        $invoiced = WorkOrderInvoice::query()->where('work_order_id', $workOrder->id)->toBase()->pluck('contract_net_ore')->map(fn (mixed $net): int => (int) $net)->all();

        $labourInvoiced = array_sum(array_map(fn (int $net): int => self::labourShare($net, $sum, $labour), $invoiced));

        return ['net' => max(0, $sum - array_sum($invoiced)), 'labour' => max(0, $labour - $labourInvoiced)];
    }

    /** The labour part of an amount of the contract sum, rounded down to whole öre. */
    public static function labourShare(int $net, int $sum, int $labour): int
    {
        return $sum > 0 ? intdiv($net * $labour, $sum) : 0;
    }

    public static function changeOrderInvoiced(ChangeOrder $changeOrder): bool
    {
        return WorkOrderInvoice::query()->where('work_order_id', $changeOrder->work_order_id)
            ->whereJsonContains('change_order_ids', $changeOrder->id)->exists();
    }

    /** Rows for a part of a fixed price: labour and the rest apart when ROT or RUT applies. */
    public function fixedRows(string $prefix, int $net, int $labour, bool $eligible, ?string $label = null): void
    {
        $label ??= __('Fast pris enligt avtal');
        $labour = min($labour, $net);

        if ($this->reduces($eligible) && $labour > 0) {
            $this->row("{$prefix}{$label}, ".__('arbete'), '1', 'st', $labour, $this->workOrder->vat_rate, true);

            if ($net - $labour > 0) {
                $this->row("{$prefix}{$label}, ".__('material och övrigt'), '1', 'st', $net - $labour, $this->workOrder->vat_rate, false);
            }

            return;
        }

        $this->row("{$prefix}{$label}", '1', 'st', $net, $this->workOrder->vat_rate, false);
    }

    /** A fixed-price ÄTA: its labour, material and other amounts. */
    public function changeOrderRows(string $prefix, ChangeOrder $changeOrder): void
    {
        $eligible = $changeOrder->tax_reduction_applies;
        $title = mb_substr($changeOrder->title, 0, 120);

        if ($changeOrder->labour_ore->ore > 0) {
            $this->row("{$prefix}{$title}, ".__('arbete'), '1', 'st', $changeOrder->labour_ore->ore, $this->workOrder->vat_rate, $this->reduces($eligible));
        }

        if ($changeOrder->material_ore->ore > 0) {
            $this->row("{$prefix}{$title}, ".__('material'), '1', 'st', $changeOrder->material_ore->ore, $this->workOrder->vat_rate, false);
        }

        if ($changeOrder->other_ore->ore > 0) {
            $this->row("{$prefix}{$title}, ".__('övrigt'), '1', 'st', $changeOrder->other_ore->ore, $this->workOrder->vat_rate, false);
        }

        $this->changeOrderIds[] = $changeOrder->id;
    }

    /**
     * Rows from recorded entries: time per kind of work and rate, material
     * per article, text and price.
     *
     * @param  Collection<int, TimeEntry>  $time
     * @param  Collection<int, MaterialEntry>  $material
     */
    public function entryRows(string $prefix, Collection $time, Collection $material, bool $eligible): void
    {
        foreach ($time->groupBy(fn (TimeEntry $entry): string => "{$entry->labour_kind_id}|{$entry->rate_ore}") as $group) {
            /** @var TimeEntry $first */
            $first = $group->first();
            $kind = $first->labourKind;
            $isTime = $first->minutes !== null;
            $quantity = $isTime
                ? self::decimal((int) round($group->sum('minutes') * 1000 / 60))
                : self::decimal((int) $group->sum('quantity_milli'));

            $this->row($prefix.($kind->name ?? __('Arbete')), $quantity, $kind?->unit->label() ?? 'tim', $first->rate_ore, $this->workOrder->vat_rate, $kind !== null && $kind->isLabour() && $this->reduces($eligible));
        }

        $articles = Article::query()->whereKey($material->pluck('article_id')->filter()->unique()->all())->pluck('number', 'id');

        foreach ($material->groupBy(fn (MaterialEntry $entry): string => "{$entry->article_id}|{$entry->description}|{$entry->unit_price_ore}|{$entry->vat_rate}|{$entry->unit}") as $group) {
            /** @var MaterialEntry $first */
            $first = $group->first();
            $this->row($prefix.$first->description, self::decimal((int) $group->sum('quantity_milli')), $first->unit, $first->unit_price_ore, $first->vat_rate, false, $first->article_id === null ? null : $articles->get($first->article_id));
        }

        $this->consume($time, $material, $eligible);
    }

    /**
     * Entries the invoice covers: billed above, or part of a fixed price.
     *
     * @param  Collection<int, TimeEntry>  $time
     * @param  Collection<int, MaterialEntry>  $material
     */
    public function consume(Collection $time, Collection $material, bool $eligible): void
    {
        foreach ($time as $entry) {
            $this->timeIds[] = $entry->id;
        }

        foreach ($material as $entry) {
            $this->materialIds[] = $entry->id;
        }
    }

    /**
     * The ROT/RUT labour minutes recorded on the work order up to a date:
     * live entries of a labour kind on the main work and on decided ÄTA
     * that keep the tax reduction (AO-R13, AO-R17).
     */
    public static function recordedLabourMinutes(WorkOrder $workOrder, CarbonImmutable $upTo): int
    {
        $optedOut = ChangeOrder::query()->where('work_order_id', $workOrder->id)
            ->where(fn ($query) => $query->where('tax_reduction_applies', false)
                ->orWhereIn('status', array_map(fn (ChangeOrderStatus $status): string => $status->value, array_filter(ChangeOrderStatus::cases(), fn (ChangeOrderStatus $status): bool => ! $status->allowsInvoicing()))))
            ->pluck('id')->all();

        return (int) Uninvoiced::live(TimeEntry::query()->where('work_order_id', $workOrder->id))
            ->whereNotNull('minutes')
            ->whereDate('work_date', '<=', $upTo->toDateString())
            ->whereIn('labour_kind_id', LabourKind::query()->select('id')->where('type', LabourKindType::Labour->value))
            ->where(fn ($query) => $query->whereNull('change_order_id')->orWhereNotIn('change_order_id', $optedOut === [] ? [0] : $optedOut))
            ->sum('minutes');
    }

    /**
     * Lead's rule (review P1-2): an invoice claims the labour minutes
     * recorded and not yet claimed by an earlier invoice of the same work
     * order. Returns [recorded, unclaimed].
     *
     * TODO(legal-verify): whether a ROT/RUT invoice with no new hours (all
     * hours claimed on earlier invoices of the order) is acceptable in the
     * claim (lag (2009:194) 9 § p. 4).
     *
     * @return array{0: int, 1: int}
     */
    public function claimLabourMinutes(CarbonImmutable $upTo): array
    {
        $recorded = self::recordedLabourMinutes($this->workOrder, $upTo);
        $claimed = (int) WorkOrderInvoice::query()->where('work_order_id', $this->workOrder->id)->sum('labour_minutes');
        $this->labourMinutes = max(0, $recorded - $claimed);
        $this->hoursReportedOn = $this->labourMinutes === 0 && $recorded > 0
            ? WorkOrderInvoice::query()->where('work_order_id', $this->workOrder->id)->where('labour_minutes', '>', 0)->orderBy('id')->pluck('invoice_id')->map(fn (mixed $id): string => (string) $id)->values()->all()
            : [];

        return [$recorded, $this->labourMinutes];
    }

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function hasLabour(): bool
    {
        return array_filter($this->rows, fn (array $row): bool => (bool) $row['labour']) !== [];
    }

    public function labourMinutes(): int
    {
        return $this->labourMinutes;
    }

    /** @return list<string> */
    public function timeIds(): array
    {
        return $this->timeIds;
    }

    /** @return list<string> */
    public function materialIds(): array
    {
        return $this->materialIds;
    }

    /** @return array<string, mixed> SaveDraftInvoice's input */
    public function input(CarbonImmutable $invoiceDate, string $workDescription): array
    {
        $workOrder = $this->workOrder;
        $taxReduction = $workOrder->tax_reduction !== null && $this->hasLabour() ? $workOrder->tax_reduction : null;
        $property = $workOrder->property_designation;

        if ($property === null && filled($workOrder->housing_association_org_number)) {
            $property = __('BRF :org, lägenhet :apartment', ['org' => $workOrder->housing_association_org_number, 'apartment' => (string) $workOrder->apartment_number]);
        }

        $buyers = $taxReduction === null ? [] : WorkOrderBuyer::query()->where('work_order_id', $workOrder->id)->orderBy('id')->get()
            ->map(fn (WorkOrderBuyer $buyer): array => ['customer_id' => $buyer->customer_id, 'share_bp' => $buyer->share_bp])->all();

        return [
            'customer_id' => $workOrder->customer_id,
            'invoice_date' => $invoiceDate->toDateString(),
            'our_reference' => __('Arbetsorder :number', ['number' => $workOrder->number]),
            'reverse_charge' => $workOrder->reverse_charge,
            'tax_reduction' => $taxReduction,
            'property_designation' => $taxReduction === TaxReduction::ROT ? $property : null,
            // Hours all reported on earlier invoices: "redovisade på faktura N" instead of 0. TODO(legal-verify): HFL 9 § p. 4.
            'labour_hours_milli' => $taxReduction === null || ($this->labourMinutes === 0 && $this->hoursReportedOn !== []) ? null : (int) round($this->labourMinutes * 1000 / 60),
            'labour_hours_reported_on' => $taxReduction === null || $this->labourMinutes > 0 ? null : ($this->hoursReportedOn === [] ? null : $this->hoursReportedOn),
            'work_description' => $taxReduction === null ? null : mb_substr($workDescription, 0, 255),
            ...($buyers === [] ? [] : ['tax_reduction_buyers' => $buyers]),
            'rows' => $this->rows,
        ];
    }

    /** Marks the entries as reserved by the draft; issuing it makes them invoiced. */
    public function reserve(Invoice $invoice): void
    {
        if ($this->timeIds !== []) {
            TimeEntry::query()->whereKey($this->timeIds)->update(['invoice_id' => $invoice->id]);
        }

        if ($this->materialIds !== []) {
            MaterialEntry::query()->whereKey($this->materialIds)->update(['invoice_id' => $invoice->id]);
        }
    }

    private function reduces(bool $eligible): bool
    {
        return $eligible && $this->workOrder->tax_reduction !== null;
    }

    private function row(string $description, string $quantity, string $unit, int $priceOre, int $vatRate, bool $labour, ?string $article = null): void
    {
        $this->rows[] = [
            'article' => $article,
            'description' => mb_substr($description, 0, 255),
            'quantity' => $quantity,
            'unit' => mb_substr($unit, 0, 10),
            'price' => self::kronor($priceOre),
            'vat_rate' => $vatRate,
            'labour' => $labour,
        ];
    }

    private static function decimal(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);
        $fraction = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($milli, 1000).($fraction === '' ? '' : ".{$fraction}");
    }

    private static function kronor(int $ore): string
    {
        $sign = $ore < 0 ? '-' : '';
        $ore = abs($ore);

        return $sign.intdiv($ore, 100).'.'.str_pad((string) ($ore % 100), 2, '0', STR_PAD_LEFT);
    }
}
