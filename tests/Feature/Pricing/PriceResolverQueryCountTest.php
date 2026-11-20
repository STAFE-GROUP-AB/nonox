<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\PriceListItem;
use App\Support\Money\Money;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §5 "Batch design" (P2): pricing 300 rows costs the same number of
 * queries as pricing one, with every source in play — a customer with its
 * own list and a price group, an agreement, campaigns for everyone and for
 * the group, a default list and a derived list.
 */

it('prices 1 and 300 rows in the same number of queries', function () {
    $scenario = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000, 'conversions' => ['förp' => '1/10']]],
        'lists' => [
            'BAS' => ['default' => true],
            'ATER' => ['derived_percent_bp' => -1000, 'rounding' => 'WHOLE'],
            'EGEN' => ['based_on' => 'ATER', 'derived_percent_bp' => -500],
            'K1' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31'],
            'K2' => ['kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31', 'groups' => ['G1']],
        ],
        'groups' => ['G1' => ['list' => 'ATER']],
        'customer' => ['group' => 'G1', 'list' => 'EGEN'],
        'agreements' => ['PA' => ['valid_from' => '2026-01-01', 'allow_campaigns' => true, 'items' => [['discount_bp' => 300]]]],
    ]);

    $template = Article::query()->findOrFail($scenario->articles['A1']);
    $ids = [$template->id];

    foreach (range(2, 300) as $i) {
        $copy = $template->replicate();
        $copy->number = "A{$i}";
        $copy->price_ore = Money::ofOre(10000 + $i);
        $copy->save();
        $ids[] = $copy->id;
        ArticleUnits::saveManual($copy->load('stockUnit'), 'förp', BigRational::of('1/10'));
    }

    $items = [];

    foreach ($ids as $index => $id) {
        foreach (['BAS' => 9000, 'ATER' => 8500, 'K1' => 8800, 'K2' => 8700] as $list => $price) {
            $items[] = ['company_id' => $scenario->company->id, 'price_list_id' => $scenario->lists[$list], 'article_id' => $id, 'min_quantity_milli' => 0, 'price_minor' => $price + $index, 'created_at' => now(), 'updated_at' => now()];
            $items[] = ['company_id' => $scenario->company->id, 'price_list_id' => $scenario->lists[$list], 'article_id' => $id, 'min_quantity_milli' => 10000, 'price_minor' => $price - 500, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    foreach (array_chunk($items, 500) as $chunk) {
        PriceListItem::query()->insert($chunk);
    }

    $context = $scenario->context(['date' => '2026-03-15']);
    $pieces = $scenario->unitId('förp');
    $requests = fn (array $articleIds): array => array_map(
        fn (int $id, int $i): PriceRequest => new PriceRequest("r{$i}", $id, ($i % 3) * 7000, $i % 2 === 0 ? null : $pieces),
        $articleIds,
        array_keys($articleIds),
    );

    $count = function (array $requests) use ($context): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolved = app(PriceResolver::class)->resolveMany($context, $requests);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $resolved];
    };

    [$one, $resolvedOne] = $count($requests([$ids[0]]));
    [$many, $resolvedMany] = $count($requests($ids));

    expect($many)->toBe($one)
        ->and($one)->toBeLessThanOrEqual(10)
        ->and($resolvedMany)->toHaveCount(300)
        ->and($resolvedOne['r0']->source)->toBeIn([PriceSource::Agreement, PriceSource::Campaign])
        ->and(collect($resolvedMany)->every(fn ($price) => $price->hasPrice()))->toBeTrue();
});
