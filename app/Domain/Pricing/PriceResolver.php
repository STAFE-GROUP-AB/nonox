<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Article;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceListPriceGroup;
use App\Models\Unit;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Support\Currency\RiksbankRates;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * The price engine (ADR-082 §5). Prices a whole document's rows at once, in
 * a fixed number of queries however many rows there are, and explains every
 * price with ids and keys only.
 *
 * Order per row; the first that applies wins, except where it says lowest:
 *  1. a manual price on the row (never replaced);
 *  2. the customer's price agreement valid on the date in the document's
 *     currency (a cheaper campaign wins only when the agreement allows it);
 *  3. the customer's own price list, else its price group's list;
 *  4. campaigns for everyone, the customer's group or the channel: the
 *     lowest of 3/5 and the campaigns is used;
 *  5. the currency's default list, else (SEK) the article's base price;
 *  6. no price in the document's currency: the base price converted at
 *     Riksbanken's rate on the date, with a warning (decision 6).
 *
 * Every read goes through the company scope of the current company, which
 * must be the context's: a list, agreement, unit or article of another
 * company is never found and so never used.
 */
final class PriceResolver
{
    public function __construct(private readonly RiksbankRates $rates) {}

    public function resolve(PriceContext $context, PriceRequest $request): ResolvedPrice
    {
        return $this->resolveMany($context, [$request])[$request->key];
    }

    /**
     * @param  list<PriceRequest>  $requests
     * @return array<string, ResolvedPrice> keyed by the requests' keys, in their order
     */
    public function resolveMany(PriceContext $context, array $requests): array
    {
        if (CurrentCompany::id() !== $context->companyId) {
            throw new LogicException('Prices are resolved inside the company they belong to.');
        }

        if ($requests === []) {
            return [];
        }

        $keys = array_map(fn (PriceRequest $request): string => $request->key, $requests);

        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException('Every row to price needs its own key.');
        }

        $book = $this->load($context, $requests);
        $resolved = [];

        foreach ($requests as $request) {
            $resolved[$request->key] = $book->resolve($request);
        }

        return $resolved;
    }

    /** @param  list<PriceRequest>  $requests */
    private function load(PriceContext $context, array $requests): PriceBook
    {
        $date = $context->date->toDateString();
        $articleIds = array_values(array_unique(array_map(fn (PriceRequest $request): int => $request->articleId, $requests)));

        $articles = Article::query()
            ->whereIn('id', $articleIds)
            ->get(['id', 'company_id', 'unit', 'price_ore', 'vat_rate', 'stock_unit_id', 'sales_unit_id'])
            ->keyBy('id');

        $units = Unit::query()->pluck('code', 'id')->map(fn (mixed $code): string => (string) $code)->all();

        $conversions = $articles->isEmpty() ? collect() : ArticleUnitConversion::query()
            ->whereIn('article_id', $articles->keys()->all())
            ->orderBy('id')
            ->get()
            ->groupBy('article_id');

        [$customer, $group] = $this->customer($context);

        $lists = PriceList::query()
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $agreements = $customer === null ? [] : PriceAgreement::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [PriceAgreementStatus::Active, PriceAgreementStatus::Ended, PriceAgreementStatus::Superseded, PriceAgreementStatus::Terminated])
            ->where('valid_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->where(fn (Builder $query) => $query->whereNull('terminated_on')->orWhere('terminated_on', '>=', $date))
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->get()
            ->values()
            ->all();

        $agreementItems = $agreements === [] || $articles->isEmpty() ? collect() : PriceAgreementItem::query()
            ->whereIn('price_agreement_id', array_map(fn (PriceAgreement $agreement): int => $agreement->id, $agreements))
            ->where(fn (Builder $query) => $query->whereIn('article_id', $articles->keys()->all())->orWhere('applies_to_all', true))
            ->orderBy('id')
            ->get()
            ->groupBy('price_agreement_id');

        $campaignIds = $lists->filter(fn (PriceList $list): bool => $list->kind === PriceListKind::Campaign)->keys()->map(intval(...))->values()->all();

        $campaignGroups = $campaignIds === [] ? collect() : PriceListPriceGroup::query()
            ->whereIn('price_list_id', $campaignIds)
            ->get(['price_list_id', 'customer_price_group_id'])
            ->groupBy('price_list_id')
            ->map(fn ($rows): array => $rows->pluck('customer_price_group_id')->map(intval(...))->all());

        $listIds = $this->relevantLists($lists, $customer, $group, $context->currency, $campaignIds, [...$agreementItems->flatten(1)->pluck('base_price_list_id')->filter()->all(), ...($context->channelListId === null ? [] : [$context->channelListId])]);

        $items = $listIds === [] || $articles->isEmpty() ? collect() : PriceListItem::query()
            ->whereIn('price_list_id', $listIds)
            ->whereIn('article_id', $articles->keys()->all())
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PriceListItem $item): string => "{$item->price_list_id}:{$item->article_id}");

        return new PriceBook(
            context: $context,
            articles: $articles->all(),
            units: $units,
            conversions: $conversions->all(),
            customer: $customer,
            group: $group,
            lists: $lists->all(),
            agreements: array_values($agreements),
            agreementItems: $agreementItems->all(),
            campaignGroups: $campaignGroups->all(),
            items: $items->all(),
            rates: $this->rates,
        );
    }

    /** @return array{0: ?Customer, 1: ?CustomerPriceGroup} */
    private function customer(PriceContext $context): array
    {
        if ($context->customerId === null) {
            return [null, null];
        }

        $customer = Customer::query()->find($context->customerId, ['id', 'company_id', 'price_group_id', 'price_list_id']);

        if (! $customer instanceof Customer) {
            throw new InvalidArgumentException('The customer is not one of the company\'s.');
        }

        $group = $customer->price_group_id === null ? null : CustomerPriceGroup::query()
            ->whereNull('archived_at')
            ->find($customer->price_group_id, ['id', 'company_id', 'price_list_id']);

        return [$customer, $group instanceof CustomerPriceGroup ? $group : null];
    }

    /**
     * The lists whose items a row may need: the customer's and its group's,
     * the campaigns, the currency's default, the lists agreement items
     * discount, and every list those are derived from.
     *
     * @param  Collection<int, PriceList>  $lists
     * @param  array<int, int>  $campaignIds
     * @param  array<int, mixed>  $agreementLists
     * @return list<int>
     */
    private function relevantLists(Collection $lists, ?Customer $customer, ?CustomerPriceGroup $group, string $currency, array $campaignIds, array $agreementLists): array
    {
        $wanted = [
            $customer?->price_list_id,
            $group?->price_list_id,
            ...$campaignIds,
            ...$lists->filter(fn (PriceList $list): bool => $list->is_default_for_currency && $list->currency === $currency)->keys()->all(),
            ...array_map(intval(...), $agreementLists),
        ];

        $result = [];

        // A chain is followed for at most MAX_DERIVED_DEPTH lists, as PriceBook prices it; a list reached
        // again with more of the chain left is followed again, and a loop ends when the chain runs out.
        foreach ($wanted as $id) {
            $remaining = PriceBook::MAX_DERIVED_DEPTH;

            while ($id !== null && $lists->has($id) && $remaining > 0 && ($result[$id] ?? 0) < $remaining) {
                $result[$id] = $remaining--;
                $id = $lists->get($id)?->based_on_list_id;
            }
        }

        $ids = array_keys($result);
        sort($ids);

        return $ids;
    }
}
