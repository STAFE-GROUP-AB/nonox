<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Support;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Inventory\Support\LocationTree;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Avvikelser (ADR-073 §8, rule sheet RS-C8, fixture F-RS-3): between two
 * completed stock counts of the same place, per ingredient counted in
 * both: opening count, receipts, theoretical consumption from the till's
 * sales, waste by reason, staff meals, other recorded outs and the closing
 * count, and the unexplained variance
 *
 *     (opening + receipts − closing) − (theoretical + waste + staff meals + other outs)
 *
 * in thousandths, valued at the closing cost per unit of the valuation
 * method, and as a share of the theoretical consumption. COUNT movements
 * are the counts themselves and are left out; movements are those of the
 * counted place (a move in from elsewhere counts as a receipt, out as an
 * other out). With drawing on sale on, the drawn quantities are shown
 * beside the theoretical figure, and the closing count's COUNT difference
 * is the same variance. Waste dated in the period but registered after the
 * closing count is listed apart ("registrerad efter inventeringen").
 * Sorted by value, largest first. A fixed number of queries.
 */
final class VarianceReport
{
    private const array EMPTY = ['receipts' => 0, 'drawn' => 0, 'waste' => 0, 'by_reason' => [], 'staff' => 0, 'other' => 0, 'count' => 0];

    /**
     * @param  list<array{article_id: int, number: string, name: string, unit: string, stock_class: string, opening_milli: int, receipts_milli: int, theoretical_milli: int, drawn_milli: int, waste_milli: int, waste_by_reason: array<string, int>, staff_milli: int, other_milli: int, closing_milli: int, actual_milli: int, explained_milli: int, variance_milli: int, unit_cost_ore: ?int, variance_ore: ?int, waste_ore: ?int, share_bp: ?int, count_difference_milli: int}>  $lines
     * @param  array<int, int>  $withoutRecipe  dish article => portions sold without a recipe
     * @param  array<int, string>  $dishNames
     * @param  list<array{id: int, article: string, date: string, quantity_milli: int, type: string, excluded: bool}>  $late  movements "bokförd i efterhand": dated in the period but posted after the closing count (left out of the sums) or after a stock valuation of their date (counted, marked)
     */
    public function __construct(
        public readonly StockCount $opening,
        public readonly StockCount $closing,
        public readonly array $lines,
        public readonly array $withoutRecipe,
        public readonly array $dishNames,
        public readonly array $late,
        public readonly int $unlocatedRows = 0,
    ) {}

