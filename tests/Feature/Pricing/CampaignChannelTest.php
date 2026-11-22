<?php

declare(strict_types=1);

use App\Actions\Pricing\SavePriceList;
use App\Domain\Pricing\ChannelPrices;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Livewire\Pricing\PriceLists;
use App\Models\Article;
use App\Models\PriceList;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\PricingScenario;

/*
 * ADR-082 P11: a campaign may name one channel. With a channel it applies only
 * when the document's channel is the same; without one it applies everywhere.
 */

function campaignScenario(array $campaigns): PricingScenario
{
    $lists = [];

    foreach ($campaigns as $code => $channel) {
        $lists[$code] = [
            'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'channel' => $channel,
            'items' => [['article' => 'A1', 'price_minor' => $code === 'TILL' ? 7000 : ($code === 'FAKT' ? 8000 : 9000)]],
        ];
    }

    return PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000]],
        'lists' => $lists,
    ]);
}

it('applies a campaign with a channel only in that channel, and one without a channel everywhere', function () {
    $scenario = campaignScenario(['TILL' => 'POS', 'FAKT' => 'SALES', 'ALLA' => null]);
    $request = [$scenario->request(['key' => 'r', 'article' => 'A1'])];
    $unit = fn (string $channel): int => $scenario->resolve(['date' => '2026-06-15', 'no_customer' => true, 'channel' => $channel], $request)['r']->unitPriceMinor;

    // A SALES campaign reaches every document channel except the till and the portal.
    expect($unit('SALES'))->toBe(8000)
        ->and($unit('RECURRING'))->toBe(8000)
        ->and($unit('WORK_ORDER'))->toBe(8000)
        ->and($unit('POS'))->toBe(7000)
        ->and($unit('PORTAL'))->toBe(9000);
});

it('does not let a till campaign reach a sales document', function () {
    $scenario = campaignScenario(['TILL' => 'POS']);
    $price = $scenario->resolve(['date' => '2026-06-15', 'no_customer' => true, 'channel' => 'SALES'], [$scenario->request(['key' => 'r', 'article' => 'A1'])])['r'];

    expect($price->source)->toBe(PriceSource::Base)->and($price->unitPriceMinor)->toBe(10000);
});

it('gives the till and the labels only campaigns without a channel or for the till', function () {
    $scenario = campaignScenario(['FAKT' => 'SALES']);
    $articles = Article::query()->get();

    expect(app(ChannelPrices::class)->gross($articles, null, CarbonImmutable::parse('2026-06-15'))[$articles->first()->id])->toBe(12500);

    $other = campaignScenario(['TILL' => 'POS']);
    $articles = Article::query()->get();
    $gross = app(ChannelPrices::class)->gross($articles, null, CarbonImmutable::parse('2026-06-15'));

    expect($gross)->not->toBeEmpty()->and(min($gross))->toBe(8750);
});

it('keeps the query count the same with channel campaigns present', function () {
    $scenario = campaignScenario(['TILL' => 'POS', 'FAKT' => 'SALES', 'ALLA' => null]);
    $context = $scenario->context(['date' => '2026-06-15', 'no_customer' => true, 'channel' => 'POS']);
    $article = $scenario->articles['A1'];
    $count = function (int $rows) use ($context, $article): int {
        $requests = array_map(fn (int $i): PriceRequest => new PriceRequest("r{$i}", $article), range(1, $rows));
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PriceResolver::class)->resolveMany($context, $requests);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(60))->toBe($count(1));
});

