<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\Article;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\Actions\SaveRowStructure;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use LogicException;

/**
 * Tillverkning (ADR-071 §3, §4, §6) in the joinery, for the manual's
 * manufacturing pages and the landing page: five operations with an hourly
 * cost, oak board by the square metre, legs, handles, screws and lacquer,
 * the bills of materials of an oak table and a chest of drawers (its drawer
 * is a sub-assembly with its own bill of materials), and an open quote whose
 * table row has a copy changed for that row, and production orders in every
 * open status plus a finished one. Made-up articles and prices.
 */
final class ManufacturingExtras extends DemoExtra
{
    /** Name => [hourly cost, capacity in hours per day]. */
    private const array OPERATIONS = [
        'Kapning' => ['450,00', '16'],
        'Hyvling' => ['450,00', '8'],
        'Montering' => ['480,00', '16'],
        'Slipning' => ['420,00', '8'],
        'Lackering' => ['520,00', '8'],
    ];

    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Tillverkning', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $modules = app(ModuleManager::class);

            if (! $modules->isEnabled(InventoryModule::KEY, $c->company)) {
                $modules->enable(InventoryModule::KEY, $c->company, $c->user);
            }

            if (! $modules->isEnabled(ManufacturingModule::KEY, $c->company)) {
                $modules->enable(ManufacturingModule::KEY, $c->company, $c->user);
            }

            if (Operation::query()->exists()) {
                return;
            }

            $operations = [];

            foreach (self::OPERATIONS as $name => [$cost, $capacity]) {
                $operations[$name] = app(SaveOperation::class)($c->user, $c->company, ['name' => $name, 'hourly_cost' => $cost, 'capacity_hours' => $capacity])->id;
            }

            $operation = fn (string $name): int => $operations[$name] ?? throw new LogicException("Operation {$name} was not seeded.");

            $a = $this->articles($c);
            $bom = app(SaveBillOfMaterials::class);

            $bom($c->user, $c->company, $a['drawer'], ['name' => 'Låda till Byrå 80', 'yield' => '1', 'rows' => [
                ['article_id' => $a['board']->id, 'quantity' => '0,35', 'loss' => '12'],
                ['article_id' => $a['handle']->id, 'quantity' => '1'],
                ['article_id' => $a['screw']->id, 'quantity' => '8'],
                ['operation_id' => $operation('Kapning'), 'minutes' => '15'],
                ['operation_id' => $operation('Montering'), 'minutes' => '20'],
            ]]);

            $bom($c->user, $c->company, $a['table'], ['name' => 'Ekbord 180', 'yield' => '1', 'method' => 'Skivan limmas av två längder. Benen fräses för sargen.', 'rows' => [
                ['article_id' => $a['board']->id, 'quantity' => '1,62', 'loss' => '8'],
                ['article_id' => $a['leg']->id, 'quantity' => '4'],
                ['article_id' => $a['screw']->id, 'quantity' => '16'],
                ['article_id' => $a['lacquer']->id, 'quantity' => '3', 'unit' => 'dl', 'loss' => '10'],
                ['operation_id' => $operation('Kapning'), 'minutes' => '30'],
                ['operation_id' => $operation('Hyvling'), 'minutes' => '25'],
                ['operation_id' => $operation('Montering'), 'minutes' => '90'],
                ['operation_id' => $operation('Slipning'), 'minutes' => '45'],
                ['operation_id' => $operation('Lackering'), 'minutes' => '30'],
            ]]);

            $bom($c->user, $c->company, $a['chest'], ['name' => 'Byrå 80', 'yield' => '1', 'rows' => [
                ['article_id' => $a['board']->id, 'quantity' => '1,9', 'loss' => '10'],
                ['article_id' => $a['drawer']->id, 'quantity' => '4'],
                ['article_id' => $a['lacquer']->id, 'quantity' => '4', 'unit' => 'dl', 'loss' => '10'],
                ['operation_id' => $operation('Kapning'), 'minutes' => '40'],
                ['operation_id' => $operation('Montering'), 'minutes' => '120'],
                ['operation_id' => $operation('Slipning'), 'minutes' => '60'],
                ['operation_id' => $operation('Lackering'), 'minutes' => '45'],
            ]]);

