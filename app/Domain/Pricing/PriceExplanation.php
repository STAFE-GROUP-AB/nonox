<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PriceAgreement;
use App\Models\PriceList;
use App\Support\Currency\ExchangeRate;
use App\Support\Money\Money;

/**
 * A row's price explanation in Swedish, for "Varför detta pris?" (ADR-082
 * P3). The stored explanation holds ids, keys and amounts only; the names of
 * the lists and agreements are looked up here, inside the current company,
 * when it is shown. An id that is not the company's is simply not found and
 * shown without a name, so an explanation sent from a browser can never
 * reveal another company's data.
 */
final class PriceExplanation
{
    /**
     * @param  array<string, mixed>  $explanation
     * @return array{source: string, summary: string, details: list<string>, considered: list<string>, warnings: list<string>}
     */
    public static function describe(array $explanation, string $currency, string $unit = ''): array
    {
        $considered = array_values(array_filter((array) ($explanation['considered'] ?? []), is_array(...)));
        $listIds = [];
        $agreementIds = [];

        foreach ([$explanation, ...$considered] as $entry) {
            if (is_numeric($entry['list_id'] ?? null)) {
                $listIds[] = (int) $entry['list_id'];
            }

            if (is_numeric($entry['agreement_id'] ?? null)) {
                $agreementIds[] = (int) $entry['agreement_id'];
            }
        }

        $lists = $listIds === [] ? collect() : PriceList::query()->whereIn('id', array_unique($listIds))->get(['id', 'code', 'name', 'valid_to'])->keyBy('id');
        $agreements = $agreementIds === [] ? collect() : PriceAgreement::query()->whereIn('id', array_unique($agreementIds))->get(['id', 'number', 'title'])->keyBy('id');

        $name = function (array $entry) use ($lists, $agreements): string {
            $source = PriceSource::tryFrom((string) ($entry['source'] ?? ''));
            $agreement = is_numeric($entry['agreement_id'] ?? null) ? $agreements->get((int) $entry['agreement_id']) : null;
            $list = is_numeric($entry['list_id'] ?? null) ? $lists->get((int) $entry['list_id']) : null;

            return match (true) {
                $agreement instanceof PriceAgreement => __('Prisavtal :number, :title', ['number' => $agreement->number, 'title' => $agreement->title]),
                $list instanceof PriceList && $source === PriceSource::Campaign && $list->valid_to !== null => __('Kampanj :name, gäller till :date', ['name' => $list->name, 'date' => $list->valid_to->toDateString()]),
                $list instanceof PriceList => __(':label :name (:code)', ['label' => $source?->label() ?? __('Prislista'), 'name' => $list->name, 'code' => $list->code]),
                $source !== null => $source->label(),
                default => __('Okänd källa'),
            };
        };

        $money = fn (int $minor): string => Money::ofOre($minor)->format(withCurrency: false).' '.($currency === 'SEK' ? 'kr' : $currency);
        $source = PriceSource::tryFrom((string) ($explanation['source'] ?? ''));

        return [
            'source' => $source?->label() ?? __('Okänd källa'),
            'summary' => self::summary($source, $name($explanation)),
            'details' => self::details($explanation, $money, $unit),
            'considered' => array_map(fn (array $entry): string => __(':name: :price – :reason', [
                'name' => $name($entry),
                'price' => self::candidatePrice($entry, $money),
                'reason' => self::reason((string) ($entry['reason'] ?? '')),
            ]), $considered),
            'warnings' => array_map(self::warning(...), array_values(array_filter((array) ($explanation['warnings'] ?? []), is_string(...)))),
        ];
    }

    /** A warning key (ResolvedPrice::WARNING_*) as Swedish text. */
    public static function warning(string $key): string
    {
        return match ($key) {
            ResolvedPrice::WARNING_CONVERTED => __('Omräknat från kronor med Riksbankens kurs, det finns inget pris i valutan.'),
            ResolvedPrice::WARNING_NO_PRICE => __('Det finns inget pris för artikeln i valutan. Skriv ett pris.'),
            ResolvedPrice::WARNING_NO_RATE => __('Riksbanken har ingen kurs för valutan på datumet.'),
            ResolvedPrice::WARNING_UNIT => __('Enheten går inte att räkna om från artikelns enhet. Skriv ett pris.'),
            ResolvedPrice::WARNING_UNKNOWN_ARTICLE => __('Artikeln finns inte.'),
            default => __('Kontrollera priset.'),
        };
    }

