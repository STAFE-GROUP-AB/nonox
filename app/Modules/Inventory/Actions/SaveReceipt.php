<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes an inleverans (goods receipt) before anything is
 * received: from a supplier invoice or on its own, with lines of article,
 * quantity, location and cost. Those who handle goods may create one; only
 * those who manage the stock set the cost (otherwise the article's purchase
 * price is used). A receipt for a purchase order is made from the order
 * (fromPurchaseOrder) and ticks off its rows when goods arrive.
 */
class SaveReceipt
{
    use GuardsInventory;

    public const string SERIES = 'stock_receipts';

    /**
     * @param  array<string, mixed>  $input  supplier_id, supplier_invoice_id, expected_on, reference, note, lines: list<{article_id, location_id, quantity, unit_cost}>
     */
    public function __invoke(User $user, Company $company, array $input, ?StockReceipt $receipt = null): StockReceipt
    {
        $this->guard($user, $company, 'count');
        $setsCost = Gate::forUser($user)->allows('manage', [StockMovement::class, $company]);

        $validated = Validator::make($input, [
            'supplier_id' => ['nullable', 'integer'],
            'supplier_invoice_id' => ['nullable', 'uuid'],
            'expected_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.location_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required'],
            'lines.*.unit_cost' => ['nullable'],
        ], [
            'lines.required' => __('Lägg till minst en rad.'),
            'lines.min' => __('Lägg till minst en rad.'),
        ], [
            'lines.*.article_id' => __('artikel'),
            'lines.*.quantity' => __('antal'),
        ])->validate();

