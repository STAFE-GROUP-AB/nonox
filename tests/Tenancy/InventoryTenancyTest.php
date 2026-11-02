<?php

declare(strict_types=1);

use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Livewire\ArticlesPage;
use App\Modules\Inventory\Livewire\LocationsPage;
use App\Modules\Inventory\Livewire\PickListsPage;
use App\Modules\Inventory\Livewire\ReportsPage;
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
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Connect;
use Tests\Support\Inventory;

/*
 * AGENTS.md §13.2 for Lager: company A never sees B's locations, articles in
 * stock, movements, levels, receipts, pick lists, counts, valuations or
 * images, through Eloquent, the pages, the PDF, the API or webhooks; B's ids
 * answer 404.
 */

beforeEach(function () {
    Mail::fake();
    Http::fake();
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);
        $article = Inventory::article($company, $owner, "{$name}-1", "{$name}vara");
        Inventory::receive($company, $owner, $article, $places['bin1'], '3', '50');
        $pick = app(PickLists::class)->manual($owner, $company, [['article_id' => $article->id, 'quantity' => '1']]);
        $count = app(StockCounts::class)->start($owner, $company, null, CarbonImmutable::parse('2026-06-01'));
        ArticleImage::create(['article_id' => $article->id, 'path' => "bilder/{$name}.webp", 'position' => 0, 'alt' => "{$name}bild"]);
        StockValuation::create(['valuation_date' => '2026-12-31', 'method' => 'FIFO', 'cost_value_ore' => 1, 'value_ore' => 1, 'stock_account' => 1460, 'change_account' => 4960, 'lines' => []]);
        $this->{$side} = compact('owner', 'company', 'places', 'article', 'pick', 'count');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every Lager model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([
    StockLocation::class, StockMovement::class, StockLevel::class, StockReceipt::class, StockReceiptLine::class,
    PickList::class, PickListLine::class, StockCount::class, StockCountLine::class, StockValuation::class, ArticleImage::class,
]);

it('answers 404 for the other company\'s records', function () {
    expect(StockLocation::query()->find($this->b['places']['bin1']->id))->toBeNull()
        ->and(PickList::query()->find($this->b['pick']->id))->toBeNull();

    $this->get(route('inventory.pickLists.pdf', $this->b['pick']->id))->assertNotFound();
    $this->get(route('inventory.pickLists.pdf', $this->a['pick']->id))->assertOk();

    Livewire::test(PickListsPage::class)->call('cancelList', $this->b['pick']->id)->assertNotFound();
    Livewire::test(ArticlesPage::class)->call('move', $this->b['article']->id)->assertNotFound();

    CurrentCompany::set($this->b['company']);
    expect($this->b['pick']->fresh()->status)->toBe(PickList::OPEN);
});

it('shows only the own company on the pages and in the valuation', function () {
    Livewire::test(ArticlesPage::class)->assertSee('Alfavara')->assertDontSee('Betavara');
    Livewire::test(LocationsPage::class)->assertSee('Huvudlager');
    Livewire::test(ReportsPage::class)->set('report', 'rorelser')->set('from', '2026-01-01')->assertSee('Alfavara')->assertDontSee('Betavara');

    expect(array_column(StockValuationReport::at($this->a['company'], CarbonImmutable::parse('2026-12-31'))->lines, 'name'))->toBe(['Alfavara']);
});

it('gives the API only the credential\'s own stock and refuses the other company\'s ids', function () {
    $key = Connect::apiKey($this->a['owner'], $this->a['company'], ['inventory:view', 'inventory:manage']);

    foreach (['/api/v1/inventory/articles', '/api/v1/inventory/locations', '/api/v1/inventory/stock-levels'] as $uri) {
        expect($this->withToken($key)->getJson($uri)->assertOk()->getContent())->not->toContain('Beta');
    }

    $this->withToken($key)->getJson('/api/v1/inventory/articles?number=Beta-1')->assertOk()->assertJsonCount(0, 'data');

    $this->withToken($key)->withHeader('Idempotency-Key', 'lager-tenancy-1')->postJson('/api/v1/inventory/receipts', [
        'lines' => [['article_id' => $this->b['article']->id, 'location_id' => $this->a['places']['bin1']->id, 'quantity' => '1']],
    ])->assertUnprocessable();

    $this->withToken($key)->withHeader('Idempotency-Key', 'lager-tenancy-2')->postJson('/api/v1/inventory/receipts', [
        'receive' => true,
        'lines' => [['article_id' => $this->a['article']->id, 'location_id' => $this->b['places']['bin1']->id, 'quantity' => '1']],
    ])->assertUnprocessable();

    CurrentCompany::set($this->b['company']);
    expect(Inventory::level($this->b['article'], $this->b['places']['bin1']))->toBe(3000);
});

it('sends stock.changed only to the company\'s own webhooks', function () {
    Connect::webhook($this->a['owner'], $this->a['company'], ['stock.changed'], 'https://hooks.example.com/a');
    CurrentCompany::set($this->b['company']);
    Connect::webhook($this->b['owner'], $this->b['company'], ['stock.changed'], 'https://hooks.example.com/b');

    CurrentCompany::set($this->a['company']);
    Inventory::receive($this->a['company'], $this->a['owner'], $this->a['article'], $this->a['places']['bin2'], '2', '50');

    $deliveries = CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('event_type', 'stock.changed')->get());
    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->company_id)->toBe($this->a['company']->id);

    $body = json_decode($deliveries->first()->body, true);
    expect($body['data']['movements'][0])->toMatchArray(['article_id' => $this->a['article']->id, 'quantity' => '2', 'balance' => '2'])
        ->and($deliveries->first()->body)->not->toContain('unit_cost');
});
