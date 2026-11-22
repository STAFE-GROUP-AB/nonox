<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Models\PriceAgreement;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Modules\Connect\Webhooks\WebhookEvents;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * ADR-082 P9: price lists, price agreements and the price lookup over the
 * API. The same Actions and the same resolver as the screens, so these tests
 * check the API's own part: scopes, input and output shapes, idempotency,
 * bulk errors per row, cost only with prices:view-cost, the invoice row that
 * is priced when it has no price, and the webhooks.
 */

beforeEach(function () {
    Connect::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Connect::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);

    $this->screw = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500, 'purchase_price' => '40']);
    $this->nut = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-2', 'name' => 'Mutter', 'unit' => 'st', 'price' => '50', 'vat_rate' => 2500]);
    $this->customer = Crm::customer($this->company, $this->owner, 'Byggbolaget AB', ['email' => 'inkop@example.com', 'org_number' => '556036-0793']);

    $all = ['prices:view', 'prices:view-cost', 'price-lists:manage', 'price-agreements:manage', 'invoices:view', 'invoices:create'];
    $this->key = Connect::apiKey($this->owner, $this->company, $all);
    $this->view = Connect::apiKey($this->owner, $this->company, ['prices:view']);
    $this->lists = Connect::apiKey($this->owner, $this->company, ['prices:view', 'price-lists:manage']);
});

function p9List(array $extra = []): PriceList
{
    $test = test();

    return app(SavePriceList::class)($test->owner, $test->company, ['code' => 'ÅF', 'name' => 'Återförsäljare', 'kind' => 'STANDARD', 'currency' => 'SEK', ...$extra]);
}

function p9Item(PriceList $list, array $extra = []): PriceListItem
{
    $test = test();

    return app(SavePriceListItem::class)($test->owner, $test->company, $list, ['article_id' => $test->screw->id, 'mode' => 'price', 'price' => '80', ...$extra]);
}

it('creates a price list idempotently and reads it back with the filters', function () {
    $body = ['code' => 'ÅF', 'name' => 'Återförsäljare', 'kind' => 'STANDARD', 'currency' => 'SEK', 'prices_include_vat' => false];

    $created = $this->withToken($this->lists)->withHeader('Idempotency-Key', 'lista-1')->postJson('/api/v1/price-lists', $body)
        ->assertCreated()
        ->assertJsonPath('data.code', 'ÅF')
        ->assertJsonPath('data.currency', 'SEK')
        ->assertJsonPath('data.derived_percent_bp', null)
        ->assertJsonPath('data.group_ids', []);
    $id = $created->json('data.id');

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'lista-1')->postJson('/api/v1/price-lists', $body)
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(CurrentCompany::run($this->company, fn () => PriceList::query()->count()))->toBe(1);

    $this->withToken($this->lists)->withoutHeader('Idempotency-Key')->postJson('/api/v1/price-lists', $body)->assertStatus(400);

    $this->withToken($this->view)->getJson("/api/v1/price-lists/{$id}")->assertOk()->assertJsonPath('data.name', 'Återförsäljare');
    $this->withToken($this->view)->getJson('/api/v1/price-lists?currency=sek')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->view)->getJson('/api/v1/price-lists?currency=EUR')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->view)->getJson('/api/v1/price-lists?valid_on=2026-06-15')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->view)->getJson('/api/v1/price-lists?article=A-1')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses invalid price list input with the screen\'s messages', function () {
    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'fel-1')->postJson('/api/v1/price-lists', ['code' => 'X', 'name' => 'X', 'kind' => 'STANDARD', 'currency' => 'kronor'])
        ->assertUnprocessable()->assertSee('Skriv valutan som tre bokstäver');
    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'fel-2')->postJson('/api/v1/price-lists', ['code' => 'X', 'name' => 'X', 'kind' => 'STANDARD', 'currency' => 'SEK', 'derived_percent_bp' => '12'])
        ->assertUnprocessable()->assertSee('derived_percent_bp');
    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'fel-3')->postJson('/api/v1/price-lists', ['code' => 'X', 'name' => 'X', 'kind' => 'CAMPAIGN', 'currency' => 'SEK'])
        ->assertUnprocessable();
});

