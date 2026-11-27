<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * What an article costs to sell, for the margin check (ADR-082 §9): the
 * costing price of ADR-071 §6 and ADR-073 §4, per whole stock unit, loaded
 * once for the articles asked for in a fixed number of queries and read in
 * memory. With Tillverkning on it is the manufacturing costing (the stock
 * valuation, the article's own bill of materials, the latest receipt, the
 * purchase price); with Lager on, the valuation, the latest receipt and the
 * purchase price; otherwise the article's purchase price. A management
 * figure that writes nothing, and it never reaches a customer.
 *
 * The cost of a row is its quantity in the row's unit converted to the
 * article's stock unit, rounded half up once to öre. A row whose unit does
 * not convert to the stock unit has no known cost.
 */
final class ArticleCostBook
{
    /**
     * @param  array<int, int>  $perStockUnit  article => cost per whole stock unit, öre
     * @param  array<int, string>  $stockCodes  article => the code of its stock unit
     * @param  array<int, array<string, BigRational>>  $conversions  article => its conversions
     */
    private function __construct(
        private readonly array $perStockUnit,
        private readonly array $stockCodes,
        private readonly array $conversions,
    ) {}

    /**
     * @param  list<int>  $articleIds
     */
    public static function for(Company $company, array $articleIds, ?CarbonImmutable $date = null): self
    {
        $articleIds = array_values(array_unique(array_map(intval(...), $articleIds)));

        if ($articleIds === []) {
            return new self([], [], []);
        }

        $date ??= CarbonImmutable::today();

        return CurrentCompany::run($company, function () use ($company, $articleIds, $date): self {
            $modules = app(ModuleManager::class);
            $articles = Article::query()->whereIn('id', $articleIds)->with('stockUnit:id,code')->get(['id', 'unit', 'stock_unit_id', 'purchase_price_ore']);
            $conversions = ArticleUnitConversion::query()->whereIn('article_id', $articleIds)->get()->groupBy('article_id');

            $manufacturing = $modules->isEnabled(ManufacturingModule::KEY, $company) ? ManufacturingBook::for($company, $date) : null;
            $costs = $manufacturing === null && $modules->isEnabled(InventoryModule::KEY, $company) ? UnitCosts::at($company, $date) : null;

            $perStock = [];
            $codes = [];
            $fractions = [];

            foreach ($articles as $article) {
                $codes[$article->id] = ArticleUnits::stockCode($article);
                $fractions[$article->id] = ArticleUnits::fractions($conversions->get($article->id, collect()));
                $cost = match (true) {
                    $manufacturing !== null => $manufacturing->articleUnitCostOre($article->id),
                    $costs !== null => $costs->of($article->id)['ore'],
                    default => $article->purchase_price_ore,
                };

                if ($cost !== null) {
                    $perStock[$article->id] = (int) $cost;
                }
            }

            return new self($perStock, $codes, $fractions);
        });
    }

    /** Whether the article has a cost (it may still not convert to a row's unit). */
    public function hasCost(int $articleId): bool
    {
        return isset($this->perStockUnit[$articleId]);
    }

    /**
     * What $quantity (as typed, in $unitCode) of the article costs, in öre
     * rounded half up; null when its cost is unknown or the unit does not
     * convert to its stock unit.
     */
    public function costOf(int $articleId, string $quantity, string $unitCode): ?int
    {
        if (! isset($this->perStockUnit[$articleId])) {
            return null;
        }

        $perStock = UnitConverter::enteredPerStock($this->stockCodes[$articleId] ?? '', $unitCode, $this->conversions[$articleId] ?? []);

        if ($perStock === null || $perStock->isZero()) {
            return null;
        }

        return BigRational::of(BigDecimal::of($quantity))
            ->multipliedBy($this->perStockUnit[$articleId])
            ->dividedBy($perStock)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * The exact cost of one whole $unitCode in öre, unrounded (stock cost
     * divided by the entered units per stock unit); null when its cost is
     * unknown or the unit does not convert. For deriving a price, where the
     * rounding is done once at the end.
     */
    public function unitCostExact(int $articleId, string $unitCode): ?BigRational
    {
        if (! isset($this->perStockUnit[$articleId])) {
            return null;
        }

        $perStock = UnitConverter::enteredPerStock($this->stockCodes[$articleId] ?? '', $unitCode, $this->conversions[$articleId] ?? []);

        if ($perStock === null || $perStock->isZero()) {
            return null;
        }

        return BigRational::of($this->perStockUnit[$articleId])->dividedBy($perStock);
    }

    /** The cost of one whole $unitCode in öre, rounded half up; for messages like "412 kr < 430 kr", never for sums. */
    public function unitCostOre(int $articleId, string $unitCode): ?int
    {
        return $this->costOf($articleId, '1', $unitCode);
    }
}