describe('saving', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
        [$this->owner, $this->company] = Bookkeeping::company();
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
        $this->save = fn (array $input = [], ?PriceList $list = null): PriceList => app(SavePriceList::class)($this->owner, $this->company, [
            'code' => 'K', 'name' => 'Kampanj', 'kind' => 'CAMPAIGN', 'currency' => 'SEK', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', ...$input,
        ], $list);
    });

    it('stores a channel on a campaign, none when it is empty or absent, and can clear it', function () {
        $list = ($this->save)(['channel' => 'POS']);
        expect($list->channel)->toBe(PriceChannel::Pos);

        ($this->save)(['channel' => ''], $list);
        expect($list->fresh()->channel)->toBeNull();

        ($this->save)(['channel' => 'SALES'], $list);
        expect($list->fresh()->channel)->toBe(PriceChannel::Sales);

        ($this->save)(['channel' => null], $list);
        expect($list->fresh()->channel)->toBeNull()
            ->and(($this->save)(['code' => 'K2'])->channel)->toBeNull();
    });

    it('refuses a campaign channel other than the till and documents', function () {
        foreach (['PORTAL', 'RECURRING', 'WORK_ORDER'] as $channel) {
            expect(fn () => ($this->save)(['code' => "K{$channel}", 'channel' => $channel]))->toThrow(ValidationException::class);
        }
    });

    it('sets the channel from the type chosen in the form, whatever the list filter says', function () {
        $form = Livewire::test(PriceLists::class)->call('create')->assertSet('form.channel', 'POS');

        $form->set('form.kind', 'CAMPAIGN')->assertSet('form.channel', '')
            ->set('form.code', 'FILT')->set('form.name', 'Filterkampanj')->set('form.valid_from', '2026-06-01')->set('form.valid_to', '2026-06-30')
            ->call('save')->assertHasNoErrors();

        expect(PriceList::query()->where('code', 'FILT')->firstOrFail()->channel)->toBeNull();

        Livewire::test(PriceLists::class)->set('kind', 'CAMPAIGN')->call('create')->set('form.kind', 'CHANNEL')->assertSet('form.channel', 'POS');
    });

    it('refuses an unknown channel and still ignores a channel on a standard list', function () {
        expect(fn () => ($this->save)(['channel' => 'MOON']))->toThrow(ValidationException::class);
        expect(($this->save)(['code' => 'S', 'kind' => 'STANDARD', 'valid_from' => null, 'valid_to' => null, 'channel' => 'POS'])->channel)->toBeNull();
    });

    it('still needs POS or PORTAL on a channel list', function () {
        expect(fn () => ($this->save)(['code' => 'C', 'kind' => 'CHANNEL', 'valid_from' => null, 'valid_to' => null, 'channel' => 'SALES']))->toThrow(ValidationException::class)
            ->and(fn () => ($this->save)(['code' => 'C', 'kind' => 'CHANNEL', 'valid_from' => null, 'valid_to' => null]))->toThrow(ValidationException::class);
    });

    it('offers a channel on the campaign form, defaulting to all channels', function () {
        Livewire::test(PriceLists::class)
            ->set('kind', 'CAMPAIGN')
            ->call('create')
            ->assertSet('form.channel', '')
            ->assertSee('Alla kanaler')
            ->assertSee('Fakturor, offerter och order')
            ->set('form.code', 'KASSA')
            ->set('form.name', 'Kassakampanj')
            ->set('form.valid_from', '2026-06-01')
            ->set('form.valid_to', '2026-06-30')
            ->set('form.channel', 'POS')
            ->call('save')
            ->assertHasNoErrors();

        $list = PriceList::query()->where('code', 'KASSA')->firstOrFail();
        expect($list->channel)->toBe(PriceChannel::Pos);

        Livewire::test(PriceLists::class)->call('edit', $list->id)->assertSet('form.channel', 'POS')->assertSee('Kassan');
    });

    it('takes channel on a campaign over the API', function () {
        Connect::setUp();
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $key = Connect::apiKey($owner, $company, ['prices:view', 'price-lists:manage']);

        $this->withToken($key)->withHeader('Idempotency-Key', 'kanal-1')->postJson('/api/v1/price-lists', [
            'code' => 'KASSA', 'name' => 'Kassakampanj', 'kind' => 'CAMPAIGN', 'currency' => 'SEK', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'channel' => 'POS',
        ])->assertCreated()->assertJsonPath('data.channel', 'POS');

        $this->withToken($key)->withHeader('Idempotency-Key', 'kanal-2')->postJson('/api/v1/price-lists', [
            'code' => 'ALLA', 'name' => 'Alla', 'kind' => 'CAMPAIGN', 'currency' => 'SEK', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30',
        ])->assertCreated()->assertJsonPath('data.channel', null);

        $this->withToken($key)->withHeader('Idempotency-Key', 'kanal-3')->postJson('/api/v1/price-lists', [
            'code' => 'FEL', 'name' => 'Fel', 'kind' => 'CAMPAIGN', 'currency' => 'SEK', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'channel' => 'MOON',
        ])->assertStatus(422);
    });
});
