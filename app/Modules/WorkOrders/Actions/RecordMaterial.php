<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Article;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\MaterialSource;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records material on a work order (ADR-074 §3):
 *
 * - STOCK: issues the article from a location through Lager as a
 *   WORK_ORDER_ISSUE movement, at the article's purchase price as cost;
 * - SUPPLIER_INVOICE_ROW: bills a row of an approved supplier invoice on to
 *   the customer, once (a unique index backs this up);
 * - FREE: typed by hand.
 *
 * The sales price is the article's price, or the cost with the work order's
 * mark-up. Only a manager may type prices; a field worker never sees them.
 * Material is never ROT or RUT labour (AO-R13).
 */
class RecordMaterial
{
    use GuardsWorkOrders;

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $input  source, article_id, location_id (STOCK), supplier_invoice_row_id, description,
     *                                       quantity, unit, unit_cost and unit_price (kronor, managers only), change_order_id,
     *                                       work_date, note, billable (managers only), id (uuid, optional)
     */
    public function __invoke(User $user, Company $company, WorkOrder $workOrder, array $input): MaterialEntry
    {
        $this->guardReport($user, $company, $workOrder);

        $validated = Validator::make($input, [
            'id' => ['nullable', 'uuid'],
            'source' => ['required', Rule::enum(MaterialSource::class)],
            'article_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'supplier_invoice_row_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:12'],
            'unit' => ['nullable', 'string', 'max:10'],
            'unit_cost' => ['nullable', 'string', 'max:20'],
            'unit_price' => ['nullable', 'string', 'max:20'],
            'change_order_id' => ['nullable', 'integer'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            'billable' => ['nullable', 'boolean'],
        ], [], ['work_date' => __('datum')])->validate();

        $source = MaterialSource::from((string) $validated['source']);
        $manager = $user->hasTeamPermission($company, WorkOrdersModule::MANAGE);

        if ($source === MaterialSource::Stock) {
            app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $company)
                ?: throw ValidationException::withMessages(['source' => __('Material från lager kräver modulen Lager.')]);
        }

        if ($source === MaterialSource::SupplierInvoiceRow && ! $manager) {
            throw ValidationException::withMessages(['source' => __('Bara den som hanterar arbetsordrar kan vidarefakturera leverantörsfakturor.')]);
        }

        return DB::transaction(fn (): MaterialEntry => CurrentCompany::run($company, function () use ($validated, $user, $company, $workOrder, $source, $manager): MaterialEntry {
            if (isset($validated['id']) && ($existing = MaterialEntry::query()->find($validated['id'])) instanceof MaterialEntry) {
                return $existing;
            }

            $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! $workOrder->status->acceptsReports()) {
                throw ValidationException::withMessages(['work_order' => __('Arbetsordern är :status. Material kan bara registreras när den är planerad eller pågår.', ['status' => mb_strtolower($workOrder->status->label())])]);
            }

            $changeOrder = null;

            if (filled($validated['change_order_id'] ?? null)) {
                $changeOrder = ChangeOrder::query()->where('work_order_id', $workOrder->id)->find((int) $validated['change_order_id'])
                    ?? throw ValidationException::withMessages(['change_order_id' => __('ÄTA:t finns inte på arbetsordern.')]);
            }

            $attributes = match ($source) {
                MaterialSource::SupplierInvoiceRow => $this->fromSupplierRow($workOrder, (int) ($validated['supplier_invoice_row_id'] ?? 0)),
                default => $this->fromArticleOrText($workOrder, $validated, $source),
            };

            if ($manager && filled($validated['unit_cost'] ?? null)) {
                $attributes['unit_cost_ore'] = (int) Input::money($validated['unit_cost'], 'unit_cost');
            }

            if ($manager && filled($validated['unit_price'] ?? null)) {
                $attributes['unit_price_ore'] = (int) Input::money($validated['unit_price'], 'unit_price');
            } elseif (! isset($attributes['unit_price_ore'])) {
                $attributes['unit_price_ore'] = (int) round($attributes['unit_cost_ore'] * (10_000 + $workOrder->material_markup_bp) / 10_000);
            }

            $entry = MaterialEntry::create([
                ...$attributes,
                'id' => $validated['id'] ?? null,
                'work_order_id' => $workOrder->id,
                'change_order_id' => $changeOrder?->id,
                'user_id' => $user->id,
                'source' => $source->value,
                'billable' => $manager ? (bool) ($validated['billable'] ?? true) : true,
                'work_date' => $validated['work_date'],
                'note' => $validated['note'] ?? null,
                'entered_at' => CarbonImmutable::now(),
                'created_by' => $user->id,
            ]);

            if ($source === MaterialSource::Stock) {
                $this->ledger->post($company, $user, MovementType::WorkOrderIssue, [[
                    'article_id' => (int) $entry->article_id,
                    'location_id' => (int) $entry->stock_location_id,
                    'quantity_milli' => -$entry->quantity_milli,
                ]], CarbonImmutable::parse((string) $validated['work_date']), $entry, __('Arbetsorder :number', ['number' => $workOrder->number]));
            }

            if ($workOrder->status === WorkOrderStatus::Planned) {
                $workOrder->forceFill(['status' => WorkOrderStatus::InProgress])->save();
            }

            Audit::record('work_orders.material_recorded', $entry, [
                'work_order_id' => $workOrder->id,
                'change_order_id' => $changeOrder?->id,
                'source' => $source->value,
                'article_id' => $entry->article_id,
                'supplier_invoice_row_id' => $entry->supplier_invoice_row_id,
                'quantity_milli' => $entry->quantity_milli,
            ], $company, $user);

            return $entry;
        }));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fromArticleOrText(WorkOrder $workOrder, array $validated, MaterialSource $source): array
    {
        $quantity = Input::milli($validated['quantity'] ?? null, 'quantity', __('Skriv antalet, till exempel 2 eller 1,5.'));

        if ($quantity === null || $quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Skriv ett antal större än noll.')]);
        }

        $article = filled($validated['article_id'] ?? null) ? Article::query()->find((int) $validated['article_id']) : null;

        if (filled($validated['article_id'] ?? null) && ! $article instanceof Article) {
            throw ValidationException::withMessages(['article_id' => __('Artikeln finns inte.')]);
        }

        if ($source === MaterialSource::Stock && ! $article instanceof Article) {
            throw ValidationException::withMessages(['article_id' => __('Välj artikeln som tas från lagret.')]);
        }

        if ($source === MaterialSource::Stock && blank($validated['location_id'] ?? null)) {
            throw ValidationException::withMessages(['location_id' => __('Välj lagerstället materialet tas från.')]);
        }

        $description = trim((string) ($validated['description'] ?? '')) ?: (string) $article?->name;

        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Skriv vilket material det är.')]);
        }

