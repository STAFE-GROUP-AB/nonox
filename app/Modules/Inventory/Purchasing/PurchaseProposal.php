<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Models\Article;
use App\Models\Company;
use App\Models\Unit;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockLevel;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;

/**
 * The purchase proposal (ADR-071 §5) for articles kept in stock:
 *
 *     free now = on hand − reserved (by every demand source)
 *     coming   = open purchase order rows not yet received, with their date
 *     short    = max(0, reserved − on hand − coming)
 *
 * An article with a reorder point whose free stock plus what is coming is at
 * or below the point is ordered back above it: the reorder quantity, or what
 * brings it back to the point when that is more or no reorder quantity is
 * set. The proposal is the larger of that and the shortage, expressed in the
 * article's purchase unit and rounded up (whole packs). Runs inside the
 * company, in a fixed number of queries whatever the number of articles.
 */
final class PurchaseProposal
{
    public function __construct(private readonly PurchaseDemandSources $sources) {}

    /** @return list<ProposalLine> */
    public function lines(Company $company): array
    {
        $reservedBy = [];
        $reserved = [];

        foreach ($this->sources->all() as $source) {
            foreach ($source->reserved($company) as $articleId => $milli) {
                $reservedBy[$articleId][$source->label()] = ($reservedBy[$articleId][$source->label()] ?? 0) + $milli;
                $reserved[$articleId] = ($reserved[$articleId] ?? 0) + $milli;
            }
        }

        $articles = Article::query()
            ->with(['stockUnit', 'purchaseUnit', 'defaultSupplier:id,name'])
            ->where('is_stock_item', true)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNotNull('reorder_point_milli')->orWhereIn('id', array_keys($reserved)))
            ->orderBy('number')
            ->get();

        if ($articles->isEmpty()) {
            return [];
        }

        $ids = $articles->modelKeys();
        $onHand = StockLevel::query()->whereIn('article_id', $ids)->groupBy('article_id')->select('article_id', DB::raw('sum(quantity_milli) as milli'))->pluck('milli', 'article_id');
        $coming = PurchaseOrderRow::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_rows.purchase_order_id')
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereIn('purchase_order_rows.article_id', $ids)
            ->whereColumn('purchase_order_rows.received_milli', '<', 'purchase_order_rows.quantity_milli')
            ->groupBy('purchase_order_rows.article_id')
            ->get(['purchase_order_rows.article_id', DB::raw('sum(purchase_order_rows.quantity_milli - purchase_order_rows.received_milli) as milli'), DB::raw('min(purchase_order_rows.expected_on) as first_expected')])
            ->keyBy('article_id');
        $conversions = PurchaseQuantities::conversions($ids);
        $lines = [];

        foreach ($articles as $article) {
            $have = (int) ($onHand[$article->id] ?? 0);
            $taken = $reserved[$article->id] ?? 0;
            $incoming = (int) ($coming->get($article->id)?->getAttribute('milli') ?? 0);
            $available = $have - $taken + $incoming;
            $short = max(0, $taken - $have - $incoming);
            $below = $article->reorder_point_milli !== null && $available <= $article->reorder_point_milli;
            $refill = $below ? max($article->reorder_quantity_milli ?? 0, $article->reorder_point_milli - $available) : 0;
            $proposed = max($short, $refill);

            if ($proposed <= 0) {
                continue;
            }

            [$unit, $perStock] = $this->purchaseUnit($article, $conversions[$article->id] ?? []);

            if ($unit === null) {
                continue;
            }

            $quantity = PurchaseQuantities::inPurchaseUnit($proposed, $perStock, $unit);

            $lines[] = new ProposalLine(
                article: $article,
                onHandMilli: $have,
                reservedMilli: $taken,
                reservedBy: $reservedBy[$article->id] ?? [],
                comingMilli: $incoming,
                comingFrom: $coming->get($article->id)?->getAttribute('first_expected'),
                shortMilli: $short,
                belowReorderPoint: $below,
                proposedMilli: $proposed,
                proposedQuantity: (string) $quantity,
                unit: $unit,
            );
        }

        return $lines;
    }

    /**
     * The unit to order in: the purchase unit when it converts to the stock
     * unit, otherwise the stock unit.
     *
     * @param  array<string, BigRational>  $conversions
     * @return array{0: ?Unit, 1: BigRational}
     */
    private function purchaseUnit(Article $article, array $conversions): array
    {
        /** @var ?Unit $purchase */
        $purchase = $article->purchaseUnit;

        if ($purchase !== null) {
            $perStock = PurchaseQuantities::perStock($article, $purchase, $conversions);

            if ($perStock !== null) {
                return [$purchase, $perStock];
            }
        }

        /** @var ?Unit $stock */
        $stock = $article->stockUnit;

        return [$stock, BigRational::one()];
    }
}
