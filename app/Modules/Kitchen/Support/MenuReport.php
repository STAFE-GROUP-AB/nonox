<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Support;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Pos\Models\PosSaleRow;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Meny (ADR-073 §4): every dish with a recipe in portions, its portion cost
 * today, its net menu price (articles store prices excluding VAT), the
 * contribution and food cost % (RS-C6), and from the till in a chosen
 * period the portions sold, the realised average net price (discounts and
 * happy hours make it differ from the list price) and the contribution
 * earned. A dish whose cost is incomplete says so and is never shown as
 * free. A management figure, not the stock valuation (RS-R4). A fixed
 * number of queries whatever the number of dishes.
 */
final class MenuReport
{
    /**
     * @return list<array{article_id: int, number: string, name: string, vat_rate: int, net_price_ore: int, portion_cost_ore: ?int, complete: bool, contribution_ore: ?int, margin_bp: ?int, food_cost_bp: ?int, sold_milli: int, revenue_ore: int, realised_price_ore: ?int, earned_ore: ?int}>
     */
    public static function for(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today();

        return CurrentCompany::run($company, function () use ($company, $from, $to, $on): array {
            $book = StructureBook::for($company);
            $costs = UnitCosts::at($company, $on);
            $dishes = [];

            foreach ($book->outputs() as $articleId) {
                $structure = $book->validOn($articleId, $on);

                if ($structure instanceof BillOfMaterials && $structure->isPortions()) {
                    $dishes[$articleId] = $structure;
                }
            }

            $articles = Article::query()->whereKey(array_keys($dishes))->orderBy('number')->get(['id', 'number', 'name', 'price_ore', 'vat_rate']);
            $sales = PosSaleRow::query()
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_rows.pos_sale_id')
                ->whereIn('pos_sale_rows.article_id', array_keys($dishes))
                ->whereBetween('pos_sales.business_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('pos_sale_rows.article_id')
                ->selectRaw('pos_sale_rows.article_id, SUM(pos_sale_rows.quantity_milli) AS milli, SUM(pos_sale_rows.amount_ore) AS ore')
                ->toBase()
                ->get()
                ->keyBy('article_id');

            $rows = [];

            foreach ($articles as $article) {
                $cost = $book->cost($dishes[$article->id], $costs, $on);
                $portion = $cost->complete ? $cost->perUnitOre : null;
                $net = $article->price_ore->ore;
                $sold = (int) ($sales->get($article->id)->milli ?? 0);
                $revenue = (int) ($sales->get($article->id)->ore ?? 0);

                $rows[] = [
                    'article_id' => $article->id,
                    'number' => $article->number,
                    'name' => $article->name,
                    'vat_rate' => $article->vat_rate,
                    'net_price_ore' => $net,
                    'portion_cost_ore' => $portion,
                    'complete' => $cost->complete,
                    'contribution_ore' => $portion === null ? null : $net - $portion,
                    'margin_bp' => $portion === null ? null : StructureMath::share($net - $portion, $net),
                    'food_cost_bp' => $portion === null ? null : StructureMath::share($portion, $net),
                    'sold_milli' => $sold,
                    'revenue_ore' => $revenue,
                    'realised_price_ore' => $sold > 0 ? BigDecimal::of($revenue)->multipliedBy(Quantity::SCALE)->dividedBy($sold, 0, RoundingMode::HalfUp)->toInt() : null,
                    'earned_ore' => $portion === null || $sold === 0 ? null : $revenue - Quantity::value($sold, $portion),
                ];
            }

            return $rows;
        });
    }
}