it('makes a derived list and changes a list with PATCH, keeping the other fields', function () {
    $base = p9List();

    $derived = $this->withToken($this->lists)->withHeader('Idempotency-Key', 'harledd-1')->postJson('/api/v1/price-lists', [
        'code' => 'VIP', 'name' => 'VIP', 'kind' => 'STANDARD', 'currency' => 'SEK', 'based_on_list_id' => $base->id, 'derived_percent_bp' => -1250, 'rounding_rule' => 'NONE',
    ])->assertCreated()->assertJsonPath('data.derived_percent_bp', -1250)->assertJsonPath('data.based_on_list_id', $base->id);

    $this->withToken($this->lists)->patchJson('/api/v1/price-lists/'.$derived->json('data.id'), ['name' => 'VIP-kunder'])
        ->assertOk()
        ->assertJsonPath('data.name', 'VIP-kunder')
        ->assertJsonPath('data.derived_percent_bp', -1250)
        ->assertJsonPath('data.code', 'VIP');
});

it('adds prices in bulk, reports the refused rows by position, and keeps the rest', function () {
    $list = p9List();

    $bulk = ['items' => [
        ['article' => 'A-1', 'price_minor' => 8000],
        ['article' => 'A-1', 'price_minor' => 7500, 'min_quantity' => '10'],
        ['article' => 'FINNS-INTE', 'price_minor' => 100],
        ['article' => 'A-2', 'price_minor' => 100, 'discount_bp' => 500],
        ['article' => 'A-2', 'discount_bp' => 1000, 'valid_from' => '2026-07-01'],
    ]];
    $response = $this->withToken($this->lists)->withHeader('Idempotency-Key', 'bulk-1')->postJson("/api/v1/price-lists/{$list->id}/items", $bulk)->assertOk()
        ->assertJsonPath('data.saved', 3)
        ->assertJsonPath('data.failed', 2)
        ->assertJsonPath('data.errors.0.index', 2)
        ->assertJsonPath('data.errors.1.index', 3)
        ->assertJsonPath('data.items.0.price_minor', 8000)
        ->assertJsonPath('data.items.1.min_quantity', '10')
        ->assertJsonPath('data.items.2.discount_bp', 1000);
    expect($response->json('data.errors.0.errors'))->toHaveKey('article');

    expect(CurrentCompany::run($this->company, fn () => PriceListItem::query()->count()))->toBe(3);

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'bulk-1')->postJson("/api/v1/price-lists/{$list->id}/items", $bulk)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect(CurrentCompany::run($this->company, fn () => PriceListItem::query()->count()))->toBe(3);

    $this->withToken($this->view)->getJson("/api/v1/price-lists/{$list->id}/items?article=A-1")->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($this->view)->getJson("/api/v1/price-lists/{$list->id}/items?valid_on=2026-06-15")->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($this->view)->getJson("/api/v1/price-lists/{$list->id}/items?valid_on=2026-07-02")->assertOk()->assertJsonCount(3, 'data');
});

it('answers 422 when no row of a bulk call was saved, refuses more than 1 000 rows and an empty list', function () {
    $list = p9List();

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'bulk-2')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [['article' => 'X', 'price_minor' => 1]]])
        ->assertUnprocessable()->assertJsonPath('data.failed', 1)->assertJsonPath('data.saved', 0);

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'bulk-3')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => array_fill(0, 1001, ['article' => 'A-1', 'price_minor' => 1])])
        ->assertUnprocessable()->assertSee('Högst 1000 priser');

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'bulk-4')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => []])->assertUnprocessable();
});

it('lets a new price take over from a running one with replace_running', function () {
    $list = p9List();
    $running = p9Item($list, ['valid_from' => '2026-01-01']);

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'byt-1')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [
        ['article' => 'A-1', 'price_minor' => 9000, 'valid_from' => '2026-07-01'],
    ]])->assertUnprocessable()->assertJsonPath('data.failed', 1);

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'byt-2')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [
        ['article' => 'A-1', 'price_minor' => 9000, 'valid_from' => '2026-07-01', 'replace_running' => true],
    ]])->assertCreated();

    expect($running->fresh()->valid_to->toDateString())->toBe('2026-06-30');
});

it('refuses replace_running when it would end a started price back in time', function () {
    $list = p9List();
    $running = p9Item($list, ['valid_from' => '2026-01-01']);

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'byt-3')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [
        ['article' => 'A-1', 'price_minor' => 9000, 'valid_from' => '2026-03-01', 'replace_running' => true],
    ]])->assertUnprocessable()->assertJsonPath('data.failed', 1);

    expect($running->fresh()->valid_to)->toBeNull();
});

