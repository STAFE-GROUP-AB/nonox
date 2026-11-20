<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything one batch of rows can be priced from, loaded once by
 * PriceResolver; the rest is arithmetic in memory (ADR-082 §5).
 *
 * Within a list or an agreement:
 * - Quantity ladder: the row's whole quantity gets the price of the highest
 *   step it reaches (decision 7). Steps are compared in the row's unit.
 * - Units: an item's unit and the row's unit are compared through the
 *   article's conversions (UnitConverter); the price per unit is converted
 *   exactly and rounded half up once. An item whose unit does not convert is
 *   skipped and listed as considered.
 * - A net price gives the row that price and no discount; a discount gives
 *   the row the price it discounts (the currency's ordinary price, or the
 *   named list) and the percent as the row discount.
 * - Prices including VAT: the row gets round_half_up(gross × 10000 /
 *   (10000 + VAT rate)) and the gross price is kept in the explanation.
 *   TODO(legal-verify): P-L1, whether a consumer's total may end one öre off
 *   the shown price after this per-row VAT rounding.
 * - Ties: the most specific target, then the highest step, then an item in
 *   the row's own unit, then the latest valid_from, then the lowest id.
 *
 * @internal built by PriceResolver only
 */
final class PriceBook
{
    public const int MAX_DERIVED_DEPTH = 5;

    /** @var array<string, ?BigRational> */
    private array $perStock = [];

    private ?ExchangeRate $rate = null;

    private bool $rateLoaded = false;

    /** @var list<array<string, int|string|bool|null>> */
    private array $considered = [];

    /** The current row's base price in the document's currency, worked out only when something needs it (a rate may be fetched). */
    private ?PriceCandidate $raw = null;

    private bool $rawDone = false;

    /** The current row's ordinary price in the document's currency (steps 5 and 6), worked out only when needed. */
    private ?PriceCandidate $reference = null;

    private bool $referenceDone = false;

    /**
     * @param  array<int, Article>  $articles
     * @param  array<int, string>  $units  unit id => code
     * @param  array<int, Collection<int, ArticleUnitConversion>>  $conversions  by article id
     * @param  array<int, PriceList>  $lists  valid on the date, not archived
     * @param  list<PriceAgreement>  $agreements  the customer's, valid on the date, latest first
     * @param  array<int, Collection<int, PriceAgreementItem>>  $agreementItems  by agreement id
     * @param  array<int, list<int>>  $campaignGroups  campaign list id => targeted price group ids
     * @param  array<string, Collection<int, PriceListItem>>  $items  "list id:article id" => items valid on the date
     */
    public function __construct(
        private readonly PriceContext $context,
        private readonly array $articles,
        private readonly array $units,
        private readonly array $conversions,
        private readonly ?Customer $customer,
        private readonly ?CustomerPriceGroup $group,
        private readonly array $lists,
        private readonly array $agreements,
        private readonly array $agreementItems,
        private readonly array $campaignGroups,
        private readonly array $items,
        private readonly RiksbankRates $rates,
    ) {}

    /**
     * A campaign is time-limited: without both dates it never prices a row,
     * whatever wrote it (ADR-082 §2; the database refuses it too).
     */
    public static function isUsableCampaign(PriceList $list, CarbonImmutable $date): bool
    {
        if ($list->kind !== PriceListKind::Campaign || $list->archived_at !== null) {
            return false;
        }

        if ($list->valid_from === null || $list->valid_to === null) {
            return false;
        }

        return $list->valid_from->lessThanOrEqualTo($date) && $list->valid_to->greaterThanOrEqualTo($date);
    }