        $vatRate = $article !== null && in_array($article->vat_rate, InvoiceCalculator::VAT_RATES, true) ? $article->vat_rate : $workOrder->vat_rate;

        return [
            'article_id' => $article?->id,
            'description' => mb_substr($description, 0, 255),
            'quantity_milli' => $quantity,
            'unit' => mb_substr(trim((string) ($validated['unit'] ?? '')) ?: ($article->unit ?? 'st'), 0, 10),
            'unit_cost_ore' => $article->purchase_price_ore ?? 0,
            ...($article !== null && $article->price_ore->ore > 0 ? ['unit_price_ore' => $article->price_ore->ore] : []),
            'vat_rate' => $vatRate,
            'stock_location_id' => $source === MaterialSource::Stock ? (int) $validated['location_id'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function fromSupplierRow(WorkOrder $workOrder, int $rowId): array
    {
        $row = SupplierInvoiceRow::query()->find($rowId)
            ?? throw ValidationException::withMessages(['supplier_invoice_row_id' => __('Raden finns inte.')]);
        $invoice = SupplierInvoice::query()->find($row->supplier_invoice_id);

        if (! $invoice instanceof SupplierInvoice || ! in_array($invoice->status, [SupplierInvoiceStatus::Approved, SupplierInvoiceStatus::Paid], true) || $invoice->isCredit()) {
            throw ValidationException::withMessages(['supplier_invoice_row_id' => __('Bara rader på attesterade leverantörsfakturor kan vidarefaktureras.')]);
        }

        $taken = MaterialEntry::query()->where('supplier_invoice_row_id', $row->id)->whereNull('reverses_id')->whereNull('reversed_by_id')->exists();

        if ($taken) {
            throw ValidationException::withMessages(['supplier_invoice_row_id' => __('Raden är redan vidarefakturerad på en arbetsorder.')]);
        }

        return [
            'description' => mb_substr(trim((string) $row->description) ?: $invoice->supplierName(), 0, 255),
            'quantity_milli' => 1000,
            'unit' => 'st',
            'unit_cost_ore' => $row->amount_ore->ore,
            'vat_rate' => $workOrder->vat_rate,
            'supplier_invoice_row_id' => $row->id,
        ];
    }
}
