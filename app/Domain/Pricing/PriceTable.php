<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Article;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The prices an editor shows while a row is written (ADR-082 P3, §5 "batch
 * design"): for every article, its resolved price in its sales unit at
 * quantity 1 and at every quantity step a list or agreement has for it, for
 * the document's customer, date and currency (a row below the lowest step
 * takes the lowest step's price). One resolver call for all of
 * them, so changing a quantity is instant in the browser; an article picked
 * on a new row is added with one more call. The table only proposes: the price is decided again on the server
 * when the document is saved (DocumentPricing). A step written in another
 * unit than the sales unit is not in the table; its price is found on save.
 */
final class PriceTable
{
    public function __construct(private readonly PriceResolver $resolver) {}

    /**
     * The table for the given articles only: the ones on the document's rows,
     * and one more when it is picked. An agreed discount is folded into the
     * price (as DocumentPricing does), so a tier has no discount of its own;
     * the source is left out ('') for a person who may not see where prices
     * come from (prices:view).
     *
     * @param  Collection<int, Article>  $articles  with id, number, vat_rate, sales_unit_id and stock_unit_id
     * @return array<string, array{unit_id: ?int, tiers: list<array{min: int, price: ?int, source: string, warnings: list<string>}>}> by article number, steps from the lowest
     */
    public function for(PriceContext $context, Collection $articles, bool $withSources = true): array
    {
        if ($articles->isEmpty()) {
            return [];
        }

        $steps = $this->steps($context, $articles->pluck('id')->map(intval(...))->values()->all());
        $requests = [];

        foreach ($articles as $article) {
            foreach ([1000, ...($steps[$article->id] ?? []), ...($steps[0] ?? [])] as $milli) {
                $key = "{$article->id}:{$milli}";
                $requests[$key] ??= new PriceRequest($key, $article->id, $milli);
            }
        }

        $byArticle = [];

        foreach ($this->resolver->resolveMany($context, array_values($requests)) as $key => $price) {
            [$articleId, $milli] = array_map(intval(...), explode(':', (string) $key));
            $byArticle[$articleId][$milli] = $price;
        }

        $table = [];

        foreach ($articles as $article) {
            $tiers = [];

            foreach ($byArticle[$article->id] ?? [] as $milli => $price) {
                $row = DocumentPricing::withDiscount($price, null, $article->vat_rate);

                $tiers[$milli] = [
                    'min' => $milli,
                    'price' => $row['unit_price_minor'] ?? null,
                    'source' => $withSources ? ($price->source->value ?? '') : '',
                    'warnings' => $price->warnings,
                ];
            }

            ksort($tiers);

            $table[(string) $article->number] = [
                'unit_id' => $article->sales_unit_id ?? $article->stock_unit_id,
                'tiers' => array_values($tiers),
            ];
        }

        return $table;
    }

    /**
     * The quantity steps (milli-units) of the lists' and the customer's
     * agreements' items, by article id; 0 holds the steps of agreement items
     * for all articles. Items in another unit than the article's sales unit
     * are left out. Two queries.
     *
     * @param  array<int, int>  $articleIds
     * @return array<int, list<int>>
     */
    private function steps(PriceContext $context, array $articleIds): array
    {
        $date = $context->date->toDateString();
        $steps = [];

        $listItems = PriceListItem::query()
            ->join('articles', 'articles.id', '=', 'price_list_items.article_id')
            ->whereIn('price_list_items.article_id', $articleIds)
            ->where('price_list_items.min_quantity_milli', '>', 0)
            ->whereNull('price_list_items.archived_at')
            ->where(fn (Builder $query) => $query->whereNull('price_list_items.unit_id')->orWhereColumn('price_list_items.unit_id', 'articles.sales_unit_id'))
            ->where(fn (Builder $query) => $query->whereNull('price_list_items.valid_to')->orWhere('price_list_items.valid_to', '>=', $date))
            ->distinct()
            ->get(['price_list_items.article_id', 'price_list_items.min_quantity_milli']);

        foreach ($listItems as $item) {
            $steps[(int) $item->article_id][] = (int) $item->min_quantity_milli;
        }

        if ($context->customerId !== null) {
            $agreementItems = PriceAgreementItem::query()
                ->whereIn('price_agreement_id', PriceAgreement::query()->select('id')->where('customer_id', $context->customerId))
                ->where('min_quantity_milli', '>', 0)
                ->where(fn (Builder $query) => $query->whereIn('article_id', $articleIds)->orWhere('applies_to_all', true))
                ->distinct()
                ->get(['article_id', 'min_quantity_milli', 'unit_id']);

            foreach ($agreementItems as $item) {
                $steps[(int) ($item->article_id ?? 0)][] = (int) $item->min_quantity_milli;
            }
        }

        return array_map(fn (array $values): array => array_values(array_unique($values)), $steps);
    }
}
