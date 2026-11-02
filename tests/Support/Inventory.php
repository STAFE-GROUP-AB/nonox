<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Lager in tests (ADR-039): the module on, a warehouse with bins, stocked
 * articles, and goods received at a cost.
 */
final class Inventory
{
    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(InventoryModule::KEY, $company, $owner);
        CurrentCompany::set($company);
    }

    /**
     * A warehouse HL with zone A and the bins A-01 and A-02 (full codes
     * HL-A-01 and HL-A-02).
     *
     * @return array{warehouse: StockLocation, zone: StockLocation, bin1: StockLocation, bin2: StockLocation}
     */
    public static function locations(Company $company, User $owner): array
    {
        $save = app(SaveLocation::class);
        $warehouse = $save($owner, $company, ['type' => 'WAREHOUSE', 'code' => 'HL', 'name' => 'Huvudlager', 'address_line1' => 'Lagergatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm']);
        $zone = $save($owner, $company, ['type' => 'ZONE', 'parent_id' => $warehouse->id, 'code' => 'A']);
        $bin1 = $save($owner, $company, ['type' => 'BIN', 'parent_id' => $zone->id, 'code' => '01']);
        $bin2 = $save($owner, $company, ['type' => 'BIN', 'parent_id' => $zone->id, 'code' => '02']);

        return ['warehouse' => $warehouse, 'zone' => $zone, 'bin1' => $bin1, 'bin2' => $bin2];
    }

    /** @param  array<string, mixed>  $extra */
    public static function article(Company $company, User $owner, string $number = 'V-1', string $name = 'Kaffekopp', array $extra = []): Article
    {
        return app(SaveArticle::class)($owner, $company, [
            'number' => $number,
            'name' => $name,
            'unit' => 'st',
            'price' => '199,00',
            'vat_rate' => 2500,
            'purchase_price' => '80,00',
            'is_stock_item' => true,
            ...$extra,
        ]);
    }

    /**
     * Receives goods at once: one receipt with one line.
     */
    public static function receive(Company $company, User $owner, Article $article, StockLocation $location, string $quantity, string $unitCost, string $date = '2026-03-01'): StockReceipt
    {
        $receipt = app(SaveReceipt::class)($owner, $company, ['lines' => [['article_id' => $article->id, 'location_id' => $location->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]]]);

        return app(ReceiveStock::class)->all($owner, $company, $receipt, CarbonImmutable::parse($date));
    }

    public static function level(Article $article, StockLocation $location): int
    {
        return (int) StockLevel::query()->where('article_id', $article->id)->where('location_id', $location->id)->value('quantity_milli');
    }
}
