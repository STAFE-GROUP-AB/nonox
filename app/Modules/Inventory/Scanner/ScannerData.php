<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Scanner;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use Illuminate\Support\Facades\Gate;

/**
 * What the scanning app keeps on the phone for offline work (ADR-040): the
 * stocked articles (codes, name, unit, default location; never a price or
 * a cost, whoever asks), the active locations, the stock per location, and
 * the open receipts, pick lists and stocktakes with their lines. The same
 * number of queries whatever the size of the stock.
 */
final class ScannerData
{
    /** @return array<string, mixed> */
    public static function for(User $user, Company $company): array
    {
        $articles = Article::query()->where('is_stock_item', true)->where('is_active', true)
            ->with(['images' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('number')
            ->get(['id', 'number', 'name', 'unit', 'gtin', 'scanner_code', 'sku', 'default_location_id']);

        $locations = StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name', 'sort_key']);

        $levels = StockLevel::query()->where('quantity_milli', '<>', 0)->get(['article_id', 'location_id', 'quantity_milli']);

        $receipts = StockReceipt::query()->whereIn('status', [StockReceipt::OPEN, StockReceipt::PARTIAL])
            ->with(['supplier:id,name', 'lines' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('number')->get();

        $pickLists = PickList::query()->where('status', PickList::OPEN)
            ->with(['lines' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('number')->get();

        $counts = StockCount::query()->where('status', StockCount::OPEN)
            ->with(['location:id,full_code', 'lines' => fn ($query) => $query->orderBy('id')])
            ->orderBy('number')->get();

        $can = Gate::forUser($user);

        return [
            'context' => [
                'company_id' => $company->id,
                'company' => $company->name,
                'user_id' => $user->id,
                'user' => $user->name,
            ],
            'can' => [
                'count' => $can->allows('count', [StockMovement::class, $company]),
            ],
            'articles' => $articles->map(fn (Article $article): array => self::article($article))->values()->all(),
            'locations' => $locations->map(fn (StockLocation $location): array => [
                'id' => $location->id,
                'code' => $location->full_code,
                'name' => $location->name,
                'sort' => $location->sort_key,
            ])->values()->all(),
            'levels' => $levels->map(fn (StockLevel $level): array => [$level->article_id, $level->location_id, $level->quantity_milli])->values()->all(),
            'receipts' => $receipts->map(fn (StockReceipt $receipt): array => [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'label' => trim(implode(' · ', array_filter([$receipt->supplier?->name, $receipt->reference]))),
                'expected_on' => $receipt->expected_on?->toDateString(),
                'lines' => $receipt->lines->map(fn (StockReceiptLine $line): array => [
                    'id' => $line->id,
                    'article_id' => $line->article_id,
                    'location_id' => $line->location_id,
                    'ordered' => $line->ordered_milli,
                    'received' => $line->received_milli,
                ])->values()->all(),
            ])->values()->all(),
            'pick_lists' => $pickLists->map(fn (PickList $list): array => [
                'id' => $list->id,
                'number' => $list->number,
                'label' => $list->source_label ?? $list->note,
                'lines' => $list->lines->map(fn (PickListLine $line): array => [
                    'id' => $line->id,
                    'article_id' => $line->article_id,
                    'location_id' => $line->location_id,
                    'quantity' => $line->quantity_milli,
                    'picked' => $line->picked_milli,
                ])->values()->all(),
            ])->values()->all(),
            'counts' => $counts->map(fn (StockCount $count): array => self::count($count))->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function count(StockCount $count): array
    {
        return [
            'id' => $count->id,
            'number' => $count->number,
            'location_id' => $count->location_id,
            'location' => $count->location?->full_code,
            'lines' => $count->lines->map(fn (StockCountLine $line): array => [
                'id' => $line->id,
                'article_id' => $line->article_id,
                'location_id' => $line->location_id,
                'system' => $line->system_milli,
                'counted' => $line->counted_milli,
                'reason' => $line->reason,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function article(Article $article): array
    {
        $image = $article->images->first();

        return [
            'id' => $article->id,
            'number' => $article->number,
            'name' => $article->name,
            'unit' => $article->unit,
            'gtin' => $article->gtin,
            'scanner_code' => $article->scanner_code,
            'sku' => $article->sku,
            'default_location_id' => $article->default_location_id,
            'image' => $image instanceof ArticleImage ? route('inventory.scanner.image', $image, absolute: false) : null,
        ];
    }
}
