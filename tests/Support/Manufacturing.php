<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * Tillverkning in tests (ADR-071 §3, §4, §6): a synthetic joinery with an
 * oak table made of a board top bought by the square metre, four legs,
 * screws by the piece (bought by the pack) and lacquer by the litre, and
 * three operations. Invented prices, never a real joinery's data.
 *
 * The table's bill of materials (batch 1) costs:
 * - top 1,62 m² × 1 450,00 = 234 900 öre (F-U2b);
 * - legs 4 st × 350,00 = 140 000 öre;
 * - screws 16 st × 0,95 = 1 520 öre;
 * - lacquer 3 dl = 0,3 l with 10 % scrap: gross 0,333 l × 240,00 = 7 992 öre;
 * - material 384 412 öre;
 * - Kapning 30 min × 450,00/h = 22 500, Montering 90 min × 480,00/h =
 *   72 000, Lackering 30 min × 520,00/h = 26 000: labour 120 500 öre;
 * - 504 912 öre per table, before overhead.
 */
final class Manufacturing
{
    /**
     * @return array{owner: User, company: Company, places: array{warehouse: StockLocation, zone: StockLocation, bin1: StockLocation, bin2: StockLocation}}
     */
    public static function company(bool $manufacturing = true): array
    {
        [$owner, $company] = Bookkeeping::company();
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);

        if ($manufacturing) {
            app(ModuleManager::class)->enable(ManufacturingModule::KEY, $company, $owner);
        }

        CurrentCompany::set($company);

        return ['owner' => $owner, 'company' => $company, 'places' => $places];
    }

    /**
     * @return array{articles: array{table: Article, top: Article, leg: Article, screw: Article, lacquer: Article}, operations: array{cut: Operation, assembly: Operation, lacquering: Operation}, bom: BillOfMaterials}
     */
    public static function fixtures(Company $company, User $owner): array
    {
        $save = app(SaveArticle::class);
        $articles = [
            'table' => $save($owner, $company, ['number' => 'BORD-180', 'name' => 'Ekbord 180', 'unit' => 'st', 'price' => '12000,00', 'vat_rate' => 2500]),
            'top' => $save($owner, $company, ['number' => 'EK-27', 'name' => 'Ekskiva 27 mm', 'stock_unit' => 'm²', 'price' => '0,00', 'vat_rate' => 2500, 'purchase_price' => '1450,00', 'is_stock_item' => true, 'material_length' => '2440', 'material_width' => '1220', 'material_thickness' => '27']),
            'leg' => $save($owner, $company, ['number' => 'BEN-70', 'name' => 'Bordsben ek', 'unit' => 'st', 'price' => '0,00', 'vat_rate' => 2500, 'purchase_price' => '350,00', 'is_stock_item' => true]),
            'screw' => $save($owner, $company, ['number' => 'SKR-550', 'name' => 'Skruv 5x50', 'unit' => 'st', 'price' => '0,00', 'vat_rate' => 2500, 'purchase_price' => '0,95', 'is_stock_item' => true]),
            'lacquer' => $save($owner, $company, ['number' => 'LACK-1', 'name' => 'Möbellack', 'stock_unit' => 'l', 'price' => '0,00', 'vat_rate' => 2500, 'purchase_price' => '240,00', 'is_stock_item' => true]),
        ];

        $operation = app(SaveOperation::class);
        $operations = [
            'cut' => $operation($owner, $company, ['name' => 'Kapning', 'hourly_cost' => '450,00', 'capacity_hours' => '16']),
            'assembly' => $operation($owner, $company, ['name' => 'Montering', 'hourly_cost' => '480,00']),
            'lacquering' => $operation($owner, $company, ['name' => 'Lackering', 'hourly_cost' => '520,00']),
        ];

        $bom = app(SaveBillOfMaterials::class)($owner, $company, $articles['table'], [
            'name' => 'Ekbord 180',
            'yield' => '1',
            'rows' => [
                ['article_id' => $articles['top']->id, 'quantity' => '1,62'],
                ['article_id' => $articles['leg']->id, 'quantity' => '4'],
                ['article_id' => $articles['screw']->id, 'quantity' => '16'],
                ['article_id' => $articles['lacquer']->id, 'quantity' => '3', 'unit' => 'dl', 'loss' => '10'],
                ['operation_id' => $operations['cut']->id, 'minutes' => '30'],
                ['operation_id' => $operations['assembly']->id, 'minutes' => '90'],
                ['operation_id' => $operations['lacquering']->id, 'minutes' => '30'],
            ],
        ]);

        return ['articles' => $articles, 'operations' => $operations, 'bom' => $bom];
    }

    /** A customer and an open quote (or order) with the given rows: article number, quantity, price. */
    public static function document(Company $company, User $owner, array $rows, string $kind = SalesDocument::QUOTE): SalesDocument
    {
        $customer = Customer::query()->first() ?? Customer::create(['number' => 1001, 'name' => 'Exempelkunden AB', 'payment_terms_days' => 30]);

        return app(SaveSalesDocument::class)($owner, $company, $kind, [
            'customer_id' => $customer->id,
            'document_date' => '2026-11-10',
            'rows' => array_map(fn (array $row): array => ['article' => $row[0], 'description' => $row[3] ?? $row[0], 'quantity' => $row[1], 'price' => $row[2], 'vat_rate' => 2500], $rows),
        ]);
    }

    /**
     * The fixture stock of tests/Fixtures/manufacturing/production-orders.json
     * received into bin A-01 on 2026-11-01, and the table made a stock
     * article so production orders can receive it.
     *
     * @param  array{articles: array<string, Article>}  $fx
     */
    public static function stock(Company $company, User $owner, array $fx, StockLocation $location, bool $legs = true): void
    {
        $fx['articles']['table']->forceFill(['is_stock_item' => true])->save();

        foreach (self::productionFixtures()['stock'] as $line) {
            if ($line['article'] === 'leg' && ! $legs) {
                continue;
            }

            Inventory::receive($company, $owner, $fx['articles'][$line['article']], $location, $line['quantity'], $line['unit_cost'], '2026-11-01');
        }
    }

    /** @return array<string, mixed> */
    public static function productionFixtures(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/manufacturing/production-orders.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