    public function resolve(PriceRequest $request): ResolvedPrice
    {
        $this->considered = [];
        $currency = $this->context->currency;
        $article = $this->articles[$request->articleId] ?? null;

        if ($article === null) {
            return new ResolvedPrice($request->key, null, 0, $currency, null, warnings: [ResolvedPrice::WARNING_UNKNOWN_ARTICLE, ResolvedPrice::WARNING_NO_PRICE]);
        }

        if ($request->manualPriceMinor !== null) {
            return new ResolvedPrice(
                key: $request->key,
                unitPriceMinor: $request->manualPriceMinor,
                discountBp: $request->manualDiscountBp ?? 0,
                currency: $currency,
                source: PriceSource::Manual,
                manualDiscount: $request->manualDiscountBp !== null,
                explanation: ['source' => PriceSource::Manual->value],
            );
        }

        $rowUnit = $request->unitId === null ? $this->salesUnit($article) : ($this->units[$request->unitId] ?? null);

        if ($rowUnit === null || $this->perStock($article, $rowUnit) === null) {
            return new ResolvedPrice($request->key, null, 0, $currency, null, warnings: [ResolvedPrice::WARNING_UNIT, ResolvedPrice::WARNING_NO_PRICE]);
        }

        $row = new PricedRow($article, $rowUnit, max(0, $request->quantityMilli));

        $this->rawDone = false;
        $this->referenceDone = false;
        $reference = fn (): ?PriceCandidate => $this->reference($row);
        $agreement = $this->agreementPrice($row, $reference);
        $customerList = $this->customerListPrice($row, $reference);
        $campaign = $this->bestCampaign($row, $reference);

        $winner = $this->choose($agreement, $customerList, $campaign, $reference);

        if ($winner === null) {
            $rateMissing = $this->context->currency !== 'SEK' && $this->rate() === null;
            $warnings = $rateMissing ? [ResolvedPrice::WARNING_NO_RATE, ResolvedPrice::WARNING_NO_PRICE] : [ResolvedPrice::WARNING_NO_PRICE];

            return new ResolvedPrice($request->key, null, 0, $currency, null, considered: $this->considered, warnings: $warnings);
        }

        return $this->result($request, $winner);
    }

    /** @param  \Closure(): ?PriceCandidate  $reference */
    private function choose(?PriceCandidate $agreement, ?PriceCandidate $customerList, ?PriceCandidate $campaign, \Closure $reference): ?PriceCandidate
    {
        // The ordinary price is listed as a loser only when it was worked out anyway; it is never fetched just to be listed.
        $ordinary = $this->referenceDone ? $this->reference : null;

        if ($agreement !== null) {
            $allowsCampaigns = $this->agreementAllowsCampaigns($agreement->agreementId);

            foreach ([$customerList, $ordinary] as $other) {
                if ($other !== null) {
                    $this->considered[] = $other->considered('agreement_wins');
                }
            }

            if ($campaign !== null && $allowsCampaigns && $campaign->isLowerThan($agreement)) {
                $this->considered[] = $agreement->considered('campaign_lower');

                return $campaign;
            }

            if ($campaign !== null) {
                $this->considered[] = $campaign->considered($allowsCampaigns ? 'not_lower' : 'agreement_wins');
            }

            return $agreement;
        }

        $normal = $customerList ?? $reference();

        if ($customerList !== null && $ordinary !== null) {
            $this->considered[] = $ordinary->considered('customer_list_wins');
        }

        if ($campaign !== null && ($normal === null || $campaign->isLowerThan($normal))) {
            if ($normal !== null) {
                $this->considered[] = $normal->considered('campaign_lower');
            }

            return $campaign;
        }

        if ($campaign !== null) {
            $this->considered[] = $campaign->considered('not_lower');
        }

        return $normal;
    }

    private function result(PriceRequest $request, PriceCandidate $winner): ResolvedPrice
    {
        $unitPrice = $winner->unitPriceMinor;
        $discount = $winner->discountBp;
        $folded = null;

        if ($request->manualDiscountBp !== null) {
            if ($discount > 0) {
                $folded = $discount;
                $unitPrice = $winner->effectiveMinor();
            }

            $discount = $request->manualDiscountBp;
        }

        $warnings = $winner->converted ? [ResolvedPrice::WARNING_CONVERTED] : [];
        $rate = $winner->converted ? $this->rate : null;

        $explanation = array_filter([
            'source' => $winner->source->value,
            'list_id' => $winner->listId,
            'item_id' => $winner->itemId,
            'agreement_id' => $winner->agreementId,
            'tier_min_milli' => $winner->tierMinMilli,
            'base_minor' => $winner->baseMinor,
            'gross_minor' => $winner->grossMinor,
            'derived' => $winner->derived ? true : null,
            'rate_micros' => $rate?->micros,
            'rate_date' => $rate?->date,
            'folded_discount_bp' => $folded,
            'manual_discount_bp' => $request->manualDiscountBp,
        ], fn (mixed $value): bool => $value !== null);

        return new ResolvedPrice(
            key: $request->key,
            unitPriceMinor: $unitPrice,
            discountBp: $discount,
            currency: $this->context->currency,
            source: $winner->source,
            includesVat: $winner->includesVat,
            grossUnitPriceMinor: $winner->grossMinor,
            manualDiscount: $request->manualDiscountBp !== null,
            explanation: $explanation,
            considered: $this->considered,
            warnings: $warnings,
        );
    }

