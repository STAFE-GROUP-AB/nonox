<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the price API (ADR-082 P9): a key of company A never
 * sees B's price lists, items, agreements, customers or articles; B's ids
 * answer 404 on every route; a webhook of A hears nothing of B's prices.
 */

beforeEach(function () {
    Connect::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $article = app(SaveArticle::class)($owner, $company, ['number' => $side === 'a' ? 'A-1' : 'B-1', 'name' => "{$name}artikel", 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
        $customer = Crm::customer($company, $owner, "{$name} Kund AB");
        $list = app(SavePriceList::class)($owner, $company, ['code' => 'L', 'name' => "{$name}lista", 'kind' => 'STANDARD', 'currency' => 'SEK']);
        app(SavePriceListItem::class)($owner, $company, $list, ['article_id' => $article->id, 'mode' => 'price', 'price' => '80']);
        $channel = app(SavePriceList::class)($owner, $company, ['code' => 'K', 'name' => "{$name}kassa", 'kind' => 'CHANNEL', 'currency' => 'SEK', 'channel' => 'POS']);
        $agreement = app(SavePriceAgreement::class)($owner, $company, ['customer_id' => $customer->id, 'title' => "{$name}avtal", 'currency' => 'SEK', 'valid_from' => '2026-06-01']);
        app(SavePriceAgreementItem::class)($owner, $company, $agreement, ['target' => 'all', 'mode' => 'discount', 'discount' => '5']);
        $key = Connect::apiKey($owner, $company, ['prices:view', 'prices:view-cost', 'price-lists:manage', 'price-agreements:manage']);
        $this->{$side} = compact('owner', 'company', 'article', 'customer', 'list', 'channel', 'agreement', 'key');
    }
});

it('lists only the key\'s own company\'s lists, items and agreements', function () {
    $key = $this->a['key'];

    $lists = $this->withToken($key)->getJson('/api/v1/price-lists')->assertOk();
    expect(collect($lists->json('data'))->pluck('name')->all())->toBe(['Alfalista', 'Alfakassa']);

    $items = $this->withToken($key)->getJson("/api/v1/price-lists/{$this->a['list']->id}/items")->assertOk();
    expect(collect($items->json('data'))->pluck('article')->all())->toBe(['A-1']);

    $agreements = $this->withToken($key)->getJson('/api/v1/price-agreements')->assertOk();
    expect(collect($agreements->json('data'))->pluck('title')->all())->toBe(['Alfaavtal']);
});

it('answers 404 for the other company\'s ids on every route, never 403', function () {
    $key = $this->a['key'];
    $list = $this->b['list']->id;
    $agreement = $this->b['agreement']->id;

    $this->withToken($key)->getJson("/api/v1/price-lists/{$list}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/price-lists/{$list}/items")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/price-lists/{$list}", ['name' => 'Kapad'])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-1')->postJson("/api/v1/price-lists/{$list}/items", ['items' => [['article' => 'A-1', 'price_minor' => 1]]])->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/price-agreements/{$agreement}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/price-agreements/{$agreement}", ['title' => 'Kapad'])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-2')->postJson("/api/v1/price-agreements/{$agreement}/items", ['items' => [['article' => 'A-1', 'price_minor' => 1]]])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-3')->postJson("/api/v1/price-agreements/{$agreement}/activate", ['reason' => 'Undertecknat på papper.'])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-4')->postJson("/api/v1/price-agreements/{$agreement}/terminate", ['terminated_on' => '2026-12-31'])->assertNotFound();

    CurrentCompany::set($this->b['company']);
    expect($this->b['list']->fresh()->name)->toBe('Betalista')
        ->and($this->b['agreement']->fresh()->title)->toBe('Betaavtal');
});

it('does not look up the other company\'s customer, channel list or articles', function () {
    $key = $this->a['key'];

    $this->withToken($key)->getJson("/api/v1/prices/resolve?customer={$this->b['customer']->id}&rows[]=A-1")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/prices/resolve?channel=POS&channel_list={$this->b['channel']->id}&rows[]=A-1")->assertNotFound();
    $this->withToken($key)->getJson('/api/v1/prices/resolve?rows[]=B-1')->assertUnprocessable();

    $this->withToken($key)->withHeader('Idempotency-Key', 't-5')->postJson("/api/v1/price-lists/{$this->a['list']->id}/items", ['items' => [['article' => 'B-1', 'price_minor' => 1]]])
        ->assertUnprocessable()->assertJsonPath('data.errors.0.index', 0);
    $this->withToken($key)->withHeader('Idempotency-Key', 't-6')->postJson('/api/v1/price-agreements', ['customer_id' => $this->b['customer']->id, 'title' => 'X', 'currency' => 'SEK', 'valid_from' => '2026-06-01'])
        ->assertUnprocessable()->assertSee('Kunden finns inte');
    $this->withToken($key)->withHeader('Idempotency-Key', 't-7')->postJson('/api/v1/price-lists', ['code' => 'V', 'name' => 'V', 'kind' => 'STANDARD', 'currency' => 'SEK', 'based_on_list_id' => $this->b['list']->id, 'derived_percent_bp' => -500])
        ->assertUnprocessable()->assertSee('Prislistan finns inte');
});

it('lets a webhook hear only of its own company\'s price changes', function () {
    Http::fake(['*' => Http::response('', 200)]);
    Connect::fakeDns(['hooks.example.com' => ['93.184.216.34']]);
    Connect::webhook($this->b['owner'], $this->b['company'], ['price_list.changed', 'price_agreement.activated']);

    $this->withToken($this->a['key'])->withHeader('Idempotency-Key', 't-8')->postJson("/api/v1/price-lists/{$this->a['list']->id}/items", ['items' => [['article' => 'A-1', 'price_minor' => 7000, 'min_quantity' => '10']]])->assertCreated();
    $this->withToken($this->a['key'])->withHeader('Idempotency-Key', 't-9')->postJson("/api/v1/price-agreements/{$this->a['agreement']->id}/activate", ['reason' => 'Undertecknat på papper.'])->assertOk();

    Http::assertNothingSent();
});