    private static function summary(?PriceSource $source, string $name): string
    {
        return match ($source) {
            PriceSource::Manual => __('Priset är skrivet för hand och ändras inte av prislistorna.'),
            PriceSource::Base => __('Artikelns grundpris.'),
            PriceSource::Converted => __('Artikelns grundpris, omräknat till dokumentets valuta.'),
            null => __('Priset har ingen känd källa.'),
            default => __('Priset kommer från :name.', ['name' => $name]),
        };
    }

    /**
     * @param  array<string, mixed>  $explanation
     * @param  callable(int): string  $money
     * @return list<string>
     */
    private static function details(array $explanation, callable $money, string $unit): array
    {
        $details = [];

        if (is_numeric($explanation['tier_min_milli'] ?? null) && (int) $explanation['tier_min_milli'] > 0) {
            $details[] = __('Mängdpris från :quantity :unit.', ['quantity' => self::quantity((int) $explanation['tier_min_milli']), 'unit' => $unit]);
        }

        if (is_numeric($explanation['gross_minor'] ?? null)) {
            $gross = $money((int) $explanation['gross_minor']);
            $details[] = ($explanation['net_rounded_down'] ?? false) === true
                ? __('Listans pris är :gross inklusive moms. À-priset utan moms är uträknat från det med radens momssats och avrundat nedåt till hela öre, så att kunden aldrig betalar mer än listans pris.', ['gross' => $gross])
                : __('Listans pris är :gross inklusive moms. Raden visar à-priset utan moms.', ['gross' => $gross]);
        }

        if (($explanation['derived'] ?? false) === true) {
            $details[] = __('Priset är räknat fram från en annan prislista.');
        }

        if (is_numeric($explanation['rate_micros'] ?? null)) {
            $details[] = __('Omräknat med kursen :rate kronor från :date.', [
                'rate' => (new ExchangeRate('XXX', (string) ($explanation['rate_date'] ?? ''), (int) $explanation['rate_micros'], 'riksbank'))->format(),
                'date' => (string) ($explanation['rate_date'] ?? ''),
            ]);
        }

        if (is_numeric($explanation['folded_discount_bp'] ?? null)) {
            $details[] = __('Den avtalade rabatten :percent ingår i à-priset, eftersom raden har en egen rabatt.', ['percent' => self::percent((int) $explanation['folded_discount_bp'])]);
        }

        if (is_numeric($explanation['manual_discount_bp'] ?? null)) {
            $details[] = __('Rabatten :percent är skriven för hand.', ['percent' => self::percent((int) $explanation['manual_discount_bp'])]);
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  callable(int): string  $money
     */
    private static function candidatePrice(array $entry, callable $money): string
    {
        if (! is_numeric($entry['unit_price_minor'] ?? null)) {
            return '–';
        }

        $discount = (int) ($entry['discount_bp'] ?? 0);

        return $discount > 0
            ? __(':price med :percent rabatt', ['price' => $money((int) $entry['unit_price_minor']), 'percent' => self::percent($discount)])
            : $money((int) $entry['unit_price_minor']);
    }

    private static function reason(string $reason): string
    {
        return match ($reason) {
            'agreement_wins' => __('prisavtalet gäller före'),
            'campaign_lower' => __('en kampanj var lägre'),
            'not_lower' => __('inte lägre'),
            'customer_list_wins' => __('kundens prislista gäller före'),
            'currency_mismatch' => __('annan valuta'),
            'not_valid' => __('gäller inte på datumet'),
            'derived_loop' => __('listan bygger på sig själv'),
            'derived_too_deep' => __('listan bygger på för många andra listor'),
            'unit_not_convertible' => __('enheten går inte att räkna om'),
            'reference_missing' => __('priset som rabatten utgår från saknas'),
            default => __('användes inte'),
        };
    }

    private static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',').' %';
    }

    private static function quantity(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, ',', "\u{a0}"), '0'), ',');
    }
}