    /**
     * The article's base price per the row's unit in the document's
     * currency: articles.price_ore for SEK, converted at Riksbanken's rate
     * on the date for any other currency (null when there is no rate).
     */
    private function rawBase(PricedRow $row): ?PriceCandidate
    {
        $sales = $this->perStock($row->article, $this->salesUnit($row->article));
        $perRow = $this->perStock($row->article, $row->unit);

        if ($sales === null || $perRow === null) {
            return null;
        }

        $perUnit = BigRational::of($row->article->price_ore->ore)->multipliedBy($sales)->dividedBy($perRow);

        if ($this->context->currency === 'SEK') {
            return new PriceCandidate(PriceSource::Base, $perUnit->toScale(0, RoundingMode::HalfUp)->toInt());
        }

        $rate = $this->rate();

        if ($rate === null) {
            return null;
        }

        $foreign = $perUnit->multipliedBy(ExchangeRate::SCALE)->dividedBy($rate->micros)->toScale(0, RoundingMode::HalfUp)->toInt();

        return new PriceCandidate(PriceSource::Converted, $foreign, converted: true);
    }

    private function raw(PricedRow $row): ?PriceCandidate
    {
        if (! $this->rawDone) {
            $this->rawDone = true;
            $this->raw = $this->rawBase($row);
        }

        return $this->raw;
    }

    /**
     * What a discount item discounts: the currency's ordinary price, i.e.
     * what other customers pay (ADR-082 §2 and §5).
     */
    private function reference(PricedRow $row): ?PriceCandidate
    {
        if (! $this->referenceDone) {
            $this->referenceDone = true;
            $this->reference = $this->currencyPrice($row);
        }

        return $this->reference;
    }

    /** Steps 5 and 6: the currency's default list, else the base price (converted for another currency). */
    private function currencyPrice(PricedRow $row): ?PriceCandidate
    {
        $default = $this->defaultList();

        if ($default !== null) {
            // A discount item in the default list itself discounts the base price.
            $listed = $this->listPrice($default, $row, fn (): ?PriceCandidate => $this->raw($row));

            if ($listed !== null) {
                return $listed->with(['source' => PriceSource::CurrencyList]);
            }
        }

        $raw = $this->raw($row);

        if ($raw === null || $raw->source !== PriceSource::Converted) {
            return $raw;
        }

        $rounding = $default === null ? PriceRounding::None : $default->rounding_rule;

        return $raw->with(['unitPriceMinor' => max(0, $rounding->apply($raw->unitPriceMinor))]);
    }

    private function defaultList(): ?PriceList
    {
        foreach ($this->lists as $list) {
            if ($list->is_default_for_currency && $list->currency === $this->context->currency && $list->kind !== PriceListKind::Campaign) {
                return $list;
            }
        }

        return null;
    }

    /** Step 2: the customer's price agreement valid on the date in the document's currency. */
    /** @param  \Closure(): ?PriceCandidate  $reference */
    private function agreementPrice(PricedRow $row, \Closure $reference): ?PriceCandidate
    {
        foreach ($this->agreements as $agreement) {
            if ($agreement->currency !== $this->context->currency) {
                $this->considered[] = ['source' => PriceSource::Agreement->value, 'agreement_id' => $agreement->id, 'reason' => 'currency_mismatch'];

                continue;
            }

            /** @var Collection<int, PriceAgreementItem> $items */
            $items = $this->agreementItems[$agreement->id] ?? collect();
            $items = $items->filter(fn (PriceAgreementItem $item): bool => $item->article_id === $row->article->id || ($item->applies_to_all && $item->article_id === null))->values();

            $eligible = $this->ladder($row, $items->all(), fn (PriceAgreementItem $item): int => $item->article_id !== null ? 1 : 0, fn (): int => 0, PriceSource::Agreement, null, $agreement->id);

            foreach ($eligible as $item) {
                $candidate = $this->itemPrice($row, $item, PriceSource::Agreement, $reference, false, null, $agreement->id);

                if ($candidate !== null) {
                    return $candidate;
                }
            }

            // Only one agreement is valid on a date (the action refuses overlaps); the latest one is the customer's.
            return null;
        }

        return null;
    }

