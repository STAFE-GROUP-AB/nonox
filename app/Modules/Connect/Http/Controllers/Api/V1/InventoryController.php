<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Models\Article;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ApiInput;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Support\Files\StoredFiles;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Lager over the API (ADR-039, ADR-032): stocked articles with their stock
 * per location, the locations, the stock levels, and creating an inleverans
 * (optionally received at once). Quantities are decimal strings with up to
 * three decimals ("12.5"); costs are whole öre and only given to a
 * credential whose person may manage the stock. inventory:view is false
 * while the module is off.
 */
final class InventoryController
{
    use ActsAsCredential;

    public function articles(Request $request): JsonResponse
    {
        $prices = $this->seesPrices();
        $query = Article::query()->where('is_stock_item', true)->with(['stockLevels' => fn ($levels) => $levels->where('quantity_milli', '<>', 0)->with('location:id,full_code'), 'images']);

        if ($request->filled('gtin')) {
            $query->where('gtin', (string) $request->query('gtin'));
        }

        if ($request->filled('number')) {
            $query->where('number', (string) $request->query('number'));
        }

        return CursorList::respond($request, $query, fn (Article $article): array => self::present($article, $prices));
    }

    /**
     * An article's picture as stored: a JPEG. The same token that may list
     * the articles may fetch it; another company's picture answers 404.
     */
    public function image(int $article, int $image): Response
    {
        $model = ArticleImage::query()->where('article_id', $article)->findOrFail($image);

        return response((string) Storage::disk(StoredFiles::DISK)->get($model->path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function locations(Request $request): JsonResponse
    {
        return CursorList::respond($request, StockLocation::query(), fn (StockLocation $location): array => [
            'id' => $location->id,
            'parent_id' => $location->parent_id,
            'type' => $location->type->value,
            'code' => $location->code,
            'full_code' => $location->full_code,
            'name' => $location->name,
            'address' => $location->address_line1 === null ? null : ['line1' => $location->address_line1, 'postal_code' => $location->postal_code, 'city' => $location->city],
            'is_active' => $location->is_active,
            'created_at' => self::time($location->created_at),
            'updated_at' => self::time($location->updated_at),
        ]);
    }

    public function levels(Request $request): JsonResponse
    {
        $query = StockLevel::query()->with(['article:id,number', 'location:id,full_code']);

        if ($request->filled('location_id')) {
            $query->where('location_id', (int) $request->query('location_id'));
        }

        if ($request->filled('article_id')) {
            $query->where('article_id', (int) $request->query('article_id'));
        }

        return CursorList::respond($request, $query, fn (StockLevel $level): array => [
            'id' => $level->id,
            'article_id' => $level->article_id,
            'article_number' => $level->article?->number,
            'location_id' => $level->location_id,
            'location_code' => $level->location?->full_code,
            'quantity' => Quantity::decimal($level->quantity_milli),
            'updated_at' => self::time($level->updated_at),
        ]);
    }

    /**
     * Creates an inleverans; with "receive": true every line is received at
     * once on "received_on" (today by default), all in one transaction.
     */
    public function storeReceipt(Request $request, SaveReceipt $save, ReceiveStock $receive): JsonResponse
    {
        $body = ApiInput::body($request->json()->all());
        $lines = [];

        foreach ((array) ($body['lines'] ?? []) as $index => $line) {
            $line = (array) $line;
            $lines[] = [
                'article_id' => $line['article_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'quantity' => ApiInput::quantity($line['quantity'] ?? null, "lines.{$index}.quantity"),
                'unit_cost' => array_key_exists('unit_cost_ore', $line) && $line['unit_cost_ore'] !== null ? ApiInput::kronor($line['unit_cost_ore'], "lines.{$index}.unit_cost_ore") : null,
            ];
        }

        $date = isset($body['received_on']) && is_string($body['received_on']) ? $body['received_on'] : CarbonImmutable::today()->toDateString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages(['received_on' => __('Skriv datumet som ÅÅÅÅ-MM-DD.')]);
        }

        $receipt = DB::transaction(function () use ($body, $lines, $save, $receive, $date): StockReceipt {
            $receipt = $save($this->user(), $this->company(), [
                'supplier_id' => $body['supplier_id'] ?? null,
                'supplier_invoice_id' => $body['supplier_invoice_id'] ?? null,
                'expected_on' => $body['expected_on'] ?? null,
                'reference' => $body['reference'] ?? null,
                'note' => $body['note'] ?? null,
                'lines' => $lines,
            ]);

            if (($body['receive'] ?? false) === true) {
                $receipt = $receive->all($this->user(), $this->company(), $receipt, CarbonImmutable::parse($date));
            }

            return $receipt;
        });

        return CursorList::one($this->receipt($receipt->refresh()->load('lines')), 201);
    }

    /** @return array<string, mixed> */
    private function receipt(StockReceipt $receipt): array
    {
        $prices = $this->seesPrices();

        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'status' => $receipt->status,
            'supplier_id' => $receipt->supplier_id,
            'supplier_invoice_id' => $receipt->supplier_invoice_id,
            'expected_on' => $receipt->expected_on?->toDateString(),
            'reference' => $receipt->reference,
            'lines' => $receipt->lines->map(fn (StockReceiptLine $line): array => [
                'id' => $line->id,
                'article_id' => $line->article_id,
                'location_id' => $line->location_id,
                'quantity' => Quantity::decimal($line->ordered_milli),
                'received' => Quantity::decimal($line->received_milli),
                'unit_cost_ore' => $prices ? $line->unit_cost_ore : null,
            ])->all(),
            'created_at' => self::time($receipt->created_at),
            'updated_at' => self::time($receipt->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function present(Article $article, bool $prices): array
    {
        return [
            'id' => $article->id,
            'number' => $article->number,
            'name' => $article->name,
            'unit' => $article->unit,
            'sku' => $article->sku,
            'gtin' => $article->gtin,
            'scanner_code' => $article->scanner_code,
            'brand' => $article->brand,
            'model' => $article->model,
            'price_ore' => $article->price_ore->ore,
            'purchase_price_ore' => $prices ? $article->purchase_price_ore : null,
            'vat_rate' => $article->vat_rate,
            'vat_treatment' => $article->vat_treatment,
            'is_active' => $article->is_active,
            'default_location_id' => $article->default_location_id,
            'reorder_point' => $article->reorder_point_milli === null ? null : Quantity::decimal($article->reorder_point_milli),
            'stock' => [
                'quantity' => Quantity::decimal($article->stockLevels->sum('quantity_milli')),
                'locations' => $article->stockLevels->map(fn (StockLevel $level): array => [
                    'location_id' => $level->location_id,
                    'code' => $level->location?->full_code,
                    'quantity' => Quantity::decimal($level->quantity_milli),
                ])->values()->all(),
            ],
            'images' => $article->images->map(fn (ArticleImage $image): array => [
                'id' => $image->id,
                'position' => $image->position,
                'alt' => $image->alt,
                'url' => route('connect.api.inventory.articles.images.content', ['article' => $article->id, 'image' => $image->id]),
            ])->values()->all(),
            'updated_at' => self::time($article->updated_at),
        ];
    }

    private function seesPrices(): bool
    {
        return $this->user()->hasTeamPermission($this->company(), 'inventory:manage');
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
