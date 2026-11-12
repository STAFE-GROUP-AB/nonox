<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Production;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\ArticleValuation;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\ProductionOrderIssue;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Takes a production order's components out of stock and puts them back
 * (ADR-071 §8), through the stock ledger as PRODUCTION_ISSUE, and records
 * each issue with the valuation cost that left the stock.
 *
 * The cost of an issue is what the article's value under the company's
 * method (FIFO or weighted average, ADR-039) loses: its cost value on the
 * day before the issue minus after it, from the movement ledger. So FIFO
 * takes the oldest receipts' costs and the average takes the average, and
 * the stock and the work in progress always add up to what came in. Where
 * negative stock is allowed and more is issued than is on hand, the part
 * not on hand is costed at the article's purchase price.
 *
 * Each article is taken from its default location first, then from the
 * other locations that hold it, in code order. An issue is never dated
 * before a later movement of the component, which would make FIFO value
 * the same receipts twice. Only stock articles are
 * issued; the caller locks the order. Runs inside the company's
 * transaction.
 */
final class ComponentIssues
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Issues what each component still needs (or up to $limit per
     * component id) on $date.
     *
     * @param  iterable<ProductionOrderComponent>  $components  with the component article loaded
     * @param  ?array<int, int>  $limit  component id => at most this many milli
     * @return int the value issued, öre
     */
    public function issue(Company $company, User $user, ProductionOrder $order, iterable $components, CarbonImmutable $date, ?array $limit = null): int
    {
        $wanted = [];

        foreach ($components as $component) {
            /** @var ?Article $article */
            $article = $component->component;

            if ($article === null || ! $article->is_stock_item) {
                continue;
            }

            $milli = $component->remainingMilli();

            if ($limit !== null) {
                $milli = min($milli, max(0, $limit[$component->id] ?? 0));
            }

            if ($milli > 0) {
                $wanted[] = [$component, $article, $milli];
            }
        }

        if ($wanted === []) {
            return 0;
        }

        $articleIds = array_values(array_unique(array_map(fn (array $item): int => $item[1]->id, $wanted)));
        $this->refuseBeforeLaterMovements(array_map(fn (array $item): Article => $item[1], $wanted), $date);
        $plan = $this->locations($company, $wanted, $articleIds);
        $before = $this->ledgerFor($articleIds, $date);
        $lines = [];

        foreach ($plan as $taken) {
            $lines[] = ['article_id' => $taken['article']->id, 'location_id' => $taken['location_id'], 'quantity_milli' => -$taken['milli']];
        }

        $movements = $this->ledger->post($company, $user, MovementType::ProductionIssue, $lines, $date, $order, $order->reference());
        $method = InventorySettings::method($company);
        $running = $before;
        $total = 0;
        $sums = [];

        foreach ($plan as $index => $taken) {
            /** @var Article $article */
            $article = $taken['article'];
            $history = $running[$article->id] ?? [];
            $costBefore = ArticleValuation::of($history, $method, null, $article->purchase_price_ore ?? 0)->costValueOre;
            $onHand = array_sum(array_column($history, 'quantity_milli'));
            $history[] = ['quantity_milli' => -$taken['milli'], 'unit_cost_ore' => null, 'type' => MovementType::ProductionIssue->value];
            $running[$article->id] = $history;
            $costAfter = ArticleValuation::of($history, $method, null, $article->purchase_price_ore ?? 0)->costValueOre;
            $notOnHand = max(0, $taken['milli'] - max(0, $onHand));
            $value = $costBefore - $costAfter + Quantity::value($notOnHand, $article->purchase_price_ore ?? 0);
            /** @var StockMovement $movement */
            $movement = $movements[$index];

            ProductionOrderIssue::create([
                'production_order_id' => $order->id,
                'production_order_component_id' => $taken['component']->id,
                'article_id' => $article->id,
                'location_id' => $taken['location_id'],
                'stock_movement_id' => $movement->id,
                'quantity_milli' => $taken['milli'],
                'value_ore' => $value,
                'issued_on' => $date->toDateString(),
                'created_by' => $user->id,
            ]);

            $sums[$taken['component']->id] ??= [$taken['component'], 0, 0];
            $sums[$taken['component']->id][1] += $taken['milli'];
            $sums[$taken['component']->id][2] += $value;
            $total += $value;
        }

        foreach ($sums as [$component, $milli, $value]) {
            $component->forceFill([
                'issued_milli' => $component->issued_milli + $milli,
                'issued_value_ore' => $component->issued_value_ore + $value,
            ])->save();
        }

        return $total;
    }

    /**
     * Puts everything the order has taken back where it came from, at the
     * cost it left with: a cancelled order (§8). Each location's net issue
     * is returned as a PRODUCTION_ISSUE movement in at the issued cost per
     * stock unit, with the rounding on its last thousandth so exactly the
     * issued value comes back (to the default or first active location when
     * the original one is switched off), and recorded as a negative issue.
     *
     * @return int the value returned, öre
     */
    public function returnAll(Company $company, User $user, ProductionOrder $order, CarbonImmutable $date): int
    {
        $net = [];

        foreach (ProductionOrderIssue::query()->where('production_order_id', $order->id)->orderBy('id')->get() as $issue) {
            $key = "{$issue->production_order_component_id}:{$issue->location_id}";
            $net[$key] ??= ['component_id' => $issue->production_order_component_id, 'article_id' => $issue->article_id, 'location_id' => $issue->location_id, 'milli' => 0, 'value' => 0];
            $net[$key]['milli'] += $issue->quantity_milli;
            $net[$key]['value'] += $issue->value_ore;
        }

        $net = array_values(array_filter($net, fn (array $item): bool => $item['milli'] > 0));

        if ($net === []) {
            return 0;
        }

        $articles = Article::query()->whereKey(array_values(array_unique(array_column($net, 'article_id'))))->get();
        $this->refuseBeforeLaterMovements(array_values($articles->all()), $date);
        $active = StockLocation::query()->where('is_active', true)->orderBy('sort_key')->pluck('id')->all();
        $defaults = $articles->pluck('default_location_id', 'id');
        $lines = [];
        $owner = [];

        foreach ($net as $index => $item) {
            $location = $item['location_id'];

            // A location switched off since the issue cannot receive goods: the article's default, else the first active one.
            if (! in_array($location, $active, true)) {
                $default = $defaults[$item['article_id']] ?? null;
                $location = $default !== null && in_array((int) $default, $active, true) ? (int) $default : ($active[0] ?? $location);
                $net[$index]['location_id'] = $location;
            }

            foreach (self::exactLines(max(0, $item['value']), $item['milli']) as [$milli, $unitCost]) {
                $lines[] = ['article_id' => $item['article_id'], 'location_id' => $location, 'quantity_milli' => $milli, 'unit_cost_ore' => $unitCost];
                $owner[] = $index;
            }
        }

        $movements = $this->ledger->post($company, $user, MovementType::ProductionIssue, $lines, $date, $order, $order->reference());
        $first = [];

        foreach ($owner as $position => $index) {
            $first[$index] ??= $movements[$position];
        }

        $components = ProductionOrderComponent::query()->whereKey(array_column($net, 'component_id'))->get()->keyBy('id');
        $total = 0;

        foreach ($net as $index => $item) {
            /** @var StockMovement $movement */
            $movement = $first[$index];

            ProductionOrderIssue::create([
                'production_order_id' => $order->id,
                'production_order_component_id' => $item['component_id'],
                'article_id' => $item['article_id'],
                'location_id' => $item['location_id'],
                'stock_movement_id' => $movement->id,
                'quantity_milli' => -$item['milli'],
                'value_ore' => -$item['value'],
                'issued_on' => $date->toDateString(),
                'created_by' => $user->id,
            ]);

            $component = $components->get($item['component_id']);

            if ($component instanceof ProductionOrderComponent) {
                $component->forceFill([
                    'issued_milli' => $component->issued_milli - $item['milli'],
                    'issued_value_ore' => $component->issued_value_ore - $item['value'],
                ])->save();
            }

            $total += $item['value'];
        }

        return $total;
    }

    /**
     * Movements that bring back exactly $valueOre for $milli: one line at the
     * cost per stock unit when that adds up (rounded half up), otherwise that
     * line less one thousandth and the remainder on the last thousandth, so
     * the stock gets back exactly what left it.
     *
     * @return list<array{0: int, 1: int}> milli and cost per stock unit
     */
    private static function exactLines(int $valueOre, int $milli): array
    {
        $unitCost = BigDecimal::of($valueOre)->multipliedBy(Quantity::SCALE)->dividedBy($milli, 0, RoundingMode::HalfUp)->toInt();

        if ($milli === 1 || Quantity::value($milli, $unitCost) === $valueOre) {
            return [[$milli, $unitCost]];
        }

        $rest = $valueOre - Quantity::value($milli - 1, $unitCost);

        return [[$milli - 1, $unitCost], [1, max(0, $rest) * Quantity::SCALE]];
    }

    /**
     * The value of an issue is read from the ledger up to its day, which is
     * right only when nothing has moved the article after that day: FIFO would
     * otherwise value the same layers twice. An issue is therefore never dated
     * before the component's latest movement.
     *
     * @param  list<Article>  $articles
     */
    private function refuseBeforeLaterMovements(array $articles, CarbonImmutable $date): void
    {
        $latest = StockMovement::query()->whereIn('article_id', array_map(fn (Article $article): int => $article->id, $articles))->whereDate('occurred_on', '>', $date->toDateString())
            ->groupBy('article_id')->selectRaw('article_id, max(occurred_on) as last_on')->pluck('last_on', 'article_id');

        foreach ($articles as $article) {
            $last = $latest[$article->id] ?? null;

            if ($last !== null) {
                throw ValidationException::withMessages(['date' => __('Komponenter kan inte tas ut eller läggas tillbaka med ett datum före :date, då :number :name senast ändrades i lagret. Välj :date eller senare.', [
                    'date' => substr((string) $last, 0, 10),
                    'number' => $article->number,
                    'name' => $article->name,
                ])]);
            }
        }
    }

    /**
     * Where each component is taken from: the article's default location,
     * then the others that hold it. What is not on hand anywhere is taken
     * from the default (or first) location when negative stock is allowed,
     * and refused otherwise.
     *
     * @param  list<array{0: ProductionOrderComponent, 1: Article, 2: int}>  $wanted
     * @param  list<int>  $articleIds
     * @return list<array{component: ProductionOrderComponent, article: Article, location_id: int, milli: int}>
     */
    private function locations(Company $company, array $wanted, array $articleIds): array
    {
        $codes = StockLocation::query()->pluck('full_code', 'id');
        $levels = [];

        foreach (StockLevel::query()->whereIn('article_id', $articleIds)->where('quantity_milli', '>', 0)->get(['article_id', 'location_id', 'quantity_milli']) as $level) {
            $levels[$level->article_id][$level->location_id] = $level->quantity_milli;
        }

        $fallback = StockLocation::query()->where('is_active', true)->orderBy('sort_key')->value('id');
        $allowNegative = InventorySettings::allowsNegative($company);
        $plan = [];

        foreach ($wanted as [$component, $article, $milli]) {
            $available = $levels[$article->id] ?? [];
            uksort($available, function (int $a, int $b) use ($article, $codes): int {
                if ($a === $article->default_location_id || $b === $article->default_location_id) {
                    return $a === $article->default_location_id ? -1 : 1;
                }

                return strcmp((string) ($codes[$a] ?? ''), (string) ($codes[$b] ?? ''));
            });
            $left = $milli;

            foreach ($available as $locationId => $quantity) {
                $take = min($left, $quantity);

                if ($take <= 0) {
                    continue;
                }

                $plan[] = ['component' => $component, 'article' => $article, 'location_id' => $locationId, 'milli' => $take];
                $levels[$article->id][$locationId] -= $take;
                $left -= $take;

                if ($left === 0) {
                    break;
                }
            }

            if ($left === 0) {
                continue;
            }

            $location = $article->default_location_id ?? $fallback;

            if (! $allowNegative || $location === null) {
                throw ValidationException::withMessages(['components' => __('Det finns bara :available :unit av :number :name i lager, och ordern behöver :needed :unit. Ta emot mer eller ändra antalet.', [
                    'available' => Quantity::format($milli - $left),
                    'needed' => Quantity::format($milli),
                    'unit' => $article->stockUnitCode(),
                    'number' => $article->number,
                    'name' => $article->name,
                ])]);
            }

            $plan[] = ['component' => $component, 'article' => $article, 'location_id' => (int) $location, 'milli' => $left];
        }

        return $plan;
    }

    /**
     * Each article's movements up to and including the day, in the order the
     * valuation reads them.
     *
     * @param  list<int>  $articleIds
     * @return array<int, list<array{quantity_milli: int, unit_cost_ore: ?int, type: string}>>
     */
    private function ledgerFor(array $articleIds, CarbonImmutable $date): array
    {
        $result = [];

        foreach (StockMovement::query()->whereIn('article_id', $articleIds)->whereDate('occurred_on', '<=', $date->toDateString())
            ->orderBy('article_id')->orderBy('occurred_on')->orderBy('id')
            ->get(['article_id', 'quantity_milli', 'unit_cost_ore', 'type']) as $movement) {
            $result[$movement->article_id][] = ['quantity_milli' => $movement->quantity_milli, 'unit_cost_ore' => $movement->unit_cost_ore, 'type' => $movement->type->value];
        }

        return $result;
    }
}
