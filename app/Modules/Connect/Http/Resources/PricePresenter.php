<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Resources;

use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Modules\Inventory\Domain\Quantity;
use DateTimeInterface;

/**
 * Prices as the API shows them (ADR-082 P9, ADR-032): amounts are integers
 * in the minor unit of the list's or agreement's currency (öre for SEK),
 * discounts are basis points, quantities are decimal text, dates are Y-m-d.
 * The presenters read the relations they need and never query, so a list
 * costs a fixed number of queries: load priceGroups for lists, article and
 * unit for items.
 */
final class PricePresenter
{
    /**
     * Needs priceGroups loaded.
     *
     * @return array<string, mixed>
     */
    public static function list(PriceList $list): array
    {
        return [
            'id' => $list->id,
            'code' => $list->code,
            'name' => $list->name,
            'kind' => $list->kind->value,
            'currency' => $list->currency,
            'prices_include_vat' => $list->prices_include_vat,
            'valid_from' => self::date($list->valid_from),
            'valid_to' => self::date($list->valid_to),
            'is_default_for_currency' => $list->is_default_for_currency,
            'based_on_list_id' => $list->based_on_list_id,
            'derived_percent_bp' => $list->derived_percent_bp,
            'rounding_rule' => $list->rounding_rule->value,
            'channel' => $list->channel?->value,
            'group_ids' => $list->priceGroups->pluck('id')->values()->all(),
            'archived' => $list->archived_at !== null,
            'created_at' => self::time($list->created_at),
            'updated_at' => self::time($list->updated_at),
        ];
    }

    /**
     * Needs article and unit loaded.
     *
     * @return array<string, mixed>
     */
    public static function listItem(PriceListItem $item): array
    {
        return [
            'id' => $item->id,
            'price_list_id' => $item->price_list_id,
            'article_id' => $item->article_id,
            'article' => $item->article?->number,
            'unit_id' => $item->unit_id,
            'unit' => $item->unit?->code,
            'min_quantity' => Quantity::decimal($item->min_quantity_milli),
            'price_minor' => $item->price_minor,
            'discount_bp' => $item->discount_bp,
            'valid_from' => self::date($item->valid_from),
            'valid_to' => self::date($item->valid_to),
            'archived' => $item->archived_at !== null,
            'updated_at' => self::time($item->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function agreement(PriceAgreement $agreement): array
    {
        return [
            'id' => $agreement->id,
            'number' => $agreement->number,
            'reference' => $agreement->reference(),
            'customer_id' => $agreement->customer_id,
            'title' => $agreement->title,
            'currency' => $agreement->currency,
            'status' => $agreement->status->value,
            'valid_from' => self::date($agreement->valid_from),
            'valid_to' => self::date($agreement->valid_to),
            'terminated_on' => self::date($agreement->terminated_on),
            'allow_campaigns' => $agreement->allow_campaigns,
            'crm_agreement_id' => $agreement->crm_agreement_id,
            'supersedes_id' => $agreement->supersedes_id,
            'signed_at' => self::time($agreement->signed_at),
            'activation_method' => $agreement->activation_method?->value,
            'activated_at' => self::time($agreement->activated_at),
            'created_at' => self::time($agreement->created_at),
            'updated_at' => self::time($agreement->updated_at),
        ];
    }

    /**
     * Needs article and unit loaded.
     *
     * @return array<string, mixed>
     */
    public static function agreementItem(PriceAgreementItem $item): array
    {
        return [
            'id' => $item->id,
            'price_agreement_id' => $item->price_agreement_id,
            'target' => $item->applies_to_all ? 'all' : 'article',
            'article_id' => $item->article_id,
            'article' => $item->article?->number,
            'unit_id' => $item->unit_id,
            'unit' => $item->unit?->code,
            'min_quantity' => Quantity::decimal($item->min_quantity_milli),
            'price_minor' => $item->price_minor,
            'discount_bp' => $item->discount_bp,
            'base_price_list_id' => $item->base_price_list_id,
        ];
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
