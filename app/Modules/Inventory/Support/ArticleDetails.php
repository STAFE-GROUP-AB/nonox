<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Article;
use App\Models\Company;
use App\Models\Supplier;
use App\Modules\Inventory\Domain\Gtin;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StockClass;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The advanced fields of an article (the Avancerad tab, ADR-039): codes,
 * description, attributes, measures, purchase price, VAT treatment,
 * supplier, and the stock fields. Only the keys given are validated and
 * returned, so the simple form and the API keep working unchanged. Runs
 * inside the company.
 */
final class ArticleDetails
{
    public const array KEYS = [
        'sku', 'gtin', 'scanner_code', 'brand', 'model', 'description', 'properties', 'colours', 'materials',
        'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'purchase_price', 'vat_treatment',
        'default_supplier_id', 'supplier_article_number', 'is_stock_item', 'reorder_point', 'reorder_quantity',
        'default_location_id', 'net_realisable_value', 'net_realisable_value_note', 'stock_class',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> attributes for the article
     */
    public static function attributes(array $input, Company $company, ?Article $article): array
    {
        $input = array_intersect_key($input, array_flip(self::KEYS));

        if ($input === []) {
            return [];
        }

        foreach (['colours', 'materials'] as $list) {
            if (isset($input[$list]) && is_string($input[$list])) {
                $input[$list] = array_values(array_filter(array_map('trim', explode(',', $input[$list])), fn (string $value): bool => $value !== ''));
            }
        }

        if (isset($input['properties']) && is_array($input['properties'])) {
            $input['properties'] = array_values(array_filter($input['properties'], fn (mixed $row): bool => is_array($row) && trim((string) ($row['key'] ?? '')) !== ''));
        }

        foreach ($input as $key => $value) {
            if ($value === '') {
                $input[$key] = null;
            }
        }

        $measure = ['nullable', 'integer', 'between:0,100000000'];
        $validated = Validator::make($input, [
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('articles', 'sku')->where('company_id', $company->id)->ignore($article?->id)],
            'gtin' => ['nullable', 'string', 'regex:/^\d{8}$|^\d{12,14}$/', Rule::unique('articles', 'gtin')->where('company_id', $company->id)->ignore($article?->id)],
            'scanner_code' => ['nullable', 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'properties' => ['nullable', 'array', 'max:30'],
            'properties.*.key' => ['required', 'string', 'max:60'],
            'properties.*.value' => ['nullable', 'string', 'max:255'],
            'colours' => ['nullable', 'array', 'max:20'],
            'colours.*' => ['string', 'max:40'],
            'materials' => ['nullable', 'array', 'max:20'],
            'materials.*' => ['string', 'max:60'],
            'weight_grams' => $measure,
            'length_mm' => $measure,
            'width_mm' => $measure,
            'height_mm' => $measure,
            'purchase_price' => ['nullable', 'string'],
            'vat_treatment' => ['nullable', Rule::in([Article::VAT_STANDARD, Article::VAT_MARGIN])],
            'default_supplier_id' => ['nullable', 'integer'],
            'supplier_article_number' => ['nullable', 'string', 'max:60'],
            'is_stock_item' => ['nullable', 'boolean'],
            'reorder_point' => ['nullable', 'string'],
            'reorder_quantity' => ['nullable', 'string'],
            'default_location_id' => ['nullable', 'integer'],
            'net_realisable_value' => ['nullable', 'string'],
            'net_realisable_value_note' => ['nullable', 'string', 'max:255'],
            'stock_class' => ['nullable', Rule::enum(StockClass::class)],
        ], [
            'gtin.regex' => __('EAN/GTIN ska vara 8, 12, 13 eller 14 siffror.'),
            'gtin.unique' => __('EAN-koden används redan av en annan artikel.'),
            'sku.unique' => __('SKU:n används redan av en annan artikel.'),
        ], [
            'sku' => __('SKU'),
            'gtin' => __('EAN/GTIN'),
            'weight_grams' => __('vikt'),
        ])->validate();

        if (($validated['gtin'] ?? null) !== null && ! Gtin::isValid((string) $validated['gtin'])) {
            throw ValidationException::withMessages(['gtin' => __('Kontrollsiffran i EAN-koden stämmer inte. Kontrollera siffrorna.')]);
        }

        $attributes = [];

        foreach (['sku', 'gtin', 'scanner_code', 'brand', 'model', 'description', 'supplier_article_number', 'net_realisable_value_note'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key] === null ? null : trim((string) $validated[$key]);
            }
        }

        foreach (['colours', 'materials'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key] === null || $validated[$key] === [] ? null : array_values(array_map('strval', (array) $validated[$key]));
            }
        }

        if (array_key_exists('properties', $validated)) {
            $rows = array_map(fn (array $row): array => ['key' => trim((string) $row['key']), 'value' => trim((string) ($row['value'] ?? ''))], (array) $validated['properties']);
            $attributes['properties'] = $rows === [] ? null : $rows;
        }

        foreach (['weight_grams', 'length_mm', 'width_mm', 'height_mm'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key] === null ? null : (int) $validated[$key];
            }
        }

        foreach (['purchase_price' => 'purchase_price_ore', 'net_realisable_value' => 'net_realisable_value_ore'] as $key => $column) {
            if (array_key_exists($key, $validated)) {
                $attributes[$column] = self::money($validated[$key], $key);
            }
        }

        foreach (['reorder_point' => 'reorder_point_milli', 'reorder_quantity' => 'reorder_quantity_milli'] as $key => $column) {
            if (array_key_exists($key, $validated)) {
                $attributes[$column] = self::quantity($validated[$key], $key);
            }
        }

        if (array_key_exists('stock_class', $validated)) {
            $attributes['stock_class'] = $validated['stock_class'] ?? null;
        }

        if (array_key_exists('vat_treatment', $validated)) {
            $attributes['vat_treatment'] = $validated['vat_treatment'] ?? Article::VAT_STANDARD;
        }

        if (array_key_exists('default_supplier_id', $validated)) {
            $supplier = $validated['default_supplier_id'];

            if ($supplier !== null && ! Supplier::query()->whereKey((int) $supplier)->exists()) {
                throw ValidationException::withMessages(['default_supplier_id' => __('Leverantören finns inte.')]);
            }

            $attributes['default_supplier_id'] = $supplier === null ? null : (int) $supplier;
        }

        if (array_key_exists('default_location_id', $validated)) {
            $location = $validated['default_location_id'];

            if ($location !== null && ! StockLocation::query()->whereKey((int) $location)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['default_location_id' => __('Lagerstället finns inte eller är inaktivt.')]);
            }

            $attributes['default_location_id'] = $location === null ? null : (int) $location;
        }

        if (array_key_exists('is_stock_item', $validated)) {
            $stocked = (bool) $validated['is_stock_item'];

            if (! $stocked && $article?->is_stock_item && StockLevel::query()->where('article_id', $article->id)->where('quantity_milli', '<>', 0)->exists()) {
                throw ValidationException::withMessages(['is_stock_item' => __('Artikeln har varor i lager. Plocka, kassera eller inventera bort dem innan den slutar vara lagerförd.')]);
            }

            $attributes['is_stock_item'] = $stocked;
        }

        return $attributes;
    }

    private static function money(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            $ore = Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 149,00.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages([$field => __('Beloppet kan inte vara negativt.')]);
        }

        return $ore;
    }

    private static function quantity(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            $milli = Quantity::parse((string) $value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli < 0) {
            throw ValidationException::withMessages([$field => __('Antalet kan inte vara negativt.')]);
        }

        return $milli;
    }
}
