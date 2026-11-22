<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Article;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The price a shelf label or a till shows for an article (ADR-082 §10): the
 * resolver's price for the POS channel, no customer, in SEK, on the sale
 * date (today), as a gross price including VAT. Many articles are priced in
 * a fixed number of queries per chunk of CHUNK articles.
 *
 * The gross price is the list's own gross price when the list includes VAT
 * (less an agreed percentage, rounded down), otherwise the net unit price
 * plus VAT at the article's rate, rounded half up to öre as before.
 * An article the resolver finds no price for is left out; the caller then
 * keeps the article's base price.
 *
 * TODO(legal-verify): P-L2 — no earlier "lowest price in the last 30 days"
 * is computed or shown for a campaign price: the resolver prices only the
 * date asked for and the history of list items and base prices is not
 * complete enough to give the lowest price of the previous 30 days.
 * TODO(legal-verify): P-L3 — a jämförpris is not derived here; a label's
 * comparison price stays LabelData::comparison() on the shown price.
 */
final class ChannelPrices
{
    public const int CHUNK = 500;

    public function __construct(private readonly PriceResolver $resolver) {}

    /**
     * @param  iterable<Article>  $articles  the company's articles; must be called inside CurrentCompany::run()
     * @return array<int, int> gross price in öre by article id
     */
    public function gross(iterable $articles, ?int $channelListId = null, ?CarbonImmutable $date = null): array
    {
        $articles = Collection::make($articles)->keyBy('id');

        if ($articles->isEmpty()) {
            return [];
        }

        $context = new PriceContext(
            companyId: (int) CurrentCompany::id(),
            date: $date ?? CarbonImmutable::now(),
            currency: 'SEK',
            customerId: null,
            channel: PriceChannel::Pos,
            channelListId: $channelListId,
        );
        $result = [];

        foreach ($articles->chunk(self::CHUNK) as $chunk) {
            $requests = array_values($chunk->map(fn (Article $article): PriceRequest => new PriceRequest("a{$article->id}", $article->id))->all());

            foreach ($this->resolver->resolveMany($context, $requests) as $price) {
                $article = $articles->get((int) substr($price->key, 1));

                if ($article instanceof Article && ($gross = self::grossOf($price, $article->vat_rate)) !== null) {
                    $result[$article->id] = $gross;
                }
            }
        }

        return $result;
    }

    public static function grossOf(ResolvedPrice $price, int $vatBp): ?int
    {
        if ($price->unitPriceMinor === null || $price->source === null) {
            return null;
        }

        if ($price->includesVat && $price->grossUnitPriceMinor !== null && $price->source !== PriceSource::Manual) {
            return BigRational::of($price->grossUnitPriceMinor)->multipliedBy(10000 - $price->discountBp)->dividedBy(10000)->toScale(0, RoundingMode::Floor)->toInt();
        }

        $net = $price->discountBp > 0 ? PriceCandidate::scale($price->unitPriceMinor, 10000 - $price->discountBp, 10000) : $price->unitPriceMinor;

        return BigRational::of($net)->multipliedBy(10000 + $vatBp)->dividedBy(10000)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
