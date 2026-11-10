<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\QuantityOutOfRange;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Purchasing\PurchaseQuantities;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes a purchase order while it is a draft (ADR-071 §2). A
 * row is an article kept in stock, a quantity in any unit the article
 * converts to (§12.2), a price per that unit (the article's purchase price
 * converted when left empty) and an expected date. A row that goods have
 * already been received on stays, with at least what was received. Nothing
 * is booked.
 */
class SavePurchaseOrder
{
    use GuardsPurchaseOrders;

    public const int MAX_ROWS = 300;

    /** @var list<string> conversions that changed the typed quantity by more than 0,5 % (§12.4) */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $input  supplier_id, order_date, requested_delivery_on, our_reference, delivery_address, notes, rows: list<{id?, article_id, quantity, unit_id?, price?, expected_on?, supplier_article_number?, description?}>
     */
    public function __invoke(User $user, Company $company, array $input, ?PurchaseOrder $order = null, PurchaseOrderSource $source = PurchaseOrderSource::Manual): PurchaseOrder
    {
        $this->guard($user, $company, 'manage');
        $this->warnings = [];

        $validated = Validator::make($input, [
            'supplier_id' => ['required', 'integer'],
            'order_date' => ['nullable', 'date_format:Y-m-d'],
            'requested_delivery_on' => ['nullable', 'date_format:Y-m-d'],
            'our_reference' => ['nullable', 'string', 'max:100'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.article_id' => ['required', 'integer'],
            'rows.*.quantity' => ['required'],
            'rows.*.unit_id' => ['nullable', 'integer'],
            'rows.*.price' => ['nullable'],
            'rows.*.expected_on' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.supplier_article_number' => ['nullable', 'string', 'max:60'],
            'rows.*.description' => ['nullable', 'string', 'max:255'],
        ], [
            'supplier_id.required' => __('Välj leverantör.'),
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
            'rows.max' => __('En inköpsorder kan ha högst :max rader.', ['max' => self::MAX_ROWS]),
        ], [
            'rows.*.article_id' => __('artikel'),
            'rows.*.quantity' => __('antal'),
            'requested_delivery_on' => __('önskat leveransdatum'),
            'rows.*.expected_on' => __('väntas'),
        ])->validate();

        return CurrentCompany::run($company, fn (): PurchaseOrder => DB::transaction(function () use ($validated, $order, $company, $user, $source): PurchaseOrder {
            if ($order !== null) {
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $order->isDraft()) {
                    throw ValidationException::withMessages(['order' => __('Inköpsordern är skickad. Gör en ny version för att ändra den.')]);
                }
            }

            $supplier = Supplier::query()->find((int) $validated['supplier_id'])
                ?? throw ValidationException::withMessages(['supplier_id' => __('Leverantören finns inte.')]);

            $supplier->ensureNotRestricted('supplier_id');

            if ($order !== null && $order->wasSent() && $order->supplier_id !== $supplier->id) {
                throw ValidationException::withMessages(['supplier_id' => __('En skickad inköpsorder kan inte byta leverantör. Makulera den och gör en ny.')]);
            }

            $existing = $order === null ? collect() : $order->rows()->get()->keyBy('id');
            $rows = $this->rows(array_values((array) $validated['rows']), $supplier, $existing);

            $attributes = [
                'supplier_id' => $supplier->id,
                'order_date' => $validated['order_date'] ?? ($order?->order_date?->toDateString() ?? CarbonImmutable::today()->toDateString()),
                'requested_delivery_on' => $validated['requested_delivery_on'] ?? null,
                'our_reference' => self::text($validated['our_reference'] ?? null),
                'delivery_address' => self::text($validated['delivery_address'] ?? null),
                'notes' => self::text($validated['notes'] ?? null),
            ];

            if ($order === null) {
                $order = PurchaseOrder::create([
                    ...$attributes,
                    'delivery_address' => $attributes['delivery_address'] ?? self::defaultDeliveryAddress($company),
                    'number' => NumberSeries::take(PurchaseOrder::SERIES),
                    'status' => PurchaseOrderStatus::Draft,
                    'source' => $source,
                    'created_by' => $user->id,
                ]);
            } else {
                $order->update($attributes);
            }

            $kept = [];

            foreach ($rows as $position => $row) {
                $id = $row['id'];
                unset($row['id']);

                if ($id !== null) {
                    $existing->get($id)?->update([...$row, 'position' => $position]);
                    $kept[] = $id;

                    continue;
                }

                PurchaseOrderRow::create([...$row, 'purchase_order_id' => $order->id, 'position' => $position]);
            }

            $removed = $existing->keys()->diff($kept)->all();

            if ($removed !== []) {
                $this->guardRemoval($existing->only($removed)->all());
                PurchaseOrderRow::query()->whereKey($removed)->delete();
            }

            Audit::record('inventory.purchase_order_saved', $order, ['number' => $order->number, 'version' => $order->version, 'rows' => count($rows), 'source' => $order->source->value], $company, $user);

            return $order->load('rows');
        }));
    }

