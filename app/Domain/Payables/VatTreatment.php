<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * Who accounts for the VAT on a supplier invoice. On a domestic invoice the
 * supplier charges VAT, and the buyer deducts it on 2641. In the other cases
 * the buyer accounts for the VAT: the cost goes on the BAS account that feeds
 * the right box of the VAT return (20–24), output VAT on 2614/2624/2634
 * (boxes 30–32) and the same amount as input VAT on 2645 or 2647 (box 48).
 * Sources: Skatteverket, "Fylla i momsdeklarationen"; BAS 2026 v2 (ADR-016).
 *
 * Goods from outside the EU (NonEuGoods): the supplier charges no Swedish
 * VAT and the buyer does not calculate any from the invoice either. The
 * import VAT is based on the customs value on Tullverket's tullräkning and is
 * booked from it (App\Domain\Vat\ImportVat, ADR-057).
 */
enum VatTreatment: string
{
    case Domestic = 'DOMESTIC';
    case EuGoods = 'EU_GOODS';
    case EuServices = 'EU_SERVICES';
    case NonEuServices = 'NON_EU_SERVICES';
    case NonEuGoods = 'NON_EU_GOODS';
    case SeReverseGoods = 'SE_REVERSE_GOODS';
    case SeReverseServices = 'SE_REVERSE_SERVICES';

    /** BAS 2026 v2 names of the accounts this may need, verbatim. */
    public const array ACCOUNT_NAMES = [
        2614 => 'Utgående moms omvänd betalskyldighet, 25 %',
        2624 => 'Utgående moms omvänd betalningsskyldighet 12 %',
        2634 => 'Utgående moms omvänd betalningsskyldighet, 6 %',
        2645 => 'Beräknad ingående moms på förvärv från utlandet',
        2647 => 'Ingående moms omvänd betalningsskyldighet varor och tjänster i Sverige',
        4415 => 'Inköp av råvaror och material i Sverige, omvänd betalningsskyldighet, 25 % moms',
        4416 => 'Inköp av råvaror och material i Sverige, omvänd betalningsskyldighet, 12 % moms',
        4417 => 'Inköp av råvaror och material i Sverige, omvänd betalningsskyldighet, 6 % moms',
        4425 => 'Inköp av tjänster i Sverige, omvänd betalningsskyldighet, 25 % moms',
        4426 => 'Inköp av tjänster i Sverige, omvänd betalningsskyldighet, 12 % moms',
        4427 => 'Inköp av tjänster i Sverige, omvänd betalningsskyldighet, 6 % moms',
        4515 => 'Inköp av råvaror och material från annat EU-land, 25 %',
        4516 => 'Inköp av råvaror och material från annat EU-land, 12 %',
        4517 => 'Inköp av råvaror och material från annat EU-land, 6 %',
        4531 => 'Inköp av tjänster från ett land utanför EU, 25 % moms',
        4532 => 'Inköp av tjänster från ett land utanför EU, 12 % moms',
        4533 => 'Inköp av tjänster från ett land utanför EU, 6 % moms',
        4535 => 'Inköp av tjänster från annat EU-land, 25 %',
        4536 => 'Inköp av tjänster från annat EU-land, 12 %',
        4537 => 'Inköp av tjänster från annat EU-land, 6 %',
    ];

    public const array RATES = [2500, 1200, 600];

    public function label(): string
    {
        return match ($this) {
            self::Domestic => __('Svensk faktura med moms'),
            self::EuGoods => __('Varor från ett annat EU-land'),
            self::EuServices => __('Tjänster från ett annat EU-land'),
            self::NonEuServices => __('Tjänster från ett land utanför EU'),
            self::NonEuGoods => __('Varor från ett land utanför EU (import)'),
            self::SeReverseGoods => __('Varor i Sverige, omvänd betalningsskyldighet'),
            self::SeReverseServices => __('Tjänster i Sverige, omvänd betalningsskyldighet (till exempel bygg)'),
        };
    }

    /**
     * Whether the VAT is reported for the period the invoice is issued even
     * under kontantmetoden: an intra-Community acquisition of goods (ML
     * 2023:200 7 kap. 26 and 40 §§). Kontantmetoden (7 kap. 16 § andra st.)
     * covers only 16 kap. 6 and 8-16 §§, not the acquisition under 16 kap.
     * 17 § (enligt granskning 2026-11-02, juristgranskning återstår
     * (AUD-FAKT-4, #50)).
     */
    public function isReportedWhenInvoiced(): bool
    {
        return $this === self::EuGoods;
    }

    /**
     * Whether the buyer, not the supplier, accounts for the VAT on the
     * invoice itself. Not for goods from outside the EU: their VAT follows
     * the tullräkning, not the invoice (ADR-057).
     */
    public function buyerIsLiable(): bool
    {
        return $this !== self::Domestic && $this !== self::NonEuGoods;
    }

    /** Whether the invoice may carry Swedish VAT charged by the supplier. */
    public function chargesSwedishVat(): bool
    {
        return $this === self::Domestic;
    }

    /** The cost account for a row at the given rate, or null when the user chooses it. */
    public function costAccount(int $rate): ?int
    {
        $offset = match ($rate) {
            2500 => 0,
            1200 => 1,
            600 => 2,
            default => null,
        };

        $base = match ($this) {
            self::Domestic, self::NonEuGoods => null,
            self::EuGoods => 4515,
            self::EuServices => 4535,
            self::NonEuServices => 4531,
            self::SeReverseGoods => 4415,
            self::SeReverseServices => 4425,
        };

        return $base === null || $offset === null ? null : $base + $offset;
    }

    public function outputVatAccount(int $rate): int
    {
        return match ($rate) {
            1200 => 2624,
            600 => 2634,
            default => 2614,
        };
    }

    public function inputVatAccount(): int
    {
        return match ($this) {
            self::SeReverseGoods, self::SeReverseServices => 2647,
            default => 2645,
        };
    }
}
