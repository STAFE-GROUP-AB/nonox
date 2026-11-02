<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\ScanOperation;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Skanna (ADR-040): the phone page, its reference data without prices, and
 * the operations endpoint, which runs each UUID once through the Lager
 * actions, checks the permission per operation, and never reaches another
 * company's records.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner, 'V-1', 'Kaffekopp', ['gtin' => '7350000000016', 'default_location_id' => null]);
    $this->article->update(['default_location_id' => $this->places['bin1']->id]);
});

function scan(array $operations, ?int $companyId = null): TestResponse
{
    return test()->postJson(route('inventory.scanner.operations'), [
        'company_id' => $companyId ?? test()->company->id,
        'operations' => $operations,
    ]);
}

function operation(string $kind, array $payload, ?string $uuid = null): array
{
    return ['uuid' => $uuid ?? (string) Str::uuid(), 'kind' => $kind, 'payload' => $payload];
}

it('shows the scanning page with the camera allowed and the desktop QR code', function () {
    $this->get('/lager/skanna')
        ->assertOk()
        ->assertSee('Öppna Skanna på mobilen')
        ->assertSee('scanner-config', false)
        ->assertSee('<svg', false)
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
});

it('serves a service worker for the scanning scope without any data', function () {
    $response = $this->get('/lager/skanna/serviceworker')->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/lager/skanna');

    expect($response->getContent())->toContain('const SHELL = "/lager/skanna"')->toContain('nonox-skanna-')->not->toContain('Kaffekopp');
});

it('adds the scanning shortcut to the app manifest and the Lager menu', function () {
    $manifest = json_decode((string) file_get_contents(public_path('app.webmanifest')), true);

    expect(collect($manifest['shortcuts'])->firstWhere('url', '/lager/skanna')['name'])->toBe('Skanna lager');

    $this->get('/lager')->assertOk()->assertSee(route('inventory.scanner'));
});

it('sends the reference data without any price, also to Lagerpersonal', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '3', '80');
    app(SaveReceipt::class)($this->owner, $this->company, ['lines' => [['article_id' => $this->article->id, 'quantity' => '5']]]);

    foreach ([$this->owner, Bookkeeping::member($this->company, 'stock_clerk')] as $user) {
        $this->actingAs($user);
        $json = $this->getJson('/lager/skanna/data')->assertOk()
            ->assertJsonPath('articles.0.gtin', '7350000000016')
            ->assertJsonPath('articles.0.default_location_id', $this->places['bin1']->id)
            ->assertJsonPath('levels.0', [$this->article->id, $this->places['bin1']->id, 3000])
            ->assertJsonPath('receipts.0.lines.0.ordered', 5000)
            ->assertJsonPath('can.count', true)
            ->getContent();

        expect($json)->not->toContain('price')->not->toContain('cost')->not->toContain('8000')->not->toContain('19900');
    }
});

it('loads the reference data with the same number of queries for one or many articles', function () {
    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/lager/skanna/data')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $one = $measure();

    foreach (range(2, 8) as $number) {
        $article = Inventory::article($this->company, $this->owner, "V-{$number}", "Vara {$number}");
        Inventory::receive($this->company, $this->owner, $article, $this->places['bin2'], '2', '10');
    }

    app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->article->id, 'quantity' => '1']]);

    expect($measure())->toBe($one);
});

it('receives two units without a receipt and answers a resend with the first answer', function () {
    $receive = operation('receive', ['receipt_id' => null, 'lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '2']]]);

    scan([$receive])->assertOk()
        ->assertJsonPath('results.0.status', 'OK')
        ->assertJsonPath('results.0.uuid', $receive['uuid']);

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(2000);
    expect(StockReceipt::query()->sole()->status)->toBe(StockReceipt::RECEIVED);

    scan([$receive])->assertOk()
        ->assertJsonPath('results.0.status', 'OK')
        ->assertJsonPath('results.0.replayed', true);

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(2000)
        ->and(StockReceipt::query()->count())->toBe(1)
        ->and(ScanOperation::query()->count())->toBe(1);
});

it('receives part of a receipt and refuses an article that is not on it', function () {
    $other = Inventory::article($this->company, $this->owner, 'V-2', 'Tefat');
    $receipt = app(SaveReceipt::class)($this->owner, $this->company, ['lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '5']]]);

    scan([operation('receive', ['receipt_id' => $receipt->id, 'lines' => [
        ['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '2'],
        ['article_id' => $this->article->id, 'location_id' => $this->places['bin2']->id, 'quantity' => '1'],
    ]])])->assertJsonPath('results.0.status', 'OK');

    expect($receipt->fresh()->status)->toBe(StockReceipt::PARTIAL)
        ->and($receipt->lines()->sole()->received_milli)->toBe(3000)
        ->and(Inventory::level($this->article, $this->places['bin2']))->toBe(1000);

    scan([operation('receive', ['receipt_id' => $receipt->id, 'lines' => [['article_id' => $other->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '1']]])])
        ->assertJsonPath('results.0.status', 'REJECTED')
        ->assertJsonPath('results.0.message', "Artikeln finns inte på inleverans {$receipt->number}.");
});

it('completes a pick list with a short pick that has a reason, and refuses one without', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '80');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->article->id, 'quantity' => '3']]);
    $line = $list->lines()->sole();

    scan([operation('pick', ['pick_list_id' => $list->id, 'lines' => [['line_id' => $line->id, 'picked' => '2', 'reason' => null]]])])
        ->assertJsonPath('results.0.status', 'REJECTED')
        ->assertJsonPath('results.0.message', 'Ange varför det fattas på en rad.');
    expect($list->fresh()->status)->toBe(PickList::OPEN);

    scan([operation('pick', ['pick_list_id' => $list->id, 'lines' => [['line_id' => $line->id, 'picked' => '2', 'reason' => 'MISSING']]])])
        ->assertJsonPath('results.0.status', 'OK');

    expect($list->fresh()->status)->toBe(PickList::COMPLETED)
        ->and(PickListLine::query()->find($line->id)->short_reason)->toBe('MISSING')
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(3000);
});