    public static function between(Company $company, StockCount $opening, StockCount $closing): self
    {
        return CurrentCompany::run($company, function () use ($company, $opening, $closing): self {
            if ($opening->status !== StockCount::COMPLETED || $closing->status !== StockCount::COMPLETED) {
                throw ValidationException::withMessages(['counts' => __('Välj två slutförda inventeringar.')]);
            }

            if (! $opening->counted_on->lessThan($closing->counted_on)) {
                throw ValidationException::withMessages(['counts' => __('Den första inventeringen måste vara gjord före den andra.')]);
            }

            if ($opening->location_id !== $closing->location_id) {
                throw ValidationException::withMessages(['counts' => __('Välj två inventeringar av samma lagerställe, så att de räknar samma varor.')]);
            }

            $from = $opening->counted_on;
            $to = $closing->counted_on;
            // On the count days themselves the time of the count decides (review P3.10).
            $openedAt = CarbonImmutable::parse($opening->completed_at ?? $opening->counted_on->endOfDay());
            $closedAt = CarbonImmutable::parse($closing->completed_at ?? $closing->counted_on->endOfDay());
            $scope = $closing->location_id === null ? null : LocationTree::subtreeIds(StockLocation::query()->findOrFail($closing->location_id));

            $counted = StockCountLine::query()->whereIn('stock_count_id', [$opening->id, $closing->id])->get(['stock_count_id', 'article_id', 'system_milli', 'counted_milli']);
            $openingQty = [];
            $closingQty = [];

            foreach ($counted as $line) {
                $quantity = $line->counted_milli ?? $line->system_milli;

                if ($line->stock_count_id === $opening->id) {
                    $openingQty[$line->article_id] = ($openingQty[$line->article_id] ?? 0) + $quantity;
                } else {
                    $closingQty[$line->article_id] = ($closingQty[$line->article_id] ?? 0) + $quantity;
                }
            }

            $articleIds = array_values(array_intersect(array_keys($openingQty), array_keys($closingQty)));
            $articles = Article::query()->whereKey($articleIds)->get(['id', 'number', 'name', 'unit', 'stock_class'])->keyBy('id');

            $movements = StockMovement::query()
                ->whereIn('article_id', $articleIds)
                ->when($scope !== null, fn ($query) => $query->whereIn('location_id', $scope))
                ->whereDate('occurred_on', '>=', $from->toDateString())
                ->whereDate('occurred_on', '<=', $to->toDateString())
                ->with('article:id,name')
                ->orderBy('occurred_on')->orderBy('id')
                ->get(['id', 'article_id', 'quantity_milli', 'type', 'reason', 'source_type', 'source_id', 'occurred_on', 'created_at']);
            $valuedUntil = StockValuation::query()->whereDate('valuation_date', '>=', $from->toDateString())->get(['valuation_date', 'created_at']);

            $wasteType = (new WasteEntry)->getMorphClass();
            $mealType = (new StaffMeal)->getMorphClass();
            $countType = (new StockCount)->getMorphClass();
            $sums = [];

            $late = [];

            foreach ($movements as $movement) {
                if ($movement->type !== MovementType::Count) {
                    // Before the opening count was done, it is in the opening count.
                    if ($movement->created_at->lessThanOrEqualTo($openedAt) && $movement->occurred_on->equalTo($from)) {
                        continue;
                    }

                    // Posted after the closing count was done but dated in the period: the count could not see it (P3.7).
                    if ($movement->created_at->greaterThan($closedAt)) {
                        $late[] = self::late($movement, true);

                        continue;
                    }

                    if ($valuedUntil->contains(fn (StockValuation $valuation): bool => $valuation->valuation_date->greaterThanOrEqualTo($movement->occurred_on) && $valuation->created_at->lessThan($movement->created_at))) {
                        $late[] = self::late($movement, false);
                    }
                } elseif ($movement->source_type !== $countType || $movement->source_id !== (string) $closing->id) {
                    continue;
                }

                $sums[$movement->article_id] = self::add($sums[$movement->article_id] ?? self::EMPTY, $movement, $closing, $wasteType, $mealType, $countType);
            }

            $theory = TheoreticalConsumption::between($company, $from, $to, locationIds: $scope, after: $openedAt, until: $closedAt);
            $costs = UnitCosts::at($company, $to);
            $defaultClass = InventorySettings::defaultClass($company);
            $lines = [];

            foreach ($articleIds as $articleId) {
                $article = $articles->get($articleId);

                if (! $article instanceof Article) {
                    continue;
                }

                $sum = $sums[$articleId] ?? self::EMPTY;
                $theoretical = $theory->ingredients[$articleId] ?? 0;
                $actual = $openingQty[$articleId] + $sum['receipts'] - $closingQty[$articleId];
                $explained = $theoretical + $sum['waste'] + $sum['staff'] + $sum['other'];
                $variance = $actual - $explained;
                $unit = $costs->of($articleId)['ore'];

                $lines[] = [
                    'article_id' => $articleId,
                    'number' => $article->number,
                    'name' => $article->name,
                    'unit' => $article->unit,
                    'stock_class' => ($article->stock_class ?? $defaultClass)->value,
                    'opening_milli' => $openingQty[$articleId],
                    'receipts_milli' => $sum['receipts'],
                    'theoretical_milli' => $theoretical,
                    'drawn_milli' => $sum['drawn'],
                    'waste_milli' => $sum['waste'],
                    'waste_by_reason' => $sum['by_reason'],
                    'staff_milli' => $sum['staff'],
                    'other_milli' => $sum['other'],
                    'closing_milli' => $closingQty[$articleId],
                    'actual_milli' => $actual,
                    'explained_milli' => $explained,
                    'variance_milli' => $variance,
                    'unit_cost_ore' => $unit,
                    'variance_ore' => $unit === null ? null : Quantity::value($variance, $unit),
                    'waste_ore' => $unit === null ? null : Quantity::value($sum['waste'], $unit),
                    'share_bp' => StructureMath::share($variance, $theoretical),
                    'count_difference_milli' => $sum['count'],
                ];
            }

            usort($lines, fn (array $a, array $b): int => [abs($b['variance_ore'] ?? 0), $a['number']] <=> [abs($a['variance_ore'] ?? 0), $b['number']]);

            return new self($opening, $closing, $lines, $theory->withoutRecipe, $theory->dishNames, $late, $theory->unlocatedRows);
        });
    }

    /** @return array{id: int, article: string, date: string, quantity_milli: int, type: string, excluded: bool} */
    private static function late(StockMovement $movement, bool $excluded): array
    {
        return [
            'id' => $movement->id,
            'article' => (string) $movement->article?->name,
            'date' => $movement->occurred_on->toDateString(),
            'quantity_milli' => $movement->quantity_milli,
            'type' => $movement->type->label(),
            'excluded' => $excluded,
        ];
    }

    /**
     * One movement into the article's sums.
     *
     * @param  array{receipts: int, drawn: int, waste: int, by_reason: array<string, int>, staff: int, other: int, count: int}  $sum
     * @return array{receipts: int, drawn: int, waste: int, by_reason: array<string, int>, staff: int, other: int, count: int}
     */
    private static function add(array $sum, StockMovement $movement, StockCount $closing, string $wasteType, string $mealType, string $countType): array
    {
        $out = -$movement->quantity_milli;

        if ($movement->type === MovementType::Count) {
            if ($movement->source_type === $countType && $movement->source_id === (string) $closing->id) {
                $sum['count'] += $movement->quantity_milli;
            }

            return $sum;
        }

        if ($movement->type === MovementType::RecipeSale) {
            $sum['drawn'] += $out;

            return $sum;
        }

        if ($movement->source_type === $wasteType && in_array($movement->type, [MovementType::Scrap, MovementType::Return], true)) {
            $code = $movement->type === MovementType::Return ? 'RATTELSE' : (string) $movement->reason;
            $sum['by_reason'][$code] = ($sum['by_reason'][$code] ?? 0) + $out;
            $sum['waste'] += $out;

            return $sum;
        }

        if ($movement->type === MovementType::InternalUse || ($movement->type === MovementType::Return && $movement->source_type === $mealType)) {
            $sum['staff'] += $out;

            return $sum;
        }

        if ($movement->quantity_milli > 0) {
            $sum['receipts'] += $movement->quantity_milli;

            return $sum;
        }

        $sum['other'] += $out;

        return $sum;
    }
}