            $this->quote($c, $a, $operations);
        }));

        // Separately, so a refusal here keeps the bills of materials and the quote above.
        $this->atomically($c, 'Tillverkningsorder', fn () => CurrentCompany::run($c->company, fn () => $this->productionOrders($c)));
    }

    /**
     * Production orders (ADR-071 §8) for the manual and the landing page: the
     * components received a month ago, two tables made for stock and
     * finished with the planned minutes, three tables started with every
     * component taken out (work in progress), two chests released for a
     * customer order row with its own copy, and a draft for stock.
     */
    private function productionOrders(DemoContext $c): void
    {
        if (ProductionOrder::query()->exists()) {
            return;
        }

        $articles = Article::query()->whereIn('number', ['EK-27-M2', 'BEN-EK70', 'HAND-MS', 'SKR-5X50', 'LACK-MATT', 'BORD-180', 'BYRA-80'])->get()->keyBy('number')->all();

        if (count($articles) < 7) {
            return;
        }

        $today = $c->today->min(CarbonImmutable::today());
        $location = StockLocation::query()->where('is_active', true)->orderBy('sort_key')->first()
            ?? app(SaveLocation::class)($c->user, $c->company, [
                'type' => 'WAREHOUSE', 'code' => 'VS', 'name' => 'Verkstaden',
                'address_line1' => (string) $c->company->getAttribute('address_line1'), 'postal_code' => (string) $c->company->getAttribute('postal_code'), 'city' => (string) $c->company->getAttribute('city'),
            ]);

        foreach (['BORD-180', 'BYRA-80'] as $number) {
            $articles[$number]->forceFill(['is_stock_item' => true, 'default_location_id' => $location->id])->save();
        }

        $receipt = app(SaveReceipt::class)($c->user, $c->company, ['reference' => 'Virke och beslag', 'lines' => array_map(fn (array $line): array => [
            'article_id' => $articles[$line[0]]->id, 'location_id' => $location->id, 'quantity' => $line[1], 'unit_cost' => $line[2],
        ], [['EK-27-M2', '30', '1 450,00'], ['BEN-EK70', '24', '350,00'], ['HAND-MS', '40', '45,00'], ['SKR-5X50', '600', '0,95'], ['LACK-MATT', '10', '240,00']])]);
        app(ReceiveStock::class)->all($c->user, $c->company, $receipt, $today->subDays(30));

        $save = app(SaveProductionOrder::class);
        $life = app(ProductionOrderLifecycle::class);
        $table = $articles['BORD-180']->id;

        $finished = $save->create($c->user, $c->company, ['article_id' => $table, 'quantity' => '2', 'planned_start_on' => $today->subDays(21)->toDateString(), 'due_on' => $today->subDays(14)->toDateString(), 'notes' => 'Lagerbord till utställningen.']);
        $life->release($c->user, $c->company, $finished);
        $life->start($c->user, $c->company, $finished, $today->subDays(20)->toDateString(), true);
        $life->finish($c->user, $c->company, $finished, ['date' => $today->subDays(15)->toDateString()]);

        $started = $save->create($c->user, $c->company, ['article_id' => $table, 'quantity' => '3', 'planned_start_on' => $today->subDays(3)->toDateString(), 'due_on' => $today->addDays(4)->toDateString()]);
        $life->release($c->user, $c->company, $started);
        $life->start($c->user, $c->company, $started, $today->subDays(2)->toDateString(), true);

        $customer = Customer::query()->where('name', 'Bostadsrättsföreningen Exemplet')->first() ?? Customer::query()->orderBy('id')->firstOrFail();
        $order = app(SaveSalesDocument::class)($c->user, $c->company, SalesDocument::ORDER, [
            'customer_id' => $customer->id,
            'document_date' => $today->subDays(5)->toDateString(),
            'your_reference' => 'Byråer till kontoret',
            'rows' => [['article' => 'BYRA-80', 'description' => 'Byrå 80, mässingsknoppar', 'quantity' => '2', 'price' => '11 900,00', 'vat_rate' => 2500]],
        ]);
        $key = (string) $order->rows->firstOrFail()->line_key;
        app(SaveRowStructure::class)->choose($c->user, $order, $key, $articles['BYRA-80']);
        $released = $save->create($c->user, $c->company, ['sales_document_id' => $order->id, 'line_key' => $key, 'planned_start_on' => $today->addDays(3)->toDateString(), 'due_on' => $today->addDays(12)->toDateString()]);
        $life->release($c->user, $c->company, $released);

        $save->create($c->user, $c->company, ['article_id' => $table, 'quantity' => '4', 'planned_start_on' => $today->addDays(14)->toDateString(), 'notes' => 'Inför vårmässan.']);
    }

    /** @return array{board: Article, leg: Article, handle: Article, screw: Article, lacquer: Article, drawer: Article, table: Article, chest: Article} */
    private function articles(DemoContext $c): array
    {
        $save = fn (array $input): Article => app(SaveArticle::class)($c->user, $c->company, ['vat_rate' => 2500, ...$input]);

        return [
            'board' => $save(['number' => 'EK-27-M2', 'name' => 'Ekskiva 27 mm', 'price' => '0', 'purchase_price' => '1 450,00', 'is_stock_item' => true, 'stock_unit' => 'm²', 'material_length' => '2440', 'material_width' => '1220', 'material_thickness' => '27']),
            'leg' => $save(['number' => 'BEN-EK70', 'name' => 'Bordsben ek 70×70', 'price' => '0', 'purchase_price' => '350,00', 'is_stock_item' => true, 'unit' => 'st']),
            'handle' => $save(['number' => 'HAND-MS', 'name' => 'Knopp mässing', 'price' => '0', 'purchase_price' => '45,00', 'is_stock_item' => true, 'unit' => 'st']),
            'screw' => $save(['number' => 'SKR-5X50', 'name' => 'Skruv 5×50', 'price' => '0', 'purchase_price' => '0,95', 'is_stock_item' => true, 'unit' => 'st']),
            'lacquer' => $save(['number' => 'LACK-MATT', 'name' => 'Möbellack matt', 'price' => '0', 'purchase_price' => '240,00', 'is_stock_item' => true, 'stock_unit' => 'l']),
            'drawer' => $save(['number' => 'LADA-80', 'name' => 'Låda till Byrå 80', 'price' => '0', 'unit' => 'st']),
            'table' => $save(['number' => 'BORD-180', 'name' => 'Ekbord 180', 'price' => '14 500,00', 'unit' => 'st']),
            'chest' => $save(['number' => 'BYRA-80', 'name' => 'Byrå 80', 'price' => '11 900,00', 'unit' => 'st']),
        ];
    }

    /**
     * An open quote for four tables and two chests; the table row's copy has
     * a wider top and longer assembly, for this customer only.
     *
     * @param  array<string, Article>  $a
     * @param  array<string, int>  $operations
     */
    private function quote(DemoContext $c, array $a, array $operations): void
    {
        $operation = fn (string $name): int => $operations[$name] ?? throw new LogicException("Operation {$name} was not seeded.");

        $customer = Customer::query()->where('name', 'Bostadsrättsföreningen Exemplet')->first() ?? Customer::query()->orderBy('id')->firstOrFail();

        $quote = app(SaveSalesDocument::class)($c->user, $c->company, SalesDocument::QUOTE, [
            'customer_id' => $customer->id,
            'document_date' => $c->today->toDateString(),
            'until_date' => $c->today->addDays(30)->toDateString(),
            'your_reference' => 'Möbler till samlingslokalen',
            'rows' => [
                ['article' => 'BORD-180', 'description' => 'Ekbord 180, bredare skiva', 'quantity' => '4', 'price' => '14 500,00', 'vat_rate' => 2500],
                ['article' => 'BYRA-80', 'description' => 'Byrå 80', 'quantity' => '2', 'price' => '11 900,00', 'vat_rate' => 2500],
                ['description' => 'Leverans och montering på plats', 'quantity' => '1', 'unit' => 'st', 'price' => '1 800,00', 'vat_rate' => 2500],
            ],
        ]);

        $tableKey = (string) $quote->rows->firstOrFail()->line_key;
        $chestKey = (string) $quote->rows->skip(1)->firstOrFail()->line_key;
        $copies = app(SaveRowStructure::class);
        $copies->choose($c->user, $quote, $tableKey, $a['table']);
        $copies->choose($c->user, $quote, $chestKey, $a['chest']);
        $copies->update($c->user, $quote, $tableKey, ['name' => 'Ekbord 180, bredare skiva', 'yield' => '1', 'lines' => [
            ['article_id' => $a['board']->id, 'quantity' => '1,98', 'loss' => '8'],
            ['article_id' => $a['leg']->id, 'quantity' => '4'],
            ['article_id' => $a['screw']->id, 'quantity' => '20'],
            ['article_id' => $a['lacquer']->id, 'quantity' => '4', 'unit' => 'dl', 'loss' => '10'],
            ['operation_id' => $operation('Kapning'), 'minutes' => '30'],
            ['operation_id' => $operation('Hyvling'), 'minutes' => '30'],
            ['operation_id' => $operation('Montering'), 'minutes' => '110'],
            ['operation_id' => $operation('Slipning'), 'minutes' => '50'],
            ['operation_id' => $operation('Lackering'), 'minutes' => '35'],
        ]]);
    }
}
