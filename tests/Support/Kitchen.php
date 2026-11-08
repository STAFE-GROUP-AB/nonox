<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * Kök in tests (ADR-073): the rule sheet's synthetic restaurant
 * "Exempelkrogen AB" with the ingredients of fixtures F-RS-1 and F-RS-2
 * received at their unit costs, the sub-recipe Vitlökssmör and the dish
 * Pasta med svamp. Invented prices, never a real restaurant's data.
 */
final class Kitchen
{
    /**
     * @return array{owner: User, company: Company, places: array{warehouse: StockLocation, zone: StockLocation, bin1: StockLocation, bin2: StockLocation}}
     */
    public static function company(bool $kitchen = true): array
    {
        [$owner, $company] = Bookkeeping::company();
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);

        if ($kitchen) {
            app(ModuleManager::class)->enable(KitchenModule::KEY, $company, $owner);
        }

        CurrentCompany::set($company);

        return ['owner' => $owner, 'company' => $company, 'places' => $places];
    }

    /** A stocked ingredient. */
    public static function ingredient(Company $company, User $owner, string $number, string $name, string $unit, string $cost): Article
    {
        return app(SaveArticle::class)($owner, $company, [
            'number' => $number,
            'name' => $name,
            'unit' => $unit,
            'price' => '0,00',
            'vat_rate' => 600,
            'purchase_price' => $cost,
            'is_stock_item' => true,
        ]);
    }

    /** A dish on the menu: not stocked, price excluding VAT. */
    public static function dish(Company $company, User $owner, string $number, string $name, string $netPrice, int $vatRate = 1200): Article
    {
        return app(SaveArticle::class)($owner, $company, [
            'number' => $number,
            'name' => $name,
            'unit' => 'st',
            'price' => $netPrice,
            'vat_rate' => $vatRate,
        ]);
    }

    /**
     * Fixtures F-RS-1 and F-RS-2: the ingredients received into bin 1 at
     * their unit costs on 2026-10-01, Vitlökssmör (yield 1 kg) and Pasta
     * med svamp (yield 10 portions, menu price 189,00 kr incl. 12 % =
     * 168,75 kr excl.), both valid from 2026-10-01.
     *
     * @return array{articles: array<string, Article>, butter: BillOfMaterials, pasta: BillOfMaterials}
     */
    public static function fixtures(Company $company, User $owner, StockLocation $location, string $quantity = '100'): array
    {
        $articles = [
            'smor' => self::ingredient($company, $owner, 'R-1', 'Smör', 'kg', '110,00'),
            'vitlok' => self::ingredient($company, $owner, 'R-2', 'Vitlök', 'kg', '50,00'),
            'pasta' => self::ingredient($company, $owner, 'R-3', 'Pasta, torr', 'kg', '32,00'),
            'champinjoner' => self::ingredient($company, $owner, 'R-4', 'Champinjoner', 'kg', '60,00'),
            'gradde' => self::ingredient($company, $owner, 'R-5', 'Vispgrädde', 'l', '45,00'),
            'parmesan' => self::ingredient($company, $owner, 'R-6', 'Parmesan', 'kg', '280,00'),
            'lok' => self::ingredient($company, $owner, 'R-7', 'Gul lök', 'kg', '15,00'),
            'vitloksmor' => self::ingredient($company, $owner, 'G-1', 'Vitlökssmör', 'kg', ''),
        ];

        foreach (['smor' => '110', 'vitlok' => '50', 'pasta' => '32', 'champinjoner' => '60', 'gradde' => '45', 'parmesan' => '280', 'lok' => '15'] as $key => $cost) {
            Inventory::receive($company, $owner, $articles[$key], $location, $quantity, $cost, '2026-10-01');
        }

        $writer = app(StructureWriter::class);
        $butter = $writer->save($company, $owner, StructureKind::Recipe, $articles['vitloksmor'], [
            'name' => 'Vitlökssmör',
            'valid_from' => '2026-10-01',
            'yield' => '1',
            'yield_unit' => BillOfMaterials::UNIT,
            'rows' => [
                ['article_id' => $articles['smor']->id, 'quantity' => '0,9'],
                ['article_id' => $articles['vitlok']->id, 'quantity' => '0,15', 'loss' => '20'],
            ],
        ]);

        $articles['dish'] = self::dish($company, $owner, 'M-1', 'Pasta med svamp', '168,75');
        $pasta = $writer->save($company, $owner, StructureKind::Recipe, $articles['dish'], [
            'name' => 'Pasta med svamp',
            'valid_from' => '2026-10-01',
            'yield' => '10',
            'yield_unit' => BillOfMaterials::PORTIONS,
            'rows' => [
                ['article_id' => $articles['pasta']->id, 'quantity' => '1'],
                ['article_id' => $articles['champinjoner']->id, 'quantity' => '1,2', 'loss' => '10'],
                ['article_id' => $articles['gradde']->id, 'quantity' => '0,8'],
                ['article_id' => $articles['parmesan']->id, 'quantity' => '250', 'unit' => 'g', 'loss' => '4'],
                ['article_id' => $articles['lok']->id, 'quantity' => '0,4', 'loss' => '12'],
                ['article_id' => $articles['vitloksmor']->id, 'quantity' => '0,1'],
            ],
        ]);

        return ['articles' => $articles, 'butter' => $butter, 'pasta' => $pasta];
    }
}
