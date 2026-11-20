<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\ResolvedPrice;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\Currency\RiksbankRates;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §5 (P2): the edges of price resolution — validity, campaigns
 * without dates, a currency without a price or a rate, units that do not
 * convert, loops between derived lists, manual discounts on top, and
 * references to another company's data, which are never used.
 */

/** @param  array<string, mixed>  $fixture */
function p2Scenario(array $fixture): PricingScenario
{
    return PricingScenario::build(['articles' => ['A1' => ['price_ore' => 10000]], ...$fixture]);
}

function p2One(PricingScenario $scenario, array $context, array $row = []): ResolvedPrice
{
    return $scenario->resolve($context, [$scenario->request(['key' => 'r', 'article' => 'A1', ...$row])])['r'];
}

it('does not use an agreement that has expired, or one that has not started', function () {
    $scenario = p2Scenario([
        'customer' => [],
        'agreements' => [
            'OLD' => ['valid_from' => '2025-01-01', 'valid_to' => '2026-03-14', 'items' => [['article' => 'A1', 'price_minor' => 5000]]],
            'NEW' => ['valid_from' => '2026-03-16', 'items' => [['article' => 'A1', 'price_minor' => 6000]]],
        ],
    ]);

    expect(p2One($scenario, ['date' => '2026-03-15'])->source)->toBe(PriceSource::Base)
        ->and(p2One($scenario, ['date' => '2026-03-14'])->unitPriceMinor)->toBe(5000)
        ->and(p2One($scenario, ['date' => '2026-03-16'])->unitPriceMinor)->toBe(6000);
});

it('stops an agreement at its termination date', function () {
    $scenario = p2Scenario([
        'customer' => [],
        'agreements' => ['PA' => ['valid_from' => '2026-01-01', 'status' => 'TERMINATED', 'terminated_on' => '2026-02-28', 'items' => [['article' => 'A1', 'price_minor' => 5000]]]],
    ]);

    expect(p2One($scenario, ['date' => '2026-02-28'])->source)->toBe(PriceSource::Agreement)
        ->and(p2One($scenario, ['date' => '2026-03-01'])->source)->toBe(PriceSource::Base);
});

it('refuses a campaign without both dates, whatever wrote it', function () {
    $date = CarbonImmutable::parse('2026-05-15');
    $campaign = fn (array $dates): PriceList => new PriceList(['kind' => PriceListKind::Campaign, ...$dates]);

    expect(PriceBook::isUsableCampaign($campaign(['valid_from' => '2026-05-01', 'valid_to' => '2026-05-31']), $date))->toBeTrue()
        ->and(PriceBook::isUsableCampaign($campaign(['valid_from' => '2026-05-01']), $date))->toBeFalse()
        ->and(PriceBook::isUsableCampaign($campaign(['valid_to' => '2026-05-31']), $date))->toBeFalse()
        ->and(PriceBook::isUsableCampaign($campaign([]), $date))->toBeFalse()
        ->and(PriceBook::isUsableCampaign($campaign(['valid_from' => '2026-06-01', 'valid_to' => '2026-06-30']), $date))->toBeFalse()
        ->and(PriceBook::isUsableCampaign(new PriceList(['kind' => PriceListKind::Standard, 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-31']), $date))->toBeFalse();
});

it('gives no price and says why when the currency has no list and Riksbanken has no rate', function () {
    $scenario = p2Scenario(['customer' => []]);

    $price = p2One($scenario, ['date' => '2026-03-15', 'currency' => 'NOK']);

    expect($price->hasPrice())->toBeFalse()
        ->and($price->source)->toBeNull()
        ->and($price->warnings)->toBe([ResolvedPrice::WARNING_NO_RATE, ResolvedPrice::WARNING_NO_PRICE]);
});

it('asks for the exchange rate once for a whole document', function () {
    $scenario = p2Scenario(['rate' => ['currency' => 'EUR', 'date' => '2026-03-13', 'micros' => 11_000_000]]);

    $scenario->resolve(['date' => '2026-03-15', 'currency' => 'EUR'], array_map(
        fn (int $i) => $scenario->request(['key' => "r{$i}", 'article' => 'A1', 'quantity_milli' => $i * 1000]),
        range(1, 20),
    ));

    expect(app(RiksbankRates::class)->calls)->toBe(1);
});

it('does not ask for a rate for a SEK document', function () {
    $scenario = p2Scenario([]);

    p2One($scenario, ['date' => '2026-03-15']);

    expect(app(RiksbankRates::class)->calls)->toBe(0);
});

it('skips an item whose unit does not convert and lists it as considered', function () {
    $scenario = p2Scenario([
        'lists' => ['L' => ['items' => [['article' => 'A1', 'unit' => 'kg', 'price_minor' => 1000]]]],
        'customer' => ['list' => 'L'],
    ]);

    $price = p2One($scenario, ['date' => '2026-03-15']);

    expect($price->source)->toBe(PriceSource::Base)
        ->and($price->considered)->toContain(['source' => 'CUSTOMER_LIST', 'list_id' => $scenario->lists['L'], 'item_id' => $scenario->items['L#0'], 'reason' => 'unit_not_convertible']);
});

it('prices a row in pieces from an item per package, ladder step and price both converted', function () {
    $scenario = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 1500, 'conversions' => ['förp' => '1/10']]],
        'lists' => ['L' => ['items' => [
            ['article' => 'A1', 'price_minor' => 1400],
            ['article' => 'A1', 'unit' => 'förp', 'min_quantity_milli' => 2000, 'price_minor' => 12345],
        ]]],
        'customer' => ['list' => 'L'],
    ]);

    $small = p2One($scenario, ['date' => '2026-03-15'], ['quantity_milli' => 19000]);
    $large = p2One($scenario, ['date' => '2026-03-15'], ['quantity_milli' => 20000]);

    expect($small->unitPriceMinor)->toBe(1400)
        // 123,45 kr per package of ten: 12,345 kr a piece, half up once.
        ->and($large->unitPriceMinor)->toBe(1235)
        ->and($large->explanation['item_id'])->toBe($scenario->items['L#1'])
        ->and($large->explanation['tier_min_milli'])->toBe(2000);
});

