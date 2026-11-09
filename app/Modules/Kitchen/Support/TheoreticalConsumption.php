<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Support;

use App\Models\Company;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Pos\Models\PosSaleRow;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;

/**
 * What the till's sales of dishes should have used (ADR-073 §5, RS-C7):
 * every till row for a dish article (not a stocked article, which moved
 * stock itself) in the period, summed per dish and business day, exploded
 * through the recipe valid on that day, and rounded half up once per
 * ingredient for the whole period. Returns reduce. Dishes without a recipe
 * on the day are listed under "Sålt utan recept", never guessed. The
 * source is the till's sales rows from its API, not its Z-report (RS-R17).
 * Two queries for the sales, two for the recipes, whatever the number of
 * rows.
 */
final class TheoreticalConsumption
{
    /**
     * @param  array<int, int>  $ingredients  article => thousandths
     * @param  array<int, int>  $sold  dish article => portions sold, thousandths
     * @param  array<int, int>  $withoutRecipe  dish article => portions sold without a recipe, thousandths
     * @param  array<int, string>  $dishNames
     */
    public function __construct(
        public readonly array $ingredients,
        public readonly array $sold,
        public readonly array $withoutRecipe,
        public readonly array $dishNames,
        public readonly int $unlocatedRows = 0,
    ) {}

    /**
     * Sales on business days from $from to $to, both included; with
     * $locationIds only tills drawing from those places (and tills without
     * a place); with $after/$until only sales made after and up to those
     * moments (the count days, review P3.10).
     *
     * @param  ?list<int>  $locationIds
     */
    public static function between(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?StructureBook $book = null, ?array $locationIds = null, ?CarbonImmutable $after = null, ?CarbonImmutable $until = null): self
    {
        return CurrentCompany::run($company, function () use ($company, $from, $to, $book, $locationIds, $after, $until): self {
            $book ??= StructureBook::for($company);

            $rows = PosSaleRow::query()
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_rows.pos_sale_id')
                ->join('pos_connections', 'pos_connections.id', '=', 'pos_sales.pos_connection_id')
                ->join('articles', 'articles.id', '=', 'pos_sale_rows.article_id')
                ->where('articles.is_stock_item', false)
                ->whereBetween('pos_sales.business_date', [$from->toDateString(), $to->toDateString()])
                ->when($locationIds !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereIn('pos_connections.location_id', $locationIds ?? [])->orWhereNull('pos_connections.location_id')))
                ->when($after !== null, fn ($query) => $query->where('pos_sales.sold_at', '>', $after))
                ->when($until !== null, fn ($query) => $query->where('pos_sales.sold_at', '<=', $until))
                ->groupBy('pos_sale_rows.article_id', 'pos_sales.business_date', 'articles.name')
                ->selectRaw('pos_sale_rows.article_id, pos_sales.business_date, articles.name, SUM(pos_sale_rows.quantity_milli) AS milli, SUM(CASE WHEN pos_connections.location_id IS NULL THEN 1 ELSE 0 END) AS unlocated')
                ->toBase()
                ->get();

            $ingredients = [];
            $sold = [];
            $missing = [];
            $names = [];
            $unlocated = 0;

            foreach ($rows as $row) {
                $dish = (int) $row->article_id;
                $milli = (int) $row->milli;
                $names[$dish] = (string) $row->name;
                $unlocated += (int) $row->unlocated;
                $sold[$dish] = ($sold[$dish] ?? 0) + $milli;

                if ($milli === 0) {
                    continue;
                }

                $exploded = $book->explode([$dish => $milli], CarbonImmutable::parse((string) $row->business_date));

                foreach ($exploded['components'] as $articleId => $quantity) {
                    $ingredients[$articleId] = ($ingredients[$articleId] ?? BigRational::zero())->plus($quantity);
                }

                foreach ($exploded['missing'] as $articleId => $quantity) {
                    $missing[$articleId] = ($missing[$articleId] ?? 0) + StructureMath::round($quantity);
                }
            }

            return new self(array_map(StructureMath::round(...), $ingredients), $sold, $missing, $names, $unlocated);
        });
    }
}
