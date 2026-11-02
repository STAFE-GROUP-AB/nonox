<?php

declare(strict_types=1);

use App\Modules\Inventory\Livewire\ArticlesPage;
use App\Modules\Inventory\Livewire\CountsPage;
use App\Modules\Inventory\Livewire\LocationsPage;
use App\Modules\Inventory\Livewire\OverviewPage;
use App\Modules\Inventory\Livewire\PickListsPage;
use App\Modules\Inventory\Livewire\ReceiptsPage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockReceipt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Lager's pages (ADR-039): every save gives a Swedish toast, the phone
 * flows for receiving, picking and counting work, and the lists do the
 * same number of queries whatever their length.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner);
});

it('adds, changes and deactivates locations with toasts', function () {
    Livewire::test(LocationsPage::class)
        ->call('create', $this->places['zone']->id)
        ->assertSet('form.type', 'SHELF')
        ->set('form.code', '7')
        ->set('form.name', 'Hylla sju')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Lagerstället är tillagt.')
        ->assertSee('HL-A-7');

    $shelf = StockLocation::query()->where('full_code', 'HL-A-7')->sole();

    Livewire::test(LocationsPage::class)
        ->call('toggle', $shelf->id)
        ->assertDispatched('toast', message: 'Lagerstället HL-A-7 är inaktivt.');

    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '1', '80');
    Livewire::test(LocationsPage::class)
        ->call('toggle', $this->places['bin1']->id)
        ->assertDispatched('toast', type: 'error');
    expect($this->places['bin1']->fresh()->is_active)->toBeTrue();
});

it('creates a receipt and receives it on the page', function () {
    $component = Livewire::test(ReceiptsPage::class)
        ->call('create')
        ->set('form.lines.0.article_id', (string) $this->article->id)
        ->set('form.lines.0.quantity', '6')
        ->set('form.lines.0.location_id', (string) $this->places['bin1']->id)
        ->call('save')
        ->assertDispatched('toast', message: 'Inleveransen är skapad.');

    $receipt = StockReceipt::query()->sole();
    $component->assertSet('receiving', $receipt->id)
        ->call('receiveGoods')
        ->assertDispatched('toast', message: 'Varorna är mottagna och finns i lager.');

    expect($receipt->fresh()->status)->toBe(StockReceipt::RECEIVED)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(6000);
});

it('picks a manual list on the page, prints it and completes it', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '80');

    $component = Livewire::test(PickListsPage::class)
        ->call('create', 'manual')
        ->set('rows.0.article_id', (string) $this->article->id)
        ->set('rows.0.quantity', '3')
        ->call('make')
        ->assertDispatched('toast', message: 'Plocklistan är skapad.')
        ->assertSee('HL-A-01');

    $list = PickList::query()->sole();
    $line = $list->lines()->sole();

    $this->get(route('inventory.pickLists.pdf', $list->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $component->set("picked.{$line->id}", '2')
        ->assertDispatched('toast', message: 'Plockat antal är sparat.')
        ->call('complete')
        ->assertDispatched('toast', message: 'Plocklistan är klar och varorna är dragna från lagret.');

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(3000);
});

it('counts on the page and completes the count', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '80');

    $component = Livewire::test(CountsPage::class)
        ->call('start')
        ->call('begin')
        ->assertDispatched('toast', message: 'Inventeringen är startad. Räkna och skriv in antalet per rad.');

    $count = StockCount::query()->sole();
    $line = $count->lines()->sole();

    $component->set("lines.{$line->id}.counted", '4')
        ->set("lines.{$line->id}.reason", 'DAMAGED')
        ->call('complete')
        ->assertDispatched('toast', message: 'Inventeringen är klar och skillnaderna är införda i lagret.');

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(4000);
});

it('moves goods from the article list', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '80');

    Livewire::test(ArticlesPage::class)
        ->assertSee('HL-A-01')
        ->call('move', $this->article->id)
        ->set('form.to_location_id', (string) $this->places['bin2']->id)
        ->set('form.quantity', '2')
        ->call('saveMovement')
        ->assertDispatched('toast', message: 'Varorna är flyttade.');

    expect(Inventory::level($this->article, $this->places['bin2']))->toBe(2000);
});

it('lists articles and the overview with the same number of queries for 2 and 12 articles', function () {
    $queries = function (string $component): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($component);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $stock = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $article = Inventory::article($this->company, $this->owner, "Q-{$index}", "Artikel {$index}", ['reorder_point' => '100']);
            Inventory::receive($this->company, $this->owner, $article, $this->places['bin1'], '1', '10');
            Inventory::receive($this->company, $this->owner, $article, $this->places['bin2'], '1', '10');
        }
    };

    $stock(1, 1);
    // A first render warms what is cached per request (modules, settings).
    $queries(ArticlesPage::class);
    $queries(OverviewPage::class);
    $small = ['articles' => $queries(ArticlesPage::class), 'overview' => $queries(OverviewPage::class)];
    $stock(2, 11);
    $large = ['articles' => $queries(ArticlesPage::class), 'overview' => $queries(OverviewPage::class)];

    expect($large)->toBe($small);
});
