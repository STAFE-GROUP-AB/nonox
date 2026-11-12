<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Models\Article;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\Workshop\RecordTerminalTime;
use App\Modules\Manufacturing\Actions\Workshop\WaitingFlags;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopPeople;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * The workshop terminal and the post-calculation (ADR-071 §7) in the
 * joinery, for the manual and the landing page: the terminal switched on,
 * three made-up people and a paired tablet, nine finished production orders
 * over the last half year with the minutes their operations took (Kapning
 * drifting upwards, Montering coming down), three open orders with time
 * reported on the tablet over the last days, and two operations flagged as
 * waiting. Runs after ManufacturingExtras (file name order) and does
 * nothing without its operations. Made-up people, codes and amounts.
 */
final class WorkshopExtras extends DemoExtra
{
    /** @var array<string, string> name => code, all made up */
    private const array PEOPLE = ['Anna Andersson' => '2580', 'Emil Berg' => '1397', 'Sara Lind' => '4826'];

    /** Finished orders: article number, days ago it finished, quantity, minutes per piece for Kapning/Hyvling/Montering/Slipning/Lackering (table) or Kapning/Montering/Slipning/Lackering (chest). */
    private const array HISTORY = [
        ['BORD-180', 170, 2, [31, 25, 117, 45, 30]],
        ['BORD-180', 140, 1, [34, 26, 112, 44, 31]],
        ['BYRA-80', 120, 1, [41, 133, 61, 46]],
        ['BORD-180', 96, 2, [37, 25, 105, 46, 29]],
        ['BORD-180', 70, 1, [40, 27, 101, 45, 31]],
        ['BYRA-80', 52, 2, [47, 128, 60, 44]],
        ['BORD-180', 33, 2, [44, 26, 96, 47, 30]],
        ['BORD-180', 18, 1, [47, 25, 92, 45, 32]],
        ['BYRA-80', 9, 1, [52, 126, 61, 45]],
    ];

    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Verkstadsterminal', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            if (! Operation::query()->exists() || WorkshopPerson::query()->exists()) {
                return;
            }

            app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $c->company, $c->user, ['terminal_enabled' => true]);

            $people = [];

            foreach (self::PEOPLE as $name => $code) {
                $people[] = app(WorkshopPeople::class)->create($c->user, $c->company, ['name' => $name, 'code' => $code]);
            }

            [, $code] = app(WorkshopDevices::class)->register($c->user, $c->company, 'Snickeriet');
            [$device] = app(WorkshopDevices::class)->pair($code) ?? [null];
            app(WorkshopDevices::class)->register($c->user, $c->company, 'Lackeringen');

            $stock = $this->stock($c);
            $this->history($c, $stock);
            $this->open($c, $stock, $people, $device);
        }));
    }

    /** @return array{location: StockLocation} */
    private function stock(DemoContext $c): array
    {
        $location = StockLocation::query()->where('is_active', true)->orderBy('sort_key')->first()
            ?? app(SaveLocation::class)($c->user, $c->company, ['type' => 'WAREHOUSE', 'code' => 'VL', 'name' => 'Verkstadslager', 'address_line1' => 'Verkstadsvägen 1', 'postal_code' => '111 22', 'city' => 'Exempelstad']);

        // The chest's drawers are made in advance and kept in stock; the finished furniture is stock too, so orders can receive it.
        Article::query()->whereIn('number', ['BORD-180', 'BYRA-80', 'LADA-80'])->update(['is_stock_item' => true]);

        $lines = [];

        foreach ([['EK-27-M2', '60', '1450,00'], ['BEN-EK70', '120', '350,00'], ['HAND-MS', '80', '45,00'], ['SKR-5X50', '2000', '0,95'], ['LACK-MATT', '40', '240,00'], ['LADA-80', '40', '420,00']] as [$number, $quantity, $cost]) {
            $article = Article::query()->where('number', $number)->first();

            if ($article instanceof Article) {
                $lines[] = ['article_id' => $article->id, 'location_id' => $location->id, 'quantity' => $quantity, 'unit_cost' => $cost];
            }
        }

        $receipt = app(SaveReceipt::class)($c->user, $c->company, ['lines' => $lines]);
        app(ReceiveStock::class)->all($c->user, $c->company, $receipt, $c->today->subDays(200));

        return ['location' => $location];
    }

    /**
     * @param  array{location: StockLocation}  $stock
     */
    private function history(DemoContext $c, array $stock): void
    {
        $life = app(ProductionOrderLifecycle::class);

        foreach (self::HISTORY as [$number, $daysAgo, $quantity, $minutes]) {
            $article = Article::query()->where('number', $number)->firstOrFail();
            $order = app(SaveProductionOrder::class)->create($c->user, $c->company, ['article_id' => $article->id, 'quantity' => (string) $quantity, 'location_id' => $stock['location']->id]);
            $day = $c->today->subDays($daysAgo);
            $order = $life->release($c->user, $c->company, $order);
            $order = $life->start($c->user, $c->company, $order, $day->subDays(3)->toDateString());

            $entered = [];

            foreach ($order->operations()->orderBy('position')->get() as $index => $operation) {
                $entered[$operation->id] = ($minutes[$index] ?? $operation->minutes) * $quantity;
            }

            $life->finish($c->user, $c->company, $order, ['date' => $day->toDateString(), 'minutes' => $entered]);
        }
    }

    /**
     * Three open orders with time reported on the tablet over the last days
     * and two operations flagged as waiting.
     *
     * @param  array{location: StockLocation}  $stock
     * @param  list<WorkshopPerson>  $people
     */
    private function open(DemoContext $c, array $stock, array $people, mixed $device): void
    {
        $life = app(ProductionOrderLifecycle::class);
        $make = function (string $number, string $quantity, ?string $due) use ($c, $stock): ProductionOrder {
            $article = Article::query()->where('number', $number)->firstOrFail();

            return app(SaveProductionOrder::class)->create($c->user, $c->company, ['article_id' => $article->id, 'quantity' => $quantity, 'location_id' => $stock['location']->id, 'due_on' => $due]);
        };

        $tables = $life->start($c->user, $c->company, $life->release($c->user, $c->company, $make('BORD-180', '4', $c->today->addDays(9)->toDateString())), $c->today->subDays(4)->toDateString());
        $chests = $life->start($c->user, $c->company, $life->release($c->user, $c->company, $make('BYRA-80', '2', $c->today->addDays(14)->toDateString())), $c->today->subDays(2)->toDateString());
        $next = $life->release($c->user, $c->company, $make('BORD-180', '2', $c->today->addDays(21)->toDateString()));

        $steps = fn (ProductionOrder $order): array => $order->operations()->with('operation')->orderBy('position')->get()->keyBy(fn ($operation): string => $operation->operation->name ?? '')->all();
        $table = $steps($tables);
        $chest = $steps($chests);
        $record = app(RecordTerminalTime::class);
        [$anna, $emil, $sara] = $people;

        // [days ago, person, step, hours, minutes]
        $reports = [
            [4, $anna, $table['Kapning'], 2, 30],
            [4, $emil, $table['Kapning'], 1, 45],
            [3, $anna, $table['Hyvling'], 1, 20],
            [3, $emil, $table['Hyvling'], 1, 40],
            [2, $emil, $table['Montering'], 3, 0],
            [2, $sara, $chest['Kapning'], 1, 30],
            [1, $emil, $table['Montering'], 3, 15],
            [1, $sara, $chest['Kapning'], 0, 50],
            [1, $anna, $chest['Montering'], 2, 0],
            [0, $emil, $table['Montering'], 1, 30],
            [0, $anna, $chest['Montering'], 1, 45],
        ];

        foreach ($reports as [$daysAgo, $person, $step, $hours, $minutes]) {
            CarbonImmutable::setTestNow($c->today->subDays($daysAgo)->setTime(15, 30));

            try {
                $record($device, $person, $step->id, $hours, $minutes);
            } finally {
                CarbonImmutable::setTestNow();
            }
        }

        $flags = app(WaitingFlags::class);
        $flags->raise($device, $table['Lackering']->id, WaitingKind::Material, 'Möbellacken är slut, ny leverans på torsdag');
        $flags->raise($device, $steps($next)['Kapning']->id, WaitingKind::Work, 'Sågen är bokad till bordsordern denna vecka');
    }
}