it('breaks ties between items on the same step by the latest start, then the lowest id', function () {
    $scenario = p2Scenario([
        'lists' => ['L' => ['items' => [
            ['article' => 'A1', 'price_minor' => 9000, 'valid_from' => '2026-01-01'],
            ['article' => 'A1', 'price_minor' => 8000, 'valid_from' => '2026-02-01'],
        ]]],
        'customer' => ['list' => 'L'],
    ]);

    expect(p2One($scenario, ['date' => '2026-03-15'])->unitPriceMinor)->toBe(8000)
        ->and(p2One($scenario, ['date' => '2026-01-15'])->unitPriceMinor)->toBe(9000);
});

it('lets the lower id win between equally cheap campaigns starting the same day', function () {
    $scenario = p2Scenario([
        'lists' => [
            'K1' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-31', 'items' => [['article' => 'A1', 'price_minor' => 7000]]],
            'K2' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-31', 'items' => [['article' => 'A1', 'price_minor' => 7000]]],
        ],
    ]);

    expect(p2One($scenario, ['date' => '2026-05-15'])->explanation['list_id'])->toBe($scenario->lists['K1']);
});

it('prices nothing from derived lists that are based on each other in a loop', function () {
    $scenario = p2Scenario([
        'lists' => [
            'A' => ['derived_percent_bp' => -1000, 'based_on' => 'B'],
            'B' => ['derived_percent_bp' => -1000, 'based_on' => 'A'],
        ],
        'customer' => ['list' => 'A'],
    ]);

    $price = p2One($scenario, ['date' => '2026-03-15']);

    expect($price->source)->toBe(PriceSource::Base)
        ->and($price->unitPriceMinor)->toBe(10000)
        ->and(collect($price->considered)->pluck('reason')->all())->toContain('derived_loop');
});

it('derives a list from the base price with its rounding', function () {
    $scenario = p2Scenario([
        'lists' => ['L' => ['derived_percent_bp' => -1234, 'rounding' => 'WHOLE']],
        'customer' => ['list' => 'L'],
    ]);

    $price = p2One($scenario, ['date' => '2026-03-15']);

    // 100 kr − 12,34 % = 87,66 kr → 88 kr.
    expect($price->unitPriceMinor)->toBe(8800)
        ->and($price->explanation)->toMatchArray(['source' => 'CUSTOMER_LIST', 'derived' => true, 'base_minor' => 10000]);
});

it('never stacks discounts: a manual discount replaces the row discount and keeps the agreed one in the price', function () {
    $scenario = p2Scenario([
        'lists' => ['L' => ['items' => [['article' => 'A1', 'discount_bp' => 1000]]]],
        'customer' => ['list' => 'L'],
    ]);

    $plain = p2One($scenario, ['date' => '2026-03-15']);
    $manual = p2One($scenario, ['date' => '2026-03-15'], ['manual_discount_bp' => 500]);

    expect([$plain->unitPriceMinor, $plain->discountBp, $plain->manualDiscount])->toBe([10000, 1000, false])
        ->and([$manual->unitPriceMinor, $manual->discountBp, $manual->manualDiscount])->toBe([9000, 500, true])
        ->and($manual->source)->toBe(PriceSource::CustomerList)
        ->and($manual->explanation)->toMatchArray(['folded_discount_bp' => 1000, 'manual_discount_bp' => 500]);
});