it('denies the writes without the manage scope and the reads without prices:view', function () {
    $list = p9List();
    $none = Connect::apiKey($this->owner, $this->company, ['invoices:view']);

    $this->withToken($this->view)->withHeader('Idempotency-Key', 'nej-1')->postJson('/api/v1/price-lists', ['code' => 'X', 'name' => 'X', 'kind' => 'STANDARD', 'currency' => 'SEK'])
        ->assertForbidden()->assertJsonPath('required_scopes.0', 'price-lists:manage');
    $this->withToken($this->view)->patchJson("/api/v1/price-lists/{$list->id}", ['name' => 'Y'])->assertForbidden();
    $this->withToken($this->view)->withHeader('Idempotency-Key', 'nej-2')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [['article' => 'A-1', 'price_minor' => 1]]])->assertForbidden();
    $this->withToken($this->view)->withHeader('Idempotency-Key', 'nej-3')->postJson('/api/v1/price-agreements', [])->assertForbidden();
    $this->withToken($none)->getJson('/api/v1/price-lists')->assertForbidden();
    $this->withToken($none)->getJson('/api/v1/prices/resolve?rows[]=A-1')->assertForbidden();
    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'nej-4')->postJson('/api/v1/price-agreements', [])->assertForbidden();
});

it('runs the agreement through draft, terms, activation and termination', function () {
    $draft = $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-1')->postJson('/api/v1/price-agreements', [
        'customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01',
    ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.reference', 'PA-1')->assertJsonPath('data.items', []);
    $id = $draft->json('data.id');

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-1')->postJson('/api/v1/price-agreements', [
        'customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01',
    ])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(CurrentCompany::run($this->company, fn () => PriceAgreement::query()->count()))->toBe(1);

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-akt-0')->postJson("/api/v1/price-agreements/{$id}/activate", ['reason' => 'Undertecknat på papper.'])
        ->assertUnprocessable()->assertSee('minst ett pris');

    $this->withToken($this->key)->patchJson("/api/v1/price-agreements/{$id}", ['title' => 'Ramavtal 2026'])->assertOk()->assertJsonPath('data.title', 'Ramavtal 2026')->assertJsonPath('data.valid_from', '2026-06-01');

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-rader')->postJson("/api/v1/price-agreements/{$id}/items", ['items' => [
        ['article' => 'A-1', 'price_minor' => 9000],
        ['target' => 'all', 'discount_bp' => 500],
        ['article' => 'NEJ', 'price_minor' => 1],
    ]])->assertOk()->assertJsonPath('data.saved', 2)->assertJsonPath('data.failed', 1)->assertJsonPath('data.items.1.target', 'all');

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-akt-1')->postJson("/api/v1/price-agreements/{$id}/activate", ['reason' => 'x'])->assertUnprocessable();
    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-akt-2')->postJson("/api/v1/price-agreements/{$id}/activate", ['reason' => 'Undertecknat på papper.'])
        ->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.activation_method', 'MANUAL')->assertJsonCount(2, 'data.items');

    $this->withToken($this->key)->patchJson("/api/v1/price-agreements/{$id}", ['title' => 'Ändrat'])->assertForbidden();
    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-slut-0')->postJson("/api/v1/price-agreements/{$id}/terminate", ['terminated_on' => '2026-06-01'])->assertUnprocessable();
    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-slut-1')->postJson("/api/v1/price-agreements/{$id}/terminate", ['terminated_on' => '2026-12-31'])
        ->assertOk()->assertJsonPath('data.status', 'TERMINATED')->assertJsonPath('data.terminated_on', '2026-12-31');

    $this->withToken($this->view)->getJson('/api/v1/price-agreements?status=TERMINATED')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->view)->getJson("/api/v1/price-agreements?customer_id={$this->customer->id}&valid_on=2027-01-01")->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->view)->getJson("/api/v1/price-agreements/{$id}")->assertOk()->assertJsonPath('data.items.0.article', 'A-1');
});

