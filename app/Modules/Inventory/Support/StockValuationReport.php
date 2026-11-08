<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\ArticleValuation;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The stock on a day, valued (underlag för varulagret vid bokslut): per
 * article the quantity, the cost by the company's method, the net
 * realisable value where one is set, and the value after lägsta värdets
 * princip; per location the quantity and its share of the value. Built
 * from the movement ledger, so it can be run for any past day. Three
 * queries whatever the size of the stock.
 */
final class StockValuationReport
{
    /**
     * @param  list<array{article_id: int, number: string, name: string, unit: string, stock_class: string, quantity_milli: int, unit_cost_ore: ?int, cost_value_ore: int, net_realisable_unit_ore: ?int, value_ore: int, written_down: bool, locations: list<array{location_id: int, code: string, quantity_milli: int, value_ore: int}>}>  $lines
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly ValuationMethod $method,
        public readonly array $lines,
        public readonly int $costValueOre,
        public readonly int $valueOre,
    ) {}

    public static function at(Company $company, CarbonImmutable $date, ?ValuationMethod $method = null): self
    {
        $method ??= InventorySettings::method($company);
        $defaultClass = InventorySettings::defaultClass($company);

        return CurrentCompany::run($company, function () use ($date, $method, $defaultClass): self {
            $movements = StockMovement::query()
                ->whereDate('occurred_on', '<=', $date->toDateString())
                ->orderBy('article_id')->orderBy('occurred_on')->orderBy('id')
                ->get(['id', 'article_id', 'location_id', 'quantity_milli', 'unit_cost_ore', 'type'])
                ->groupBy('article_id');

            $articles = Article::query()->whereKey($movements->keys()->all())->orderBy('number')
                ->get(['id', 'number', 'name', 'unit', 'purchase_price_ore', 'net_realisable_value_ore', 'stock_class']);
            $codes = StockLocation::query()->pluck('full_code', 'id');
            $lines = [];
            $cost = 0;
            $value = 0;

            foreach ($articles as $article) {
                $rows = $movements->get($article->id, collect());
                $valuation = ArticleValuation::of(
                    array_values($rows->map(fn (StockMovement $movement): array => ['quantity_milli' => $movement->quantity_milli, 'unit_cost_ore' => $movement->unit_cost_ore, 'type' => $movement->type->value])->all()),
                    $method,
                    $article->net_realisable_value_ore,
                    $article->purchase_price_ore ?? 0,
                );

                $perLocation = [];

                foreach ($rows as $movement) {
                    $perLocation[$movement->location_id] = ($perLocation[$movement->location_id] ?? 0) + $movement->quantity_milli;
                }

                $perLocation = array_filter($perLocation, fn (int $quantity): bool => $quantity !== 0);

                if ($perLocation === []) {
                    continue;
                }

                $lines[] = [
                    'article_id' => $article->id,
                    'number' => $article->number,
                    'name' => $article->name,
                    'unit' => $article->unit,
                    'stock_class' => ($article->stock_class ?? $defaultClass)->value,
                    'quantity_milli' => $valuation->quantityMilli,
                    'unit_cost_ore' => $valuation->unitCostOre(),
                    'cost_value_ore' => $valuation->costValueOre,
                    'net_realisable_unit_ore' => $valuation->netRealisableUnitOre,
                    'value_ore' => $valuation->valueOre,
                    'written_down' => $valuation->writtenDown(),
                    'locations' => self::split($perLocation, $valuation, $codes->all()),
                ];
                $cost += $valuation->costValueOre;
                $value += $valuation->valueOre;
            }

            return new self($date, $method, $lines, $cost, $value);
        });
    }

    /**
     * The value spread over the locations by quantity; the rounding rest goes
     * to the last location so the parts add up to the article's value.
     *
     * @param  array<int, int>  $perLocation
     * @param  array<int|string, mixed>  $codes
     * @return list<array{location_id: int, code: string, quantity_milli: int, value_ore: int}>
     */
    private static function split(array $perLocation, ArticleValuation $valuation, array $codes): array
    {
        $positive = array_sum(array_filter($perLocation, fn (int $quantity): bool => $quantity > 0));
        uksort($perLocation, fn (int $a, int $b): int => strcmp((string) ($codes[$a] ?? ''), (string) ($codes[$b] ?? '')));
        $result = [];
        $left = $valuation->valueOre;
        $ids = array_keys($perLocation);
        $lastPositive = null;

        foreach ($ids as $id) {
            $lastPositive = $perLocation[$id] > 0 ? $id : $lastPositive;
        }

        foreach ($ids as $id) {
            $quantity = $perLocation[$id];
            $share = 0;

            if ($quantity > 0 && $positive > 0) {
                $share = $id === $lastPositive ? $left : intdiv($valuation->valueOre * $quantity, $positive);
                $left -= $share;
            }

            $result[] = ['location_id' => $id, 'code' => (string) ($codes[$id] ?? ''), 'quantity_milli' => $quantity, 'value_ore' => $share];
        }

        return $result;
    }
}