        return CurrentCompany::run($company, fn (): StockReceipt => DB::transaction(function () use ($validated, $receipt, $company, $user, $setsCost): StockReceipt {
            if ($receipt !== null && ($receipt->status !== StockReceipt::OPEN || $receipt->lines()->where('received_milli', '>', 0)->exists())) {
                throw ValidationException::withMessages(['receipt' => __('Inleveransen har redan tagits emot och kan inte ändras.')]);
            }

            if ($receipt?->purchase_order_id !== null) {
                throw ValidationException::withMessages(['receipt' => __('Inleveransen kommer från en inköpsorder. Makulera den och gör en ny från ordern.')]);
            }

            $invoice = null;

            if (($validated['supplier_invoice_id'] ?? null) !== null) {
                $invoice = SupplierInvoice::query()->whereKey((string) $validated['supplier_invoice_id'])->first()
                    ?? throw ValidationException::withMessages(['supplier_invoice_id' => __('Leverantörsfakturan finns inte.')]);
            }

            $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : $invoice?->supplier_id;

            if ($supplierId !== null && ! Supplier::query()->whereKey($supplierId)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => __('Leverantören finns inte.')]);
            }

            $lines = $this->lines((array) $validated['lines'], $setsCost);

            $attributes = [
                'supplier_id' => $supplierId,
                'supplier_invoice_id' => $invoice?->id,
                'expected_on' => $validated['expected_on'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
            ];

            if ($receipt === null) {
                $receipt = StockReceipt::create([...$attributes, 'number' => NumberSeries::take(self::SERIES), 'status' => StockReceipt::OPEN, 'created_by' => $user->id]);
            } else {
                $receipt->update($attributes);
                $receipt->lines()->delete();
            }

            foreach ($lines as $position => $line) {
                StockReceiptLine::create([...$line, 'stock_receipt_id' => $receipt->id, 'position' => $position]);
            }

            Audit::record('inventory.receipt_saved', $receipt, ['number' => $receipt->number, 'lines' => count($lines), 'supplier_invoice_id' => $receipt->supplier_invoice_id], $company, $user);

            return $receipt;
        }));
    }

    /**
     * The goods receipt for a sent purchase order (ADR-071 §2): one line per
     * row that still waits for goods, at the row's cost per stock unit and
     * the article's usual location. An order has at most one open receipt;
     * when there is one, that one is returned.
     */
    public function fromPurchaseOrder(User $user, Company $company, PurchaseOrder $order): StockReceipt
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockReceipt => DB::transaction(function () use ($user, $company, $order): StockReceipt {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->wasSent() || ! $order->status->isOpen()) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Bara en skickad inköpsorder som väntar på varor kan tas emot.')]);
            }

            $open = StockReceipt::query()->where('purchase_order_id', $order->id)->whereIn('status', [StockReceipt::OPEN, StockReceipt::PARTIAL])->latest('id')->first();

            if ($open !== null) {
                return $open;
            }

            $rows = $order->rows()->with('article:id,default_location_id,is_stock_item')->get()->filter(fn (PurchaseOrderRow $row): bool => $row->remainingMilli() > 0);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Allt på inköpsordern har redan tagits emot.')]);
            }

            $receipt = StockReceipt::create([
                'number' => NumberSeries::take(self::SERIES),
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'status' => StockReceipt::OPEN,
                'expected_on' => $rows->min(fn (PurchaseOrderRow $row): ?string => $row->expected_on?->toDateString()) ?? $order->requested_delivery_on?->toDateString(),
                'reference' => $order->title(),
                'created_by' => $user->id,
            ]);

            foreach ($rows->values() as $position => $row) {
                StockReceiptLine::create([
                    'stock_receipt_id' => $receipt->id,
                    'purchase_order_row_id' => $row->id,
                    'article_id' => $row->article_id,
                    'location_id' => $row->article?->default_location_id,
                    'ordered_milli' => $row->remainingMilli(),
                    'unit_cost_ore' => $row->stockUnitCostOre(),
                    'position' => $position,
                ]);
            }

            Audit::record('inventory.receipt_saved', $receipt, ['number' => $receipt->number, 'lines' => $rows->count(), 'purchase_order_id' => $order->id], $company, $user);

            return $receipt;
        }));
    }

    public function cancel(User $user, Company $company, StockReceipt $receipt): StockReceipt
    {
        $this->guard($user, $company, 'count');

        if ($receipt->status !== StockReceipt::OPEN || $receipt->lines()->where('received_milli', '>', 0)->exists()) {
            throw ValidationException::withMessages(['receipt' => __('Bara en inleverans där inget har tagits emot kan makuleras.')]);
        }

        return CurrentCompany::run($company, function () use ($receipt, $company, $user): StockReceipt {
            $receipt->update(['status' => StockReceipt::CANCELLED]);
            Audit::record('inventory.receipt_cancelled', $receipt, ['number' => $receipt->number], $company, $user);

            return $receipt;
        });
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, int|null>>
     */
    private function lines(array $rows, bool $setsCost): array
    {
        $articles = Article::query()->whereKey(array_map(fn (mixed $row): int => (int) ($row['article_id'] ?? 0), $rows))->get()->keyBy('id');
        $locations = StockLocation::query()->whereKey(array_filter(array_map(fn (mixed $row): int => (int) ($row['location_id'] ?? 0), $rows)))->where('is_active', true)->pluck('id')->all();
        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $article = $articles->get((int) $row['article_id']) ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte.')]);

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln :number är inte lagerförd.', ['number' => $article->number])]);
            }

            try {
                $quantity = Quantity::parse((string) $row['quantity']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Antalet måste vara större än noll.')]);
            }

            $locationId = ($row['location_id'] ?? null) ? (int) $row['location_id'] : $article->default_location_id;

            if ($locationId !== null && ! in_array($locationId, $locations, true) && ($row['location_id'] ?? null)) {
                throw ValidationException::withMessages(["lines.{$index}.location_id" => __('Lagerstället finns inte eller är inaktivt.')]);
            }

            $cost = $article->purchase_price_ore ?? 0;

            if ($setsCost && ($row['unit_cost'] ?? null) !== null && $row['unit_cost'] !== '') {
                try {
                    $cost = Money::parse((string) $row['unit_cost'])->ore;
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["lines.{$index}.unit_cost" => __('Beloppet går inte att läsa. Skriv till exempel 149,00.')]);
                }

                if ($cost < 0) {
                    throw ValidationException::withMessages(["lines.{$index}.unit_cost" => __('Beloppet kan inte vara negativt.')]);
                }
            }

            $lines[] = ['article_id' => $article->id, 'location_id' => $locationId, 'ordered_milli' => $quantity, 'unit_cost_ore' => $cost];
        }

        return $lines;
    }
}
