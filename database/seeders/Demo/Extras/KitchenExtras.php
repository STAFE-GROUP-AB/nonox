<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\Supplier;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Kitchen\Actions\RegisterStaffMeal;
use App\Modules\Kitchen\Actions\RegisterWaste;
use App\Modules\Kitchen\Actions\SaveRecipe;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Support\TheoreticalConsumption;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Support\Str;

/**
 * Kök (ADR-073) in the restaurant and the café, for the manual's kitchen
 * pages: ingredients received into the kitchen's store, a sub-recipe and
 * the dishes that use it, the menu with prices, two weeks of the till's
 * sales of the dishes, waste, staff meals, and two stock counts two weeks
 * apart whose difference is a little more than the recipes explain.
 *
 * The till's sales rows sit on a till connection that is disconnected and
 * pushes nothing: the till module stays off, so nothing is ever sent to a
 * till. Every price and quantity is invented. Never in a visitor's demo.
 *
 * @phpstan-type Ingredient array{0: string, 1: string, 2: string, 3: string, 4: string}
 * @phpstan-type Recipe array{number: string, name: string, price?: string, yield: string, unit: string, rows: list<array{0: string, 1: string, 2?: string, 3?: string}>, per_day?: array{0: int, 1: int}}
 */
final class KitchenExtras extends DemoExtra
{
    /** number, name, unit, unit cost (kr), quantity received each time */
    private const array INGREDIENTS = [
        'bokforing-for-restauranger' => [
            ['R-101', 'Smör', 'kg', '110,00', '12'],
            ['R-102', 'Vitlök', 'kg', '50,00', '3'],
            ['R-103', 'Pasta, torr', 'kg', '32,00', '25'],
            ['R-104', 'Champinjoner', 'kg', '60,00', '22'],
            ['R-105', 'Vispgrädde', 'l', '45,00', '20'],
            ['R-106', 'Parmesan', 'kg', '280,00', '5'],
            ['R-107', 'Gul lök', 'kg', '15,00', '10'],
            ['R-108', 'Laxfilé', 'kg', '215,00', '18'],
            ['R-109', 'Potatis', 'kg', '9,00', '45'],
            ['R-110', 'Dill', 'kg', '140,00', '2'],
            ['R-111', 'Citron', 'kg', '35,00', '4'],
        ],
        'bokforing-for-cafeer' => [
            ['R-201', 'Vetemjöl', 'kg', '9,50', '40'],
            ['R-202', 'Smör', 'kg', '110,00', '12'],
            ['R-203', 'Strösocker', 'kg', '14,00', '15'],
            ['R-204', 'Kanel, malen', 'kg', '180,00', '1'],
            ['R-205', 'Mjölk', 'l', '13,00', '20'],
            ['R-206', 'Jäst', 'kg', '45,00', '2'],
            ['R-207', 'Surdegsbröd', 'st', '28,00', '40'],
            ['R-208', 'Hushållsost', 'kg', '95,00', '6'],
            ['R-209', 'Gurka', 'kg', '30,00', '5'],
        ],
    ];

    /** The sub-recipe first, then the dishes; a dish's rows use ingredient numbers: number, quantity, unit, loss %. */
    private const array RECIPES = [
        'bokforing-for-restauranger' => [
            ['number' => 'G-101', 'name' => 'Vitlökssmör', 'yield' => '1', 'unit' => BillOfMaterials::UNIT, 'rows' => [['R-101', '0,9'], ['R-102', '0,15', 'kg', '20']]],
            ['number' => 'M-101', 'name' => 'Pasta med svamp', 'price' => '168,75', 'yield' => '10', 'unit' => BillOfMaterials::PORTIONS, 'per_day' => [9, 16], 'rows' => [
                ['R-103', '1'], ['R-104', '1,2', 'kg', '10'], ['R-105', '0,8'], ['R-106', '250', 'g', '4'], ['R-107', '0,4', 'kg', '12'], ['G-101', '0,1'],
            ]],
            ['number' => 'M-102', 'name' => 'Lax med dillpotatis', 'price' => '214,29', 'yield' => '10', 'unit' => BillOfMaterials::PORTIONS, 'per_day' => [7, 13], 'rows' => [
                ['R-108', '1,6'], ['R-109', '2,5', 'kg', '15'], ['R-110', '60', 'g'], ['R-111', '0,3'], ['R-105', '0,5'], ['G-101', '0,05'],
            ]],
        ],
        'bokforing-for-cafeer' => [
            ['number' => 'G-201', 'name' => 'Kanelfyllning', 'yield' => '1', 'unit' => BillOfMaterials::UNIT, 'rows' => [['R-202', '0,5'], ['R-203', '0,4'], ['R-204', '0,1']]],
            ['number' => 'M-201', 'name' => 'Kanelbulle', 'price' => '28,57', 'yield' => '24', 'unit' => BillOfMaterials::PORTIONS, 'per_day' => [30, 55], 'rows' => [
                ['R-201', '1'], ['R-205', '0,3'], ['R-202', '0,15'], ['R-203', '0,1'], ['R-206', '0,05'], ['G-201', '0,4'],
            ]],
            ['number' => 'M-202', 'name' => 'Ostsmörgås', 'price' => '62,50', 'yield' => '12', 'unit' => BillOfMaterials::PORTIONS, 'per_day' => [12, 24], 'rows' => [
                ['R-207', '1'], ['R-208', '0,6', 'kg', '5'], ['R-202', '0,1'], ['R-209', '0,4', 'kg', '10'],
            ]],
        ],
    ];

