<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\ResolvedPrice;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §5 (P2): properties over random synthetic worlds (seeded, so a
 * failure can be replayed): the same inputs always give the same prices in
 * any row order, no price or discount is ever out of range, and a manual
 * price is never replaced.
 */

/** @return array<string, mixed> a random but reproducible pricing world */
function p2RandomWorld(int $seed): array
{
    mt_srand($seed);
    $articles = [];

    foreach (range(1, 8) as $i) {
        $articles["A{$i}"] = ['price_ore' => mt_rand(0, 500_000), 'vat_rate' => [2500, 1200, 600, 0][mt_rand(0, 3)]];
    }

    $item = function () use ($articles): array {
        $base = ['article' => array_rand($articles), 'min_quantity_milli' => [0, 0, 5000, 10000, 25000][mt_rand(0, 4)]];

        return mt_rand(0, 2) === 0 ? [...$base, 'discount_bp' => mt_rand(0, 10000)] : [...$base, 'price_minor' => mt_rand(0, 400_000)];
    };

    // One item per article and step: the database refuses two live items on the same key.
    $items = fn (int $count): array => collect(range(1, $count))->map(fn () => $item())
        ->unique(fn (array $row): string => "{$row['article']}:{$row['min_quantity_milli']}")->values()->all();
    $roundings = ['NONE', 'WHOLE', 'HALF', 'TEN_ORE_9', 'ENDS_IN_9', 'TENS'];

    return [
        'articles' => $articles,
        'lists' => [
            'BAS' => ['default' => true, 'rounding' => $roundings[mt_rand(0, 5)], 'items' => $items(4)],
            'ATER' => ['derived_percent_bp' => mt_rand(-15000, 5000), 'rounding' => $roundings[mt_rand(0, 5)], 'items' => $items(6)],
            'KONS' => ['prices_include_vat' => true, 'based_on' => 'ATER', 'derived_percent_bp' => mt_rand(-3000, 3000), 'rounding' => $roundings[mt_rand(0, 5)], 'items' => $items(3)],
            'K1' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31', 'items' => $items(5)],
            'K2' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-10', 'valid_to' => '2026-03-20', 'groups' => ['G1'], 'items' => $items(5)],
        ],
        'groups' => ['G1' => ['list' => 'ATER']],
        'customer' => ['group' => 'G1', 'list' => mt_rand(0, 1) === 1 ? 'KONS' : null],
        'agreements' => [
            'PA' => ['valid_from' => '2026-01-01', 'allow_campaigns' => mt_rand(0, 1) === 1, 'items' => [
                ...$items(4),
                ['discount_bp' => mt_rand(0, 3000)],
                ['discount_bp' => mt_rand(0, 3000), 'base_list' => 'ATER', 'article' => array_rand($articles)],
            ]],
        ],
    ];
}

/** @return list<array<string, mixed>> */
function p2RandomRows(int $seed, array $articles): array
{
    mt_srand($seed * 7919);

    return array_map(fn (int $i): array => array_filter([
        'key' => "r{$i}",
        'article' => array_rand($articles),
        'quantity_milli' => mt_rand(0, 60_000),
        'manual_price_minor' => mt_rand(0, 5) === 0 ? mt_rand(0, 300_000) : null,
        'manual_discount_bp' => mt_rand(0, 5) === 0 ? mt_rand(0, 10000) : null,
    ], fn (mixed $value): bool => $value !== null), range(1, 60));
}

/** @return array<string, mixed> */
function p2Comparable(ResolvedPrice $price): array
{
    return get_object_vars($price);
}

it('holds for random worlds', function (int $seed) {
    $fixture = p2RandomWorld($seed);
    $fixture['customer'] = array_filter($fixture['customer']);
    $scenario = PricingScenario::build($fixture);
    $rows = p2RandomRows($seed, $fixture['articles']);
    $context = ['date' => '2026-03-15'];

    $first = $scenario->resolve($context, array_map(fn (array $row) => $scenario->request($row), $rows));
    $again = $scenario->resolve($context, array_map(fn (array $row) => $scenario->request($row), array_reverse($rows)));

    foreach ($rows as $row) {
        $price = $first[$row['key']];

        // Deterministic, whatever the order of the rows.
        expect(p2Comparable($again[$row['key']]))->toEqual(p2Comparable($price));

        // A SEK document always has a price: at worst the base price.
        expect($price->hasPrice())->toBeTrue()
            ->and($price->unitPriceMinor)->toBeGreaterThanOrEqual(0)
            ->and($price->discountBp)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(10000)
            ->and($price->grossUnitPriceMinor ?? 0)->toBeGreaterThanOrEqual(0);

        if (isset($row['manual_price_minor'])) {
            expect($price->source)->toBe(PriceSource::Manual)
                ->and($price->unitPriceMinor)->toBe($row['manual_price_minor'])
                ->and($price->discountBp)->toBe($row['manual_discount_bp'] ?? 0);
        } else {
            expect($price->source)->not->toBe(PriceSource::Manual);
        }

        if (isset($row['manual_discount_bp'])) {
            expect($price->discountBp)->toBe($row['manual_discount_bp']);
        }

        expect(json_encode($price->explanation))->not->toContain('Lista')->not->toContain('Avtal')->not->toContain('Kunden');
    }
})->with([11, 42, 2026, 31337, 90210]);

it('re-pricing with the manual prices filled in from a first pass changes nothing', function () {
    $fixture = p2RandomWorld(7);
    $fixture['customer'] = array_filter($fixture['customer']);
    $scenario = PricingScenario::build($fixture);
    $rows = p2RandomRows(7, $fixture['articles']);
    $context = ['date' => '2026-03-15'];

    $first = $scenario->resolve($context, array_map(fn (array $row) => $scenario->request($row), $rows));
    $pinned = array_map(fn (array $row): array => [...$row, 'manual_price_minor' => $first[$row['key']]->unitPriceMinor, 'manual_discount_bp' => $first[$row['key']]->discountBp], $rows);
    $second = $scenario->resolve(['date' => '2026-03-25'], array_map(fn (array $row) => $scenario->request($row), $pinned));

    foreach ($rows as $row) {
        expect([$second[$row['key']]->unitPriceMinor, $second[$row['key']]->discountBp])
            ->toBe([$first[$row['key']]->unitPriceMinor, $first[$row['key']]->discountBp]);
    }
});
