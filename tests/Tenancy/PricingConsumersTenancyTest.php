<?php

declare(strict_types=1);

use App\Domain\Pricing\ChannelPrices;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Models\Article;
use App\Modules\Pos\Actions\SavePosConnectionSettings;
use App\Modules\Pos\Models\PosProductLink;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\Pos;
use Tests\Support\PricingScenario;

/*
 * AGENTS.md §13.2 for the price engine's other consumers (ADR-082 P8): a
 * till or label can only name its own company's CHANNEL list, and the nightly
 * sync of company A never reads, prices or sends company B's articles.
 */

beforeEach(function () {
    Queue::fake();
    Pos::configure();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Pos::company();
        $this->actingAs($owner);
        $world = PricingScenario::build([
            'articles' => ['A1' => ['price_ore' => 10000]],
            'lists' => ['KASSA' => ['kind' => 'CHANNEL', 'channel' => 'POS', 'items' => [['article' => 'A1', 'price_minor' => $side === 'a' ? 9000 : 7000]]]],
        ], $owner, $company);
        $this->{$side} = ['owner' => $owner, 'company' => $company, 'world' => $world, 'connection' => Pos::connection($company, $owner, settings: ['price_list_id' => $world->lists['KASSA']])];
    }
});

it('never uses another company\'s channel list for a price', function () {
    CurrentCompany::set($this->a['company']);
    $article = Article::query()->where('number', 'A1')->sole();
    $context = new PriceContext($this->a['company']->id, '2026-06-15', 'SEK', null, PriceChannel::Pos, $this->b['world']->lists['KASSA']);

    $price = app(PriceResolver::class)->resolve($context, new PriceRequest('r', $article->id));
    $gross = app(ChannelPrices::class)->gross([$article], $this->b['world']->lists['KASSA']);

    expect($price->unitPriceMinor)->toBe(10000)
        ->and($price->source)->toBe(PriceSource::Base)
        ->and($gross[$article->id])->toBe(12500);
});

it('refuses another company\'s list in the till settings', function () {
    $save = fn () => app(SavePosConnectionSettings::class)($this->a['owner'], $this->a['company'], $this->a['connection'], [
        'sync_direction' => 'ONE_WAY', 'push_products' => true, 'push_stock' => true, 'location_id' => null, 'price_list_id' => $this->b['world']->lists['KASSA'],
    ]);

    expect($save)->toThrow(ValidationException::class);
});

it('syncs one company\'s till without touching the other company\'s', function () {
    Http::fake(['products.izettle.com/*' => Http::response([], 201)]);

    $this->artisan('pricing:sync-channels', ['--company' => $this->a['company']->id])->assertSuccessful();

    expect(CurrentCompany::run($this->a['company'], fn (): int => PosProductLink::query()->count()))->toBe(1)
        ->and(CurrentCompany::run($this->b['company'], fn (): int => PosProductLink::query()->count()))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['variants'][0]['price']['amount'] === 11250);

    $this->artisan('pricing:sync-channels')->assertSuccessful();

    expect(CurrentCompany::run($this->b['company'], fn (): int => PosProductLink::query()->count()))->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['variants'][0]['price']['amount'] === 8750);
});