it('resolves prices with the same engine as the editors, with source and explanation, cost only with prices:view-cost', function () {
    $list = p9List();
    p9Item($list);
    app(SavePriceList::class)($this->owner, $this->company, ['code' => 'ÅF', 'name' => 'Återförsäljare', 'kind' => 'STANDARD', 'currency' => 'SEK', 'is_default_for_currency' => true], $list);

    $agreement = app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01']);
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $agreement, ['target' => 'article', 'article_id' => $this->screw->id, 'mode' => 'price', 'price' => '70']);
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $agreement, 'Undertecknat på papper.');

    $query = 'date=2026-06-15&rows[]=A-1:5&rows[]=A-2';
    $withCost = $this->withToken($this->key)->getJson("/api/v1/prices/resolve?customer={$this->customer->id}&{$query}")->assertOk()
        ->assertJsonPath('data.currency', 'SEK')
        ->assertJsonPath('data.rows.0.article', 'A-1')
        ->assertJsonPath('data.rows.0.unit_price_minor', 7000)
        ->assertJsonPath('data.rows.0.price_source', 'AGREEMENT')
        ->assertJsonPath('data.rows.0.unit_cost_minor', 4000)
        ->assertJsonPath('data.rows.1.unit_price_minor', 5000)
        ->assertJsonPath('data.rows.1.price_source', 'BASE');
    expect($withCost->json('data.rows.0.explanation'))->toBeArray()->not->toBeEmpty();

    $without = $this->withToken($this->view)->getJson("/api/v1/prices/resolve?customer={$this->customer->id}&{$query}")->assertOk()
        ->assertJsonPath('data.rows.0.unit_price_minor', 7000);
    expect($without->json('data.rows.0'))->not->toHaveKey('unit_cost_minor');

    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?rows[]=A-1')->assertOk()->assertJsonPath('data.rows.0.unit_price_minor', 8000)->assertJsonPath('data.rows.0.price_source', 'CURRENCY_LIST');
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?currency=EUR&rows[]=A-1')->assertOk()->assertJsonPath('data.currency', 'EUR');
});

it('validates the lookup: rows required, at most 500, known articles and units, customer in the company', function () {
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve')->assertUnprocessable();
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?'.http_build_query(['rows' => array_fill(0, 501, 'A-1')]))->assertUnprocessable()->assertSee('Högst 500');
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?'.http_build_query(['rows' => array_fill(0, 500, 'A-1')]))->assertOk()->assertJsonCount(500, 'data.rows');
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?rows[]=FINNS-INTE')->assertUnprocessable()->assertSee('finns inte');
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?rows[]=A-1:1:xyz')->assertUnprocessable()->assertSee('Enheten');
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?rows[]=A-1:1.5.5')->assertUnprocessable();
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?customer=999999&rows[]=A-1')->assertNotFound();
    $this->withToken($this->view)->getJson('/api/v1/prices/resolve?date=igår&rows[]=A-1')->assertUnprocessable();
});

it('prices an invoice row that has no price and shows price_source; a typed price stays manual', function () {
    $list = p9List(['is_default_for_currency' => true]);
    p9Item($list);

    $invoice = $this->withToken($this->key)->withHeader('Idempotency-Key', 'faktura-1')->postJson('/api/v1/invoices', [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-06-15',
        'due_date' => '2026-07-15',
        'rows' => [
            ['article' => 'A-1', 'quantity' => 2],
            ['article' => 'A-2', 'quantity' => 1, 'unit_price_ore' => 4200],
            ['description' => 'Fri rad', 'quantity' => 1, 'unit_price_ore' => 1000, 'vat_rate' => 2500],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.rows.0.unit_price_ore', 8000)
        ->assertJsonPath('data.rows.0.price_source', 'CURRENCY_LIST')
        ->assertJsonPath('data.rows.1.unit_price_ore', 4200)
        ->assertJsonPath('data.rows.1.price_source', 'MANUAL')
        ->assertJsonPath('data.rows.2.price_source', null);

    $this->withToken($this->key)->patchJson('/api/v1/invoices/'.$invoice->json('data.id'), ['due_date' => '2026-07-20'])->assertOk()
        ->assertJsonPath('data.rows.0.unit_price_ore', 8000)
        ->assertJsonPath('data.rows.0.price_source', 'CURRENCY_LIST')
        ->assertJsonPath('data.rows.1.price_source', 'MANUAL');
});

it('announces price list changes and agreement life cycle to webhooks with ids and dates only', function () {
    Http::fake(['*' => Http::response('', 200)]);
    Connect::fakeDns(['hooks.example.com' => ['93.184.216.34']]);
    Connect::webhook($this->owner, $this->company, ['price_list.changed', 'price_agreement.activated', 'price_agreement.ended']);

    $list = p9List();
    Http::assertNothingSent();

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'hook-1')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [
        ['article' => 'A-1', 'price_minor' => 8000, 'valid_from' => '2026-07-01'],
        ['article' => 'A-2', 'price_minor' => 4000, 'valid_from' => '2026-06-20'],
        ['article' => 'A-2', 'price_minor' => 3500, 'min_quantity' => '10', 'valid_from' => '2026-06-25'],
    ]])->assertCreated();

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($list): bool {
        $body = json_decode($request->body(), true);

        return $body['type'] === 'price_list.changed'
            && $body['data'] === ['price_list_id' => $list->id, 'currency' => 'SEK', 'changed_from' => '2026-06-20'];
    });

    $agreement = app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01']);
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $agreement, ['target' => 'all', 'mode' => 'discount', 'discount' => '5']);

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'hook-2')->postJson("/api/v1/price-agreements/{$agreement->id}/activate", ['reason' => 'Undertecknat på papper.'])->assertOk();
    $this->withToken($this->key)->withHeader('Idempotency-Key', 'hook-3')->postJson("/api/v1/price-agreements/{$agreement->id}/terminate", ['terminated_on' => '2026-12-31'])->assertOk();

    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => json_decode($request->body(), true)['type'] === 'price_agreement.activated'
        && json_decode($request->body(), true)['data']['price_agreement_id'] === $agreement->id
        && json_decode($request->body(), true)['data']['customer_id'] === $this->customer->id
        && ! str_contains($request->body(), 'Ramavtal')
        && ! str_contains($request->body(), 'Undertecknat'));
    Http::assertSent(fn ($request): bool => json_decode($request->body(), true)['type'] === 'price_agreement.ended'
        && json_decode($request->body(), true)['data']['reason'] === 'terminated'
        && json_decode($request->body(), true)['data']['last_day'] === '2026-12-31');
});