it('reports an article of another company as unknown and never prices it', function () {
    $scenario = p2Scenario([]);
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = PricingScenario::build(['articles' => ['B1' => ['price_ore' => 1]]], $otherOwner, $other);
    CurrentCompany::set($scenario->company);

    $price = app(PriceResolver::class)->resolve(
        new PriceContext((int) $scenario->company->id, '2026-03-15'),
        new PriceRequest('r', $foreign->articles['B1']),
    );

    expect($price->hasPrice())->toBeFalse()
        ->and($price->warnings)->toBe([ResolvedPrice::WARNING_UNKNOWN_ARTICLE, ResolvedPrice::WARNING_NO_PRICE]);
});

it('ignores a price list or unit of another company that a customer or row points at', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = PricingScenario::build([
        'articles' => ['B1' => ['price_ore' => 1]],
        'lists' => ['THEIRS' => ['derived_percent_bp' => -9000]],
    ], $otherOwner, $other);
    $foreignUnit = $foreign->unitId('kg');

    $scenario = p2Scenario(['customer' => []]);
    Customer::query()->whereKey($scenario->customer->id)->update(['price_list_id' => $foreign->lists['THEIRS']]);

    $price = p2One($scenario, ['date' => '2026-03-15']);
    $foreignUnitRow = app(PriceResolver::class)->resolve($scenario->context(['date' => '2026-03-15']), new PriceRequest('r', $scenario->articles['A1'], unitId: $foreignUnit));

    expect($price->source)->toBe(PriceSource::Base)
        ->and($price->unitPriceMinor)->toBe(10000)
        ->and($price->considered)->toContain(['source' => 'CUSTOMER_LIST', 'list_id' => $foreign->lists['THEIRS'], 'reason' => 'not_valid'])
        ->and($foreignUnitRow->hasPrice())->toBeFalse()
        ->and($foreignUnitRow->warnings)->toContain(ResolvedPrice::WARNING_UNIT);
});