it('moves stock between locations and refuses to go below zero', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '2', '80');

    scan([operation('move', ['article_id' => $this->article->id, 'from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '1.5'])])
        ->assertJsonPath('results.0.status', 'OK');

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(500)
        ->and(Inventory::level($this->article, $this->places['bin2']))->toBe(1500);

    scan([operation('move', ['article_id' => $this->article->id, 'from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '3'])])
        ->assertJsonPath('results.0.status', 'REJECTED')
        ->assertJsonPath('results.0.message', 'Det finns bara 0,5 st av V-1 på HL-A-01.');

    expect(Inventory::level($this->article, $this->places['bin2']))->toBe(1500);
});

it('starts a stocktake, counts by scanning and completes it with a reason', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '4', '80');

    $start = scan([operation('count_start', ['location_id' => $this->places['zone']->id])])
        ->assertJsonPath('results.0.status', 'OK')
        ->assertJsonPath('results.0.data.count.lines.0.system', 4000);
    $countId = $start->json('results.0.data.stock_count_id');

    scan([operation('count', ['stock_count_id' => $countId, 'complete' => false, 'lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'counted' => '3']]])])
        ->assertJsonPath('results.0.status', 'OK');

    scan([operation('count', ['stock_count_id' => $countId, 'complete' => true, 'lines' => []])])
        ->assertJsonPath('results.0.status', 'REJECTED')
        ->assertJsonPath('results.0.message', 'Ange en orsak till skillnaden för V-1.');

    scan([operation('count', ['stock_count_id' => $countId, 'complete' => true, 'lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'counted' => '3', 'reason' => 'THEFT']]])])
        ->assertJsonPath('results.0.status', 'OK')
        ->assertJsonPath('results.0.data.status', StockCount::COMPLETED);

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(3000);
});

it('lets Lagerpersonal handle goods but not a reader, and asks for count rights per operation', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'stock_clerk'));
    scan([operation('receive', ['receipt_id' => null, 'lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '1']]])])
        ->assertJsonPath('results.0.status', 'OK');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);
    $this->get('/lager/skanna')->assertOk();
    $this->getJson('/lager/skanna/data')->assertOk()->assertJsonPath('can.count', false);
    scan([operation('receive', ['receipt_id' => null, 'lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '1']]])])
        ->assertForbidden();

    $this->actingAs(Bookkeeping::member($this->company, 'approver'));
    $this->get('/lager/skanna')->assertForbidden();
    $this->getJson('/lager/skanna/data')->assertForbidden();

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(1000);
});

it('never sends a queue into another company than it was made in', function () {
    scan([operation('receive', ['receipt_id' => null, 'lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '1']]])], $this->company->id + 1000)
        ->assertStatus(409)
        ->assertJsonPath('error', 'company_changed');

    expect(StockMovement::query()->count())->toBe(0);
});

it('refuses a UUID already used by another person', function () {
    $uuid = (string) Str::uuid();
    scan([operation('move', ['article_id' => $this->article->id, 'from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '1'], $uuid)]);

    $this->actingAs(Bookkeeping::member($this->company, 'stock_clerk'));
    scan([operation('move', ['article_id' => $this->article->id, 'from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '1'], $uuid)])
        ->assertJsonPath('results.0.status', 'REJECTED')
        ->assertJsonPath('results.0.message', 'Operationen har redan skickats från en annan enhet.');

    scan([operation('move', [], 'not-a-uuid')])->assertJsonPath('results.0.status', 'REJECTED');
});

it('looks up articles and locations by every code, without prices', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '3', '80');
    $this->article->update(['scanner_code' => 'KASSA-77', 'sku' => 'SKU-9']);

    foreach (['7350000000016', '07350000000016', 'KASSA-77', 'SKU-9', 'V-1', 'A:V-1', 'a:V-1'] as $code) {
        $this->getJson('/lager/skanna/sok?kod='.urlencode($code))->assertOk()
            ->assertJsonPath('type', 'article')
            ->assertJsonPath('article.name', 'Kaffekopp')
            ->assertJsonPath('stock.0.code', 'HL-A-01')
            ->assertJsonPath('stock.0.quantity', 3000)
            ->assertJsonMissingPath('article.price_ore');
    }

    foreach (['L:HL-A-01', 'l:hl-a-01', 'HL-A-01'] as $code) {
        $this->getJson('/lager/skanna/sok?kod='.urlencode($code))->assertOk()
            ->assertJsonPath('type', 'location')
            ->assertJsonPath('location.code', 'HL-A-01')
            ->assertJsonPath('stock.0.number', 'V-1');
    }

    $this->getJson('/lager/skanna/sok?kod=OKAND')->assertNotFound();
});

it('reads a 12-digit GTIN printed as EAN-13 with a leading zero', function () {
    $upc = Inventory::article($this->company, $this->owner, 'V-3', 'Burk', ['gtin' => '036000291452']);

    $this->getJson('/lager/skanna/sok?kod=0036000291452')->assertOk()->assertJsonPath('article.id', $upc->id);
});

it('shows an article image of the own company only', function () {
    Storage::fake('local');
    Storage::disk('local')->put('artiklar/kopp.png', 'png');
    $image = ArticleImage::create(['article_id' => $this->article->id, 'path' => 'artiklar/kopp.png', 'position' => 0]);

    $this->getJson('/lager/skanna/data')->assertJsonPath('articles.0.image', route('inventory.scanner.image', $image->id, absolute: false));
    $this->get(route('inventory.scanner.image', $image->id))->assertOk();
});

it('answers 404 for every scanner page while Lager is off', function () {
    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get('/lager/skanna')->assertNotFound();
    $this->getJson('/lager/skanna/data')->assertNotFound();
    $this->getJson('/lager/skanna/sok?kod=V-1')->assertNotFound();
    scan([operation('move', [])])->assertNotFound();
});

it('keeps another company out: its ids are not found and its codes unknown', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    Inventory::enable($other, $otherOwner);
    $theirPlaces = Inventory::locations($other, $otherOwner);
    $theirArticle = Inventory::article($other, $otherOwner, 'B-1', 'Hemlig vara', ['gtin' => '7350000000023']);
    Inventory::receive($other, $otherOwner, $theirArticle, $theirPlaces['bin1'], '5', '10');
    $theirReceipt = app(SaveReceipt::class)($otherOwner, $other, ['lines' => [['article_id' => $theirArticle->id, 'quantity' => '1']]]);
    $theirList = app(PickLists::class)->manual($otherOwner, $other, [['article_id' => $theirArticle->id, 'quantity' => '1']]);
    $theirCount = app(StockCounts::class)->start($otherOwner, $other, null, CarbonImmutable::parse('2026-06-15'));
    Storage::fake('local');
    Storage::disk('local')->put('artiklar/hemlig.png', 'png');
    $theirImage = ArticleImage::create(['article_id' => $theirArticle->id, 'path' => 'artiklar/hemlig.png', 'position' => 0]);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $data = $this->getJson('/lager/skanna/data')->assertOk()->getContent();
    expect($data)->not->toContain('Hemlig vara')->not->toContain('7350000000023');

    $this->getJson('/lager/skanna/sok?kod=7350000000023')->assertNotFound();
    $this->get(route('inventory.scanner.image', $theirImage->id))->assertNotFound();

    scan([
        operation('receive', ['receipt_id' => $theirReceipt->id, 'lines' => [['article_id' => $theirArticle->id, 'quantity' => '1']]]),
        operation('pick', ['pick_list_id' => $theirList->id, 'lines' => []]),
        operation('count', ['stock_count_id' => $theirCount->id, 'complete' => true, 'lines' => []]),
    ])->assertOk()
        ->assertJsonPath('results.0.status', 'NOT_FOUND')
        ->assertJsonPath('results.1.status', 'NOT_FOUND')
        ->assertJsonPath('results.2.status', 'NOT_FOUND');

    scan([operation('move', ['article_id' => $theirArticle->id, 'from_location_id' => $theirPlaces['bin1']->id, 'to_location_id' => $this->places['bin1']->id, 'quantity' => '1'])])
        ->assertJsonPath('results.0.status', 'REJECTED');

    $theirs = CompanyScope::bypass(fn () => [
        StockReceipt::query()->whereKey($theirReceipt->id)->value('status'),
        PickList::query()->whereKey($theirList->id)->value('status'),
        StockCount::query()->whereKey($theirCount->id)->value('status'),
        StockMovement::query()->where('company_id', $other->id)->count(),
    ]);

    expect($theirs)->toBe([StockReceipt::OPEN, PickList::OPEN, StockCount::OPEN, 1]);
});