    /** Sold without a recipe: the till's daily special, listed under "Sålt utan recept". */
    private const array SPECIAL = [
        'bokforing-for-restauranger' => ['M-190', 'Dagens soppa', '111,61'],
        'bokforing-for-cafeer' => ['M-290', 'Dagens kaka', '40,18'],
    ];

    /** @var array<string, Article> */
    private array $articles = [];

    public function industries(): array
    {
        return ['bokforing-for-restauranger', 'bokforing-for-cafeer'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        $this->articles = [];
        $previous = Carbon::getTestNow();

        try {
            $this->atomically($c, 'Kök', fn () => CurrentCompany::run($c->company, fn () => $this->build($c)));
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    private function build(DemoContext $c): void
    {
        $modules = app(ModuleManager::class);
        $today = $c->today;
        $modules->enable(InventoryModule::KEY, $c->company, $c->user);
        $modules->enable(KitchenModule::KEY, $c->company, $c->user);

        $store = app(SaveLocation::class)($c->user, $c->company, [
            'type' => 'WAREHOUSE', 'code' => 'KOK', 'name' => 'Köket, kyl och torrförråd',
            'address_line1' => (string) $c->company->getAttribute('address_line1'), 'postal_code' => (string) $c->company->getAttribute('postal_code'), 'city' => (string) $c->company->getAttribute('city'),
        ]);
        $modules->updateSettings(KitchenModule::KEY, $c->company, $c->user, [
            ...KitchenSettings::DEFAULTS,
            'location_id' => $store->id,
            'target_food_cost_bp' => 3000,
            'average_meal_cost_ore' => 4_500,
        ]);

        $this->at($today->subDays(30), 8, function () use ($c): void {
            $this->ingredients($c);
            $this->recipes($c);
        });

        $supplier = Supplier::query()->orderBy('id')->first();
        $this->at($today->subDays(20), 7, fn () => $this->receive($c, $store, $supplier, 'Leverans vecka '.$today->subDays(20)->isoWeek()));

        $this->at($today->subDays(14), 6, fn (): StockCount => $this->count($c, $store, $today->subDays(14), 'Inventering före perioden'));
        $this->sales($c, $store, $today->subDays(14), $today->subDay());
        $this->at($today->subDays(8), 7, fn () => $this->receive($c, $store, $supplier, 'Leverans vecka '.$today->subDays(8)->isoWeek()));
        $this->waste($c, $store);
        $this->meals($c, $store);

        $theory = TheoreticalConsumption::between($c->company, $today->subDays(14), $today->subDay());
        $this->at($today->subDay(), 22, fn (): StockCount => $this->count($c, $store, $today->subDay(), 'Inventering efter perioden', $theory->ingredients));
    }

    private function ingredients(DemoContext $c): void
    {
        foreach (self::INGREDIENTS[$c->slug] as [$number, $name, $unit, $cost]) {
            $this->articles[$number] = app(SaveArticle::class)($c->user, $c->company, [
                'number' => $number, 'name' => $name, 'unit' => $unit, 'price' => '0,00', 'vat_rate' => 1200,
                'purchase_price' => $cost, 'is_stock_item' => true,
            ]);
        }
    }

    private function recipes(DemoContext $c): void
    {
        $special = self::SPECIAL[$c->slug];
        $this->articles[$special[0]] = app(SaveArticle::class)($c->user, $c->company, ['number' => $special[0], 'name' => $special[1], 'unit' => 'st', 'price' => $special[2], 'vat_rate' => 1200]);

        foreach (self::RECIPES[$c->slug] as $recipe) {
            $dish = isset($recipe['price']);
            $this->articles[$recipe['number']] = app(SaveArticle::class)($c->user, $c->company, $dish
                ? ['number' => $recipe['number'], 'name' => $recipe['name'], 'unit' => 'st', 'price' => $recipe['price'], 'vat_rate' => 1200]
                : ['number' => $recipe['number'], 'name' => $recipe['name'], 'unit' => 'kg', 'price' => '0,00', 'vat_rate' => 1200, 'is_stock_item' => true]);

            app(SaveRecipe::class)($c->user, $c->company, $this->articles[$recipe['number']], [
                'name' => $recipe['name'],
                'valid_from' => $c->today->subDays(30)->toDateString(),
                'yield' => $recipe['yield'],
                'yield_unit' => $recipe['unit'],
                'rows' => array_map(fn (array $row): array => array_filter([
                    'article_id' => $this->articles[$row[0]]->id,
                    'quantity' => $row[1],
                    'unit' => $row[2] ?? null,
                    'loss' => $row[3] ?? null,
                ], fn (mixed $value): bool => $value !== null), $recipe['rows']),
            ]);
        }
    }

    private function receive(DemoContext $c, StockLocation $store, ?Supplier $supplier, string $reference): void
    {
        $lines = [];

        foreach (self::INGREDIENTS[$c->slug] as [$number, , , $cost, $quantity]) {
            $lines[] = ['article_id' => $this->articles[$number]->id, 'location_id' => $store->id, 'quantity' => $quantity, 'unit_cost' => $cost];
        }

        $receipt = app(SaveReceipt::class)($c->user, $c->company, ['supplier_id' => $supplier?->id, 'reference' => $reference, 'lines' => $lines]);
        app(ReceiveStock::class)->all($c->user, $c->company, $receipt, CarbonImmutable::now());
    }

    /**
     * A completed count of the store. Without $used everything is as the
     * system says; with it, each ingredient is what the system says less
     * what the till's sales used by the recipes, and a few lose a little
     * more: the variance the report shows.
     *
     * @param  array<int, int>|null  $used  article id => thousandths
     */
    private function count(DemoContext $c, StockLocation $store, CarbonImmutable $day, string $note, ?array $used = null): StockCount
    {
        $counts = app(StockCounts::class);
        $count = $counts->start($c->user, $c->company, $store->id, $day, $note);
        $extra = ['R-104' => 4, 'R-108' => 3, 'R-110' => 9, 'R-202' => 5, 'R-208' => 4];

        foreach (StockCountLine::query()->where('stock_count_id', $count->id)->with(['article:id,number', 'stockCount'])->get() as $line) {
            $milli = (int) $line->system_milli;
            $number = (string) $line->article?->number;

            if ($used !== null) {
                $milli -= $used[$line->article_id] ?? 0;
                $milli -= intdiv($milli * ($extra[$number] ?? 0), 100);
            }

            // What the kitchen used is not registered anywhere but in the till's sales: the count is where it shows.
            $counts->record($c->user, $c->company, $line, self::quantity(max(0, $milli)), max(0, $milli) !== (int) $line->system_milli ? 'NOT_REGISTERED' : null);
        }

        return $counts->complete($c->user, $c->company, $count);
    }

    /** The till's sales of the dishes, one sale per day, on a till that pushes nothing. */
    private function sales(DemoContext $c, StockLocation $store, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $till = PosConnection::query()->create([
            'provider' => Provider::Onslip,
            'status' => ConnectionStatus::Disconnected,
            'external_org_id' => 'demo-'.Str::lower(Str::random(12)),
            'display_name' => 'Kassan (demo)',
            'credentials' => [],
            'webhook_token' => Str::random(48),
            'settings' => ['sync_direction' => PosConnection::ONE_WAY, 'push_products' => false, 'push_stock' => false],
            'location_id' => $store->id,
            'connected_by' => $c->user->id,
            'connected_at' => $from->subDays(30),
        ]);

        mt_srand((int) crc32($c->company->name.'kok'));
        $dishes = array_values(array_filter(self::RECIPES[$c->slug], fn (array $recipe): bool => isset($recipe['per_day'])));
        $special = $this->articles[self::SPECIAL[$c->slug][0]];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $rows = [];

            foreach ($dishes as $recipe) {
                $article = $this->articles[$recipe['number']];
                $portions = mt_rand($recipe['per_day'][0], $recipe['per_day'][1]);
                $rows[] = [$article, $portions * 1000, $portions * $article->price_ore->ore];
            }

            if ($day->isWeekday()) {
                $portions = mt_rand(4, 9);
                $rows[] = [$special, $portions * 1000, $portions * $special->price_ore->ore];
            }

            $sale = PosSale::query()->create([
                'pos_connection_id' => $till->id,
                'provider' => $till->provider,
                'external_id' => 'demo-'.$day->toDateString(),
                'source' => 'API',
                'is_refund' => false,
                'sold_at' => $day->setTime(14, 0),
                'business_date' => $day->toDateString(),
                'currency' => 'SEK',
                'total_ore' => 0,
                'vat_ore' => 0,
                'vat_totals' => [],
                'payments' => [],
                'payload' => [],
                'payload_hash' => hash('sha256', $c->company->id.'|'.$day->toDateString()),
            ]);

            foreach ($rows as $index => [$article, $milli, $amount]) {
                PosSaleRow::query()->create([
                    'pos_sale_id' => $sale->id,
                    'line_no' => $index + 1,
                    'name' => $article->name,
                    'quantity_milli' => $milli,
                    'amount_ore' => $amount,
                    'vat_rate' => $article->vat_rate,
                    'status' => PosSaleRow::SKIPPED,
                    'article_id' => $article->id,
                ]);
            }
        }
    }

    private function waste(DemoContext $c, StockLocation $store): void
    {
        $restaurant = $c->slug === 'bokforing-for-restauranger';
        $entries = $restaurant ? [
            [12, WasteEntry::INGREDIENT, 'R-104', '1,5', 'UTGANGET'],
            [10, WasteEntry::INGREDIENT, 'R-105', '2', 'FORDARVAT', ['note' => 'Kylen stod öppen över natten.']],
            [7, WasteEntry::DISH, 'M-102', '4', 'OVERPRODUKTION'],
            [5, WasteEntry::INGREDIENT, 'R-110', '0,2', 'BEREDNING'],
            [3, WasteEntry::DISH, 'M-101', '6', 'SKANKT', ['recipient' => 'Matbanken Exempel']],
            [1, WasteEntry::INGREDIENT, 'R-109', '3', 'FEL'],
        ] : [
            [11, WasteEntry::DISH, 'M-201', '18', 'OVERPRODUKTION'],
            [8, WasteEntry::INGREDIENT, 'R-205', '3', 'UTGANGET'],
            [6, WasteEntry::DISH, 'M-202', '5', 'SKANKT', ['recipient' => 'Härbärget Exempel']],
            [2, WasteEntry::INGREDIENT, 'R-209', '0,8', 'FORDARVAT'],
        ];

        foreach ($entries as $entry) {
            [$daysAgo, $kind, $number, $quantity, $reason] = $entry;
            $this->at($c->today->subDays($daysAgo), 21, fn () => app(RegisterWaste::class)($c->user, $c->company, [
                'kind' => $kind, 'article_id' => $this->articles[$number]->id, 'quantity' => $quantity,
                'location_id' => $store->id, 'reason' => $reason, 'date' => $c->today->subDays($daysAgo)->toDateString(),
                ...($entry[5] ?? []),
            ]));
        }
    }

    /** Lunches for the staff, per person: payroll is on, so the meal benefits go to the right employee. */
    private function meals(DemoContext $c, StockLocation $store): void
    {
        $restaurant = $c->slug === 'bokforing-for-restauranger';
        $dish = $this->articles[$restaurant ? 'M-101' : 'M-202'];
        $people = Employee::query()->whereIn('first_name', $restaurant ? ['Sara', 'Ali', 'Lina', 'Johan'] : ['Ida', 'Leo', 'Hugo'])->orderBy('id')->get();

        for ($daysAgo = 12; $daysAgo >= 1; $daysAgo--) {
            $day = $c->today->subDays($daysAgo);

            if (! $day->isWeekday()) {
                continue;
            }

            $this->at($day, 15, function () use ($c, $store, $dish, $day, $people): void {
                foreach ($people as $index => $person) {
                    if (($day->day + $index) % 4 === 0) {
                        continue;
                    }

                    app(RegisterStaffMeal::class)($c->user, $c->company, [
                        'date' => $day->toDateString(), 'meal' => 'LUNCH', 'article_id' => $dish->id, 'quantity' => '1',
                        'reason' => 'PERSONALMAT', 'employee_id' => $person->id, 'paid_per_meal' => '30', 'location_id' => $store->id,
                    ]);
                }
            });
        }
    }

    /**
     * Runs $step as if it were $hour o'clock on $day, so the stock
     * movements and the counts get the times they would have had.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(CarbonImmutable $day, int $hour, callable $step): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($day->setTime($hour, 0));

        try {
            return $step();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /** Thousandths as a quantity the way a person types it: 4,800. */
    private static function quantity(int $milli): string
    {
        return sprintf('%d,%03d', intdiv($milli, 1000), $milli % 1000);
    }
}