    /**
     * @param  list<mixed>  $input
     * @param  Collection<int, PurchaseOrderRow>  $existing
     * @return list<array<string, mixed>>
     */
    private function rows(array $input, Supplier $supplier, Collection $existing): array
    {
        $articles = Article::query()->with(['stockUnit', 'purchaseUnit'])
            ->whereKey(array_map(fn (mixed $row): int => (int) ($row['article_id'] ?? 0), $input))
            ->get()->keyBy('id');
        $conversions = PurchaseQuantities::conversions($articles->keys()->all());
        $unitIds = array_filter(array_map(fn (mixed $row): int => (int) ($row['unit_id'] ?? 0), $input));
        $units = Unit::query()->whereKey([...$unitIds, ...$articles->pluck('purchase_unit_id')->filter()->all(), ...$articles->pluck('stock_unit_id')->filter()->all()])->get()->keyBy('id');
        $rows = [];

        foreach ($input as $index => $row) {
            $article = $articles->get((int) $row['article_id']) ?? throw ValidationException::withMessages(["rows.{$index}.article_id" => __('Artikeln finns inte.')]);

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(["rows.{$index}.article_id" => __('Artikeln :number är inte lagerförd. Inköpsorder gäller artiklar i lager.', ['number' => $article->number])]);
            }

            $current = null;

            if (($row['id'] ?? null) !== null && $row['id'] !== '') {
                $current = $existing->get((int) $row['id']) ?? throw ValidationException::withMessages(["rows.{$index}.id" => __('Raden hör inte till inköpsordern.')]);
            }

            $unitId = (int) (($row['unit_id'] ?? null) ?: ($article->purchase_unit_id ?? $article->stock_unit_id));
            $unit = $units->get($unitId) ?? throw ValidationException::withMessages(["rows.{$index}.unit_id" => __('Enheten finns inte.')]);

            if ($unit->isArchived() && $current?->unit_id !== $unit->id) {
                throw ValidationException::withMessages(["rows.{$index}.unit_id" => __('Enheten :unit är arkiverad. Välj en annan enhet.', ['unit' => $unit->code])]);
            }

            $perStock = PurchaseQuantities::perStock($article, $unit, $conversions[$article->id] ?? []);
            $stockCode = ArticleUnits::stockCode($article);

            if ($perStock === null) {
                throw ValidationException::withMessages(["rows.{$index}.unit_id" => __(':name har enheten :unit. Lägg till en omräkning från :entered på artikeln, eller skriv mängden i :unit.', ['name' => $article->name, 'unit' => $stockCode, 'entered' => $unit->code])]);
            }

            try {
                $entered = Quantity::parse((string) $row['quantity']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
            }

            if ($entered <= 0) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet måste vara större än noll.')]);
            }

            try {
                $conversion = UnitConverter::toStock(Quantity::decimal($entered), $perStock);
            } catch (QuantityRoundsToZero) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Mängden blir noll i lagerenheten. Skriv en större mängd.')]);
            } catch (QuantityOutOfRange) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Mängden är för stor för lagerenheten.')]);
            }

            if ($conversion->warns) {
                $this->warnings[] = __(':name: :entered :unit blir :stock :stockUnit i lagerenheten, en ändring på :percent %.', ['name' => $article->name, 'entered' => Quantity::format($entered), 'unit' => $unit->code, 'stock' => Quantity::format($conversion->stockMilli), 'stockUnit' => $stockCode, 'percent' => $conversion->changePercent]);
            }

            if ($current !== null && $current->received_milli > 0) {
                if ($current->article_id !== $article->id) {
                    throw ValidationException::withMessages(["rows.{$index}.article_id" => __('Varor har tagits emot på raden, så artikeln kan inte bytas.')]);
                }

                if ($conversion->stockMilli < $current->received_milli) {
                    throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet kan inte bli mindre än det som redan har tagits emot (:received :unit).', ['received' => Quantity::format($current->received_milli), 'unit' => $stockCode])]);
                }
            }

            $price = PurchaseQuantities::pricePerEntered($article->purchase_price_ore ?? 0, $perStock);

            if (($row['price'] ?? null) !== null && $row['price'] !== '') {
                try {
                    $price = Money::parse((string) $row['price'])->ore;
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["rows.{$index}.price" => __('Beloppet går inte att läsa. Skriv till exempel 149,00.')]);
                }

                if ($price < 0) {
                    throw ValidationException::withMessages(["rows.{$index}.price" => __('Beloppet kan inte vara negativt.')]);
                }
            }

            $ownNumber = $article->default_supplier_id === null || $article->default_supplier_id === $supplier->id ? $article->supplier_article_number : null;

            $rows[] = [
                'id' => $current?->id,
                'article_id' => $article->id,
                'supplier_article_number' => self::text($row['supplier_article_number'] ?? null) ?? $ownNumber,
                'description' => self::text($row['description'] ?? null) ?? mb_substr($article->name, 0, 255),
                'entered_quantity' => Quantity::decimal($entered),
                'unit_id' => $unit->id,
                'quantity_milli' => $conversion->stockMilli,
                'price_ore' => $price,
                'expected_on' => ($row['expected_on'] ?? null) ?: null,
            ];
        }

        return $rows;
    }

    /**
     * A row goods have been received on, or that sits on a goods receipt,
     * stays on the order.
     *
     * @param  array<int, PurchaseOrderRow>  $rows
     */
    private function guardRemoval(array $rows): void
    {
        foreach ($rows as $row) {
            if ($row->received_milli > 0) {
                throw ValidationException::withMessages(['rows' => __('Varor har tagits emot på raden :name, så den kan inte tas bort.', ['name' => $row->description])]);
            }
        }

        if (StockReceiptLine::query()->whereIn('purchase_order_row_id', array_keys($rows))->exists()) {
            throw ValidationException::withMessages(['rows' => __('En rad du tog bort finns på en inleverans. Makulera inleveransen först.')]);
        }
    }

    /**
     * Where goods are delivered when nothing else is written: the first
     * warehouse with an address, otherwise the company's own address.
     */
    public static function defaultDeliveryAddress(Company $company): ?string
    {
        $warehouse = StockLocation::query()->where('type', LocationType::Warehouse->value)->where('is_active', true)->whereNotNull('address_line1')->orderBy('sort_key')->first();
        $lines = $warehouse !== null
            ? [$company->legal_name ?: $company->name, $warehouse->address_line1, trim($warehouse->postal_code.' '.$warehouse->city)]
            : [$company->legal_name ?: $company->name, $company->address_line1, $company->address_line2, trim($company->postal_code.' '.$company->city)];

        $text = implode("\n", array_filter(array_map(fn (?string $line): string => trim((string) $line), $lines), fn (string $line): bool => $line !== ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