    private function agreementAllowsCampaigns(?int $agreementId): bool
    {
        foreach ($this->agreements as $agreement) {
            if ($agreement->id === $agreementId) {
                return $agreement->allow_campaigns;
            }
        }

        return false;
    }

    /** Step 3: the customer's own list, else its price group's list. */
    /** @param  \Closure(): ?PriceCandidate  $reference */
    private function customerListPrice(PricedRow $row, \Closure $reference): ?PriceCandidate
    {
        foreach ([$this->customer?->price_list_id, $this->group?->price_list_id] as $listId) {
            if ($listId === null) {
                continue;
            }

            $list = $this->lists[$listId] ?? null;

            if ($list === null || $list->kind === PriceListKind::Campaign) {
                $this->considered[] = ['source' => PriceSource::CustomerList->value, 'list_id' => $listId, 'reason' => 'not_valid'];

                continue;
            }

            if ($list->currency !== $this->context->currency) {
                $this->considered[] = ['source' => PriceSource::CustomerList->value, 'list_id' => $listId, 'reason' => 'currency_mismatch'];

                continue;
            }

            $candidate = $this->listPrice($list, $row, $reference);

            if ($candidate !== null) {
                return $candidate->with(['source' => PriceSource::CustomerList]);
            }
        }

        return null;
    }

    /** Step 4: the lowest campaign for everyone, the customer's group or the channel. */
    /** @param  \Closure(): ?PriceCandidate  $reference */
    private function bestCampaign(PricedRow $row, \Closure $reference): ?PriceCandidate
    {
        $best = null;

        foreach ($this->lists as $list) {
            if (! self::isUsableCampaign($list, $this->context->date) || $list->currency !== $this->context->currency) {
                continue;
            }

            if ($list->channel !== null && $list->channel !== $this->context->channel) {
                continue;
            }

            $targets = $this->campaignGroups[$list->id] ?? [];

            if ($targets !== [] && ! in_array($this->group?->id, $targets, true)) {
                continue;
            }

            $candidate = $this->listPrice($list, $row, $reference);

            if ($candidate === null) {
                continue;
            }

            $candidate = $candidate->with(['source' => PriceSource::Campaign]);

            if ($best === null || $this->campaignBeats($candidate, $best)) {
                if ($best !== null) {
                    $this->considered[] = $best->considered('not_lower');
                }

                $best = $candidate;

                continue;
            }

            $this->considered[] = $candidate->considered('not_lower');
        }

        return $best;
    }

    /** Lowest wins; on equal prices the latest start, then the lowest list id. */
    private function campaignBeats(PriceCandidate $candidate, PriceCandidate $best): bool
    {
        $compare = $candidate->effective()->compareTo($best->effective());

        if ($compare !== 0) {
            return $compare < 0;
        }

        $fromCandidate = $this->lists[$candidate->listId ?? 0]->valid_from?->toDateString() ?? '';
        $fromBest = $this->lists[$best->listId ?? 0]->valid_from?->toDateString() ?? '';

        if ($fromCandidate !== $fromBest) {
            return $fromCandidate > $fromBest;
        }

        return ($candidate->listId ?? 0) < ($best->listId ?? 0);
    }

