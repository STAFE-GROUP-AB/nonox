<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Units\QuantityOutOfRange;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Open customer orders (Order, status Öppen) reserve the stock articles on
 * their rows (ADR-071 §5). What a completed pick list for the order has
 * already taken out of stock no longer reserves anything. A row in another
 * unit than the stock unit is converted when the article converts it;
 * otherwise its quantity is read in the stock unit, as pick lists do.
 */
final class OpenCustomerOrderDemand implements PurchaseDemandSource
{
    public function label(): string
    {
        return __('Öppna kundordrar');
    }

    public function reserved(Company $company): array
    {
        $rows = SalesDocumentRow::query()
            ->join('sales_documents', 'sales_documents.id', '=', 'sales_document_rows.sales_document_id')
            ->join('articles', 'articles.id', '=', 'sales_document_rows.article_id')
            ->where('sales_documents.kind', SalesDocument::ORDER)
            ->where('sales_documents.status', SalesDocumentStatus::Open->value)
            ->where('articles.is_stock_item', true)
            ->get(['sales_document_rows.sales_document_id', 'sales_document_rows.article_id', 'sales_document_rows.quantity', 'sales_document_rows.unit']);

        if ($rows->isEmpty()) {
            return [];
        }

        $articles = Article::query()->with('stockUnit:id,code')->whereKey($rows->pluck('article_id')->unique()->all())->get(['id', 'unit', 'stock_unit_id'])->keyBy('id');
        $conversions = PurchaseQuantities::conversions($articles->keys()->all());
        $wanted = [];

        foreach ($rows as $row) {
            $article = $articles->get((int) $row->article_id);

            if ($article === null) {
                continue;
            }

            $milli = self::stockMilli($article, (string) $row->quantity, (string) $row->unit, $conversions[$article->id] ?? []);
            $wanted[(string) $row->sales_document_id][$article->id] = ($wanted[(string) $row->sales_document_id][$article->id] ?? 0) + $milli;
        }

        $picked = PickListLine::query()
            ->join('pick_lists', 'pick_lists.id', '=', 'pick_list_lines.pick_list_id')
            ->where('pick_lists.status', PickList::COMPLETED)
            ->where('pick_lists.source_type', (new SalesDocument)->getMorphClass())
            ->whereIn('pick_lists.source_id', array_keys($wanted))
            ->get(['pick_lists.source_id', 'pick_list_lines.article_id', 'pick_list_lines.picked_milli']);

        foreach ($picked as $line) {
            $order = (string) $line->getAttribute('source_id');
            $article = (int) $line->article_id;

            if (isset($wanted[$order][$article])) {
                $wanted[$order][$article] -= (int) $line->picked_milli;
            }
        }

        $reserved = [];

        foreach ($wanted as $perArticle) {
            foreach ($perArticle as $articleId => $milli) {
                $reserved[$articleId] = ($reserved[$articleId] ?? 0) + max(0, $milli);
            }
        }

        return array_filter($reserved, fn (int $milli): bool => $milli > 0);
    }

    /** @param  array<string, BigRational>  $conversions */
    private static function stockMilli(Article $article, string $quantity, string $unit, array $conversions): int
    {
        try {
            $decimal = BigDecimal::of($quantity);
        } catch (MathException) {
            return 0;
        }

        if (! $decimal->isPositive()) {
            return 0;
        }

        $perStock = UnitConverter::enteredPerStock($article->stockUnitCode(), $unit, $conversions);

        if ($perStock === null) {
            return Quantity::parse((string) $decimal->toScale(3, RoundingMode::HalfUp));
        }

        try {
            return UnitConverter::toStock($decimal, $perStock)->stockMilli;
        } catch (QuantityRoundsToZero|QuantityOutOfRange) {
            return 0;
        }
    }
}