it('lets the price events through only to a creator who may see prices', function () {
    expect(array_keys(WebhookEvents::EVENTS))->toContain('price_list.changed', 'price_agreement.activated', 'price_agreement.ended')
        ->and(WebhookEvents::permissions('price_list.changed'))->toBe(['prices:view'])
        ->and(WebhookEvents::permissions('price_agreement.activated'))->toBe(['prices:view'])
        ->and(WebhookEvents::permissions('price_agreement.ended'))->toBe(['prices:view'])
        ->and(array_keys(WebhookEvents::labels()))->toBe(array_keys(WebhookEvents::EVENTS));
});

it('reads the lists in a fixed number of queries however many rows there are', function () {
    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($this->view)->getJson($url)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $list = p9List();
    p9Item($list);
    p9Item($list, ['article_id' => $this->nut->id]);
    p9List(['code' => 'B']);
    $agreement = app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'A', 'currency' => 'SEK', 'valid_from' => '2026-06-01']);

    $count('/api/v1/price-lists');
    $count('/api/v1/prices/resolve?rows[]=A-1');
    $few = [$count('/api/v1/price-lists'), $count("/api/v1/price-lists/{$list->id}/items"), $count('/api/v1/price-agreements'), $count('/api/v1/prices/resolve?rows[]=A-1&rows[]=A-2')];

    for ($i = 0; $i < 6; $i++) {
        $extra = p9List(['code' => "L{$i}"]);
        p9Item($extra);
        p9Item($extra, ['article_id' => $this->nut->id]);
        p9Item($list, ['article_id' => $this->screw->id, 'min_quantity' => (string) (10 * ($i + 1))]);
        app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => "A{$i}", 'currency' => 'SEK', 'valid_from' => '2027-0'.($i + 1).'-01', 'valid_to' => '2027-0'.($i + 1).'-28']);
    }

    $many = [$count('/api/v1/price-lists'), $count("/api/v1/price-lists/{$list->id}/items"), $count('/api/v1/price-agreements'), $count('/api/v1/prices/resolve?rows[]=A-1&rows[]=A-2&rows[]=A-1:50&rows[]=A-2:20&rows[]=A-1:5&rows[]=A-2:5')];

    expect($many)->toBe($few)->and($agreement->id)->toBeInt();
});

it('keeps the saved rows when a later row has an unreadable date, and refuses an array as article', function () {
    $list = p9List();

    $this->withToken($this->lists)->withHeader('Idempotency-Key', 'rob-1')->postJson("/api/v1/price-lists/{$list->id}/items", ['items' => [
        ['article' => 'A-1', 'price_minor' => 8000],
        ['article' => 'A-2', 'price_minor' => 100, 'valid_from' => 'nonsense', 'replace_running' => true],
        ['article' => ['A-1'], 'price_minor' => 100],
    ]])->assertOk()
        ->assertJsonPath('data.saved', 1)
        ->assertJsonPath('data.failed', 2)
        ->assertJsonPath('data.errors.0.index', 1)
        ->assertJsonPath('data.errors.1.index', 2);

    $agreement = app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'A', 'currency' => 'SEK', 'valid_from' => '2026-06-01']);
    $this->withToken($this->key)->withHeader('Idempotency-Key', 'rob-2')->postJson("/api/v1/price-agreements/{$agreement->id}/items", ['items' => [
        ['article' => ['A-1'], 'price_minor' => 1],
        ['article' => 'A-1', 'price_minor' => 1],
    ]])->assertOk()->assertJsonPath('data.saved', 1)->assertJsonPath('data.errors.0.index', 0);
});