    /**
     * A list's price for the row: its best matching item, else its derived
     * rule, else nothing. $reference gives what a discount item discounts;
     * a list derived from the base price starts from the article's base price
     * and one derived from another list from that list's price.
     *
     * @param  \Closure(): ?PriceCandidate  $reference
     * @param  list<int>  $path  the derived lists already on the way here: a loop (A based on B based on A)
     *                           prices nothing, and a chain is followed for at most MAX_DERIVED_DEPTH lists
     */
    private function listPrice(PriceList $list, PricedRow $row, \Closure $reference, array $path = []): ?PriceCandidate
    {
        if ($list->currency !== $this->context->currency) {
            return null;
        }

        // The default list is the ordinary price itself: its discount items discount the base price, however it was reached
        // (as the currency's list, a customer's list, or an agreement's named list), never the ordinary price it gives.
        if ($list->is_default_for_currency) {
            $reference = fn (): ?PriceCandidate => $this->raw($row);
        }

        $source = $this->listSource($list);

        if (in_array($list->id, $path, true) || count($path) >= self::MAX_DERIVED_DEPTH) {
            $this->considered[] = ['source' => $source->value, 'list_id' => $list->id, 'reason' => in_array($list->id, $path, true) ? 'derived_loop' : 'derived_too_deep'];

            return null;
        }

        /** @var Collection<int, PriceListItem> $items */
        $items = $this->items["{$list->id}:{$row->article->id}"] ?? collect();
        $eligible = $this->ladder($row, $items->all(), fn (): int => 0, fn (PriceListItem $item): string => $item->valid_from?->toDateString() ?? '', $source, $list->id, null);

        foreach ($eligible as $item) {
            $candidate = $this->itemPrice($row, $item, $source, $reference, $list->prices_include_vat, $list->id, null);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        if ($list->derived_percent_bp === null) {
            return null;
        }

        $basis = $list->based_on_list_id === null
            ? $this->raw($row)
            : (isset($this->lists[$list->based_on_list_id]) ? $this->listPrice($this->lists[$list->based_on_list_id], $row, $reference, [...$path, $list->id]) : null);

        if ($basis === null) {
            return null;
        }

        $vat = $row->article->vat_rate;
        $sourceNet = $basis->effectiveMinor();
        $value = $list->prices_include_vat
            ? ($basis->includesVat && $basis->discountBp === 0 && $basis->grossMinor !== null ? $basis->grossMinor : $this->toGross($sourceNet, $vat))
            : $sourceNet;

        $derived = max(0, $list->rounding_rule->apply(max(0, PriceCandidate::scale($value, 10000 + $list->derived_percent_bp, 10000))));

        return new PriceCandidate(
            source: $source,
            unitPriceMinor: $list->prices_include_vat ? $this->toNet($derived, $vat) : $derived,
            includesVat: $list->prices_include_vat,
            grossMinor: $list->prices_include_vat ? $derived : null,
            listId: $list->id,
            tierMinMilli: 0,
            baseMinor: $sourceNet,
            derived: true,
            converted: $basis->converted,
        );
    }

    /** What a list's price is called in an explanation: a campaign, the currency's default list, or a customer's list. */
    private function listSource(PriceList $list): PriceSource
    {
        if ($list->kind === PriceListKind::Campaign) {
            return PriceSource::Campaign;
        }

        return $list->is_default_for_currency ? PriceSource::CurrencyList : PriceSource::CustomerList;
    }

    /**
     * The items of a list or agreement that the row's quantity reaches, best
     * first.
     *
     * @template T of PriceListItem|PriceAgreementItem
     *
     * @param  array<int, T>  $items
     * @param  \Closure(T): int  $specificity
     * @param  \Closure(T): (int|string)  $validFrom
     * @return list<T>
     */
    private function ladder(PricedRow $row, array $items, \Closure $specificity, \Closure $validFrom, PriceSource $source, ?int $listId, ?int $agreementId): array
    {
        $rowPerStock = $this->perStock($row->article, $row->unit);
        $ranked = [];

        foreach ($items as $item) {
            $unit = $item->unit_id === null ? $this->salesUnit($row->article) : ($this->units[$item->unit_id] ?? null);
            $itemPerStock = $unit === null ? null : $this->perStock($row->article, $unit);

            if ($itemPerStock === null || $rowPerStock === null) {
                $this->considered[] = array_filter(['source' => $source->value, 'list_id' => $listId, 'agreement_id' => $agreementId, 'item_id' => $item->id, 'reason' => 'unit_not_convertible'], fn (mixed $value): bool => $value !== null);

                continue;
            }

            // The step in the row's unit: min (item unit) × row-per-stock / item-per-stock.
            $threshold = BigRational::of($item->min_quantity_milli)->multipliedBy($rowPerStock)->dividedBy($itemPerStock);

            if ($threshold->compareTo($row->quantityMilli) > 0) {
                continue;
            }

            $ranked[] = [$item, $specificity($item), $threshold, $unit === $row->unit ? 1 : 0, $validFrom($item)];
        }

        usort($ranked, fn (array $a, array $b): int => ($b[1] <=> $a[1])
            ?: $b[2]->compareTo($a[2])
            ?: ($b[3] <=> $a[3])
            ?: ($b[4] <=> $a[4])
            ?: ($a[0]->id <=> $b[0]->id));

        return array_map(fn (array $entry) => $entry[0], $ranked);
    }

    /** @param  \Closure(): ?PriceCandidate  $reference */
    private function itemPrice(PricedRow $row, PriceListItem|PriceAgreementItem $item, PriceSource $source, \Closure $reference, bool $includesVat, ?int $listId, ?int $agreementId): ?PriceCandidate
    {
        if ($item->price_minor !== null) {
            $unit = $item->unit_id === null ? $this->salesUnit($row->article) : (string) $this->units[$item->unit_id];
            $itemPerStock = $this->perStock($row->article, $unit);
            $rowPerStock = $this->perStock($row->article, $row->unit);

            if ($itemPerStock === null || $rowPerStock === null) {
                return null;
            }

            $perUnit = BigRational::of($item->price_minor)->multipliedBy($itemPerStock)->dividedBy($rowPerStock)->toScale(0, RoundingMode::HalfUp)->toInt();

            return new PriceCandidate(
                source: $source,
                unitPriceMinor: $includesVat ? $this->toNet($perUnit, $row->article->vat_rate) : $perUnit,
                includesVat: $includesVat,
                grossMinor: $includesVat ? $perUnit : null,
                listId: $listId,
                itemId: $item->id,
                agreementId: $agreementId,
                tierMinMilli: $item->min_quantity_milli,
            );
        }

        if ($item instanceof PriceAgreementItem && $item->base_price_list_id !== null) {
            $list = $this->lists[$item->base_price_list_id] ?? null;
            $base = $list === null ? null : $this->listPrice($list, $row, $reference);
        } else {
            $base = $reference();
        }

        if ($base === null) {
            $this->considered[] = array_filter(['source' => $source->value, 'list_id' => $listId, 'agreement_id' => $agreementId, 'item_id' => $item->id, 'reason' => 'reference_missing'], fn (mixed $value): bool => $value !== null);

            return null;
        }

        $baseMinor = $base->effectiveMinor();

        return new PriceCandidate(
            source: $source,
            unitPriceMinor: $baseMinor,
            discountBp: (int) $item->discount_bp,
            listId: $listId ?? ($item instanceof PriceAgreementItem ? $item->base_price_list_id : null),
            itemId: $item->id,
            agreementId: $agreementId,
            tierMinMilli: $item->min_quantity_milli,
            baseMinor: $baseMinor,
            converted: $base->converted,
        );
    }

    private function toNet(int $gross, int $vatBp): int
    {
        return PriceCandidate::scale($gross, 10000, 10000 + $vatBp);
    }

    private function toGross(int $net, int $vatBp): int
    {
        return PriceCandidate::scale($net, 10000 + $vatBp, 10000);
    }

    private function salesUnit(Article $article): string
    {
        return $this->units[$article->sales_unit_id ?? $article->stock_unit_id ?? 0] ?? (string) $article->unit;
    }

    private function stockUnit(Article $article): string
    {
        return $this->units[$article->stock_unit_id ?? 0] ?? (string) $article->unit;
    }

    /** How much of $unit one stock unit of the article is; null when it does not convert. */
    private function perStock(Article $article, string $unit): ?BigRational
    {
        $key = "{$article->id}:{$unit}";

        if (! array_key_exists($key, $this->perStock)) {
            $conversions = isset($this->conversions[$article->id]) ? ArticleUnits::fractions($this->conversions[$article->id]) : [];
            $this->perStock[$key] = UnitConverter::enteredPerStock($this->stockUnit($article), $unit, $conversions);
        }

        return $this->perStock[$key];
    }

    private function rate(): ?ExchangeRate
    {
        if (! $this->rateLoaded) {
            $this->rateLoaded = true;
            $this->rate = $this->rates->rate($this->context->currency, $this->context->date->toDateString());
        }

        return $this->rate;
    }
}