it('refuses a customer of another company', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = PricingScenario::build(['customer' => []], $otherOwner, $other);
    $scenario = p2Scenario([]);

    expect(fn () => app(PriceResolver::class)->resolve(
        new PriceContext((int) $scenario->company->id, '2026-03-15', customerId: $foreign->customer->id),
        new PriceRequest('r', $scenario->articles['A1']),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses to resolve outside the company of the context', function () {
    $scenario = p2Scenario([]);
    [, $other] = Bookkeeping::company();
    CurrentCompany::set($other);

    expect(fn () => app(PriceResolver::class)->resolve(
        new PriceContext((int) $scenario->company->id, '2026-03-15'),
        new PriceRequest('r', $scenario->articles['A1']),
    ))->toThrow(LogicException::class);
});

it('does not use an archived list item or an archived list', function () {
    $scenario = p2Scenario([
        'lists' => ['L' => ['items' => [['article' => 'A1', 'price_minor' => 100]]]],
        'customer' => ['list' => 'L'],
    ]);

    PriceListItem::query()->whereKey($scenario->items['L#0'])->update(['archived_at' => now()]);
    expect(p2One($scenario, ['date' => '2026-03-15'])->source)->toBe(PriceSource::Base);

    PriceListItem::query()->whereKey($scenario->items['L#0'])->update(['archived_at' => null]);
    PriceList::query()->whereKey($scenario->lists['L'])->update(['archived_at' => now()]);
    expect(p2One($scenario, ['date' => '2026-03-15'])->source)->toBe(PriceSource::Base);
});

it('does not use a customer list in another currency', function () {
    $scenario = p2Scenario([
        'lists' => ['EUR' => ['currency' => 'EUR', 'items' => [['article' => 'A1', 'price_minor' => 1]]]],
        'customer' => ['list' => 'EUR'],
    ]);

    $price = p2One($scenario, ['date' => '2026-03-15']);

    expect($price->source)->toBe(PriceSource::Base)
        ->and($price->considered)->toContain(['source' => 'CUSTOMER_LIST', 'list_id' => $scenario->lists['EUR'], 'reason' => 'currency_mismatch']);
});

it('validates its inputs', function () {
    expect(fn () => new PriceContext(1, '2026-03-15', 'kronor'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PriceRequest('r', 1, manualPriceMinor: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PriceRequest('r', 1, manualDiscountBp: 10001))->toThrow(InvalidArgumentException::class)
        ->and((new PriceContext(1, '2026-03-15', 'eur'))->currency)->toBe('EUR');
});

it('fetches no exchange rate for a document the currency list prices in full', function () {
    $scenario = p2Scenario([
        'articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 20000]],
        'lists' => [
            'EURO' => ['currency' => 'EUR', 'default' => true, 'items' => [['article' => 'A1', 'price_minor' => 900], ['article' => 'A2', 'price_minor' => 1800]]],
            'KUND' => ['currency' => 'EUR', 'items' => [['article' => 'A2', 'discount_bp' => 1000]]],
        ],
        'customer' => ['list' => 'KUND'],
        'rate' => ['currency' => 'EUR', 'date' => '2026-03-13', 'micros' => 11_000_000],
    ]);

    $prices = $scenario->resolve(['date' => '2026-03-15', 'currency' => 'EUR'], [
        $scenario->request(['key' => 'a', 'article' => 'A1']),
        $scenario->request(['key' => 'b', 'article' => 'A2']),
    ]);

    expect([$prices['a']->unitPriceMinor, $prices['b']->unitPriceMinor, $prices['b']->discountBp])->toBe([900, 1800, 1000])
        ->and(app(RiksbankRates::class)->calls)->toBe(0);
});

it('follows a chain of derived lists for at most five lists', function (int $length, int $expected, PriceSource $source) {
    $lists = [];

    foreach (range(1, $length) as $i) {
        $lists["L{$i}"] = ['derived_percent_bp' => -1000, ...($i < $length ? ['based_on' => 'L'.($i + 1)] : [])];
    }

    $scenario = p2Scenario(['lists' => $lists, 'customer' => ['list' => 'L1']]);
    $price = p2One($scenario, ['date' => '2026-03-15']);

    expect($price->unitPriceMinor)->toBe($expected)
        ->and($price->source)->toBe($source);

    if ($length > PriceBook::MAX_DERIVED_DEPTH) {
        expect($price->considered)->toContain(['source' => 'CUSTOMER_LIST', 'list_id' => $scenario->lists['L6'], 'reason' => 'derived_too_deep']);
    }
})->with([
    // 100 kr less 10 % five times: 59,049 → 59,05 kr.
    'five lists' => [5, 5905, PriceSource::CustomerList],
    'six lists' => [6, 10000, PriceSource::Base],
]);

it('names a loop by the role of the list it was found in', function () {
    $scenario = p2Scenario([
        'lists' => [
            'K' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31', 'derived_percent_bp' => -1000, 'based_on' => 'X'],
            'X' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31', 'derived_percent_bp' => -1000, 'based_on' => 'K'],
        ],
    ]);

    $price = p2One($scenario, ['date' => '2026-03-15']);

    expect($price->source)->toBe(PriceSource::Base)
        ->and(collect($price->considered)->where('reason', 'derived_loop')->pluck('source')->unique()->values()->all())->toBe(['CAMPAIGN']);
});

it('reads the document date as a Swedish day, also when given in UTC', function () {
    $scenario = p2Scenario([
        'lists' => ['MAJ' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-31', 'items' => [['article' => 'A1', 'price_minor' => 5000]]]],
    ]);
    $price = fn (CarbonImmutable|string $date) => app(PriceResolver::class)
        ->resolve(new PriceContext((int) $scenario->company->id, $date), new PriceRequest('r', $scenario->articles['A1']))->unitPriceMinor;

    // 23:00 UTC on 31 May is 01:00 on 1 June in Stockholm: the campaign has ended.
    expect($price(CarbonImmutable::parse('2026-05-31 23:00:00', 'UTC')))->toBe(10000)
        ->and($price('2026-05-31T23:00:00Z'))->toBe(10000)
        ->and($price(CarbonImmutable::parse('2026-05-31 21:00:00', 'UTC')))->toBe(5000)
        ->and($price('2026-05-31'))->toBe(5000)
        ->and((new PriceContext(1, CarbonImmutable::parse('2026-05-31 23:00:00', 'UTC')))->date->toDateString())->toBe('2026-06-01');
});

it('refuses two rows with the same key', function () {
    $scenario = p2Scenario([]);

    expect(fn () => app(PriceResolver::class)->resolveMany($scenario->context(['date' => '2026-03-15']), [
        new PriceRequest('r', $scenario->articles['A1']),
        new PriceRequest('r', $scenario->articles['A1'], 2000),
    ]))->toThrow(InvalidArgumentException::class);
});
