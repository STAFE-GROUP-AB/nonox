<?php

declare(strict_types=1);

namespace App\Domain\Vat;

/**
 * The boxes of Skatteverket's momsdeklaration (AGENTS.md §8.2) and which BAS
 * accounts feed each, with the element names of the eSKD upload file.
 *
 * Sales and output VAT count credit minus debit; purchases and input VAT
 * count debit minus credit. Box 49 is calculated. Boxes are numbered as
 * integers and shown with two digits (05).
 *
 * Boxes 06 (3401–3403 Egna uttag momspliktiga) and 08 (3913 Frivilligt
 * momspliktiga hyresintäkter) follow their BAS 2026 accounts. Box 07 has no
 * account: its base is derived from the VMB VAT on 2616, 2626 and 2636
 * (VatReturn). Box 37 has no BAS account; a company ties an account of its
 * own to it (accounts.vat_box, #64).
 *
 * TODO(legal-verify): the account-to-box mapping follows the BAS 2026
 * chart's names. Confirm it against the current BAS momsrapport and
 * Skatteverket's box definitions.
 */
final class VatBoxes
{
    public const int PAYABLE = 49;

    public const int INPUT_VAT = 48;

    public const string CREDIT = 'credit';

    public const string DEBIT = 'debit';

    /**
     * @var array<int, array{label: string, element: string, side: 'credit'|'debit', accounts: list<array{0: int, 1: int}>}>
     */
    public const array BOXES = [
        5 => ['label' => 'Momspliktig försäljning som inte ingår i ruta 06, 07 eller 08', 'element' => 'ForsMomsEjAnnan', 'side' => self::CREDIT, 'accounts' => [[3000, 3003], [3005, 3099], [3914, 3914]]],
        6 => ['label' => 'Momspliktiga uttag', 'element' => 'UttagMoms', 'side' => self::CREDIT, 'accounts' => [[3401, 3403]]],
        7 => ['label' => 'Beskattningsunderlag vid vinstmarginalbeskattning', 'element' => 'UlagMargbesk', 'side' => self::CREDIT, 'accounts' => []],
        8 => ['label' => 'Hyresinkomster vid frivillig beskattning', 'element' => 'HyrinkomstFriv', 'side' => self::CREDIT, 'accounts' => [[3913, 3913]]],
        10 => ['label' => 'Utgående moms 25 %', 'element' => 'MomsUtgHog', 'side' => self::CREDIT, 'accounts' => [[2610, 2613], [2616, 2616]]],
        11 => ['label' => 'Utgående moms 12 %', 'element' => 'MomsUtgMedel', 'side' => self::CREDIT, 'accounts' => [[2620, 2623], [2626, 2626]]],
        12 => ['label' => 'Utgående moms 6 %', 'element' => 'MomsUtgLag', 'side' => self::CREDIT, 'accounts' => [[2630, 2633], [2636, 2636]]],
        20 => ['label' => 'Inköp av varor från ett annat EU-land', 'element' => 'InkopVaruAnnatEg', 'side' => self::DEBIT, 'accounts' => [[4515, 4517]]],
        21 => ['label' => 'Inköp av tjänster från ett annat EU-land enligt huvudregeln', 'element' => 'InkopTjanstAnnatEg', 'side' => self::DEBIT, 'accounts' => [[4535, 4537]]],
        22 => ['label' => 'Inköp av tjänster från ett land utanför EU', 'element' => 'InkopTjanstUtomEg', 'side' => self::DEBIT, 'accounts' => [[4531, 4533]]],
        23 => ['label' => 'Inköp av varor i Sverige som köparen är skattskyldig för', 'element' => 'InkopVaruSverige', 'side' => self::DEBIT, 'accounts' => [[4415, 4417]]],
        24 => ['label' => 'Övriga inköp av tjänster', 'element' => 'InkopTjanstSverige', 'side' => self::DEBIT, 'accounts' => [[4425, 4427]]],
        30 => ['label' => 'Utgående moms 25 % på inköp i ruta 20–24', 'element' => 'MomsInkopUtgHog', 'side' => self::CREDIT, 'accounts' => [[2614, 2614]]],
        31 => ['label' => 'Utgående moms 12 % på inköp i ruta 20–24', 'element' => 'MomsInkopUtgMedel', 'side' => self::CREDIT, 'accounts' => [[2624, 2624]]],
        32 => ['label' => 'Utgående moms 6 % på inköp i ruta 20–24', 'element' => 'MomsInkopUtgLag', 'side' => self::CREDIT, 'accounts' => [[2634, 2634]]],
        50 => ['label' => 'Beskattningsunderlag vid import', 'element' => 'MomsUlagImport', 'side' => self::DEBIT, 'accounts' => [[4545, 4547]]],
        60 => ['label' => 'Utgående moms 25 % på import', 'element' => 'MomsImportUtgHog', 'side' => self::CREDIT, 'accounts' => [[2615, 2615]]],
        61 => ['label' => 'Utgående moms 12 % på import', 'element' => 'MomsImportUtgMedel', 'side' => self::CREDIT, 'accounts' => [[2625, 2625]]],
        62 => ['label' => 'Utgående moms 6 % på import', 'element' => 'MomsImportUtgLag', 'side' => self::CREDIT, 'accounts' => [[2635, 2635]]],
        35 => ['label' => 'Försäljning av varor till ett annat EU-land', 'element' => 'ForsVaruAnnatEg', 'side' => self::CREDIT, 'accounts' => [[3108, 3108]]],
        36 => ['label' => 'Försäljning av varor utanför EU', 'element' => 'ForsVaruUtomEg', 'side' => self::CREDIT, 'accounts' => [[3105, 3105]]],
        37 => ['label' => 'Mellanmans inköp av varor vid trepartshandel', 'element' => 'InkopVaruMellan3p', 'side' => self::DEBIT, 'accounts' => []],
        38 => ['label' => 'Mellanmans försäljning av varor vid trepartshandel', 'element' => 'ForsVaruMellan3p', 'side' => self::CREDIT, 'accounts' => [[3107, 3107]]],
        39 => ['label' => 'Försäljning av tjänster till näringsidkare i ett annat EU-land', 'element' => 'ForsTjSkskAnnatEg', 'side' => self::CREDIT, 'accounts' => [[3308, 3308]]],
        40 => ['label' => 'Övrig försäljning av tjänster omsatta utanför Sverige', 'element' => 'ForsTjOvrUtomEg', 'side' => self::CREDIT, 'accounts' => [[3305, 3305]]],
        41 => ['label' => 'Försäljning när köparen är betalningsskyldig i Sverige', 'element' => 'ForsKopareSkskSverige', 'side' => self::CREDIT, 'accounts' => [[3231, 3231]]],
        42 => ['label' => 'Övrig försäljning m.m.', 'element' => 'ForsOvrigt', 'side' => self::CREDIT, 'accounts' => [[3004, 3004]]],
        // 2648 Vilande ingående moms is not deductible yet (ML 7 kap. 31 §), so it is not in box 48.
        48 => ['label' => 'Ingående moms att dra av', 'element' => 'MomsIngAvdr', 'side' => self::DEBIT, 'accounts' => [[2640, 2647], [2649, 2649]]],
    ];

    public const int TAXABLE_SALES = 5;

    public const int MARGIN_SCHEME = 7;

    /**
     * Output VAT under vinstmarginalbeskattning (BAS 2026: Utgående moms VMB
     * 25/12/6 %), with its rate. Box 07 is the base the VAT is calculated
     * on, so it is the VAT / rate (Skatteverket, fält 07).
     *
     * TODO(legal-verify): a negative margin is reported as 0 per sale; a
     * credit that brings a period's VMB VAT below zero is not clamped here.
     */
    public const array MARGIN_VAT = [2616 => 2500, 2626 => 1200, 2636 => 600];

    /**
     * The boxes an account can be tied to instead of the box its number
     * gives (accounts.vat_box, #64): the base boxes for sales and purchases.
     * VAT boxes are not offered; they follow the BAS VAT accounts, which the
     * settlement moves to 2650.
     */
    public const array ACCOUNT_BOXES = [5, 6, 8, 20, 21, 22, 23, 24, 35, 36, 37, 38, 39, 40, 41, 42, 50];

    /** The accounts that may be tied to a box: revenue and costs, not balance sheet or VAT accounts. */
    public const array ACCOUNTS_WITH_BOX = [3000, 8999];

    public const int EXEMPT_SALES = 42;

    /**
     * Output VAT on taxable sales in Sweden (BAS 2026: 2611, 2621, 2631 and
     * their group accounts), with its rate in basis points. A voucher that
     * books VAT here has a taxable base of VAT / rate, which box 05 must
     * show whatever revenue account carries the sale (#61).
     */
    public const array SALE_VAT = [2610 => 2500, 2611 => 2500, 2620 => 1200, 2621 => 1200, 2630 => 600, 2631 => 600];

    /**
     * Revenue accounts that are never part of a taxable sale's base in box
     * 05 without being mapped: VMB and reverse charge (3200–3299, their base
     * is reported elsewhere), own withdrawals (3400–3499, box 06), revenue
     * corrections, rounding and excise (3700–3799), exchange gains (3960),
     * gains on fixed assets (3970–3979, the gain is not the price) and
     * public grants (3980–3989, box 42 at most).
     *
     * TODO(legal-verify): a sale of a fixed asset belongs in box 05 at its
     * price; the price is booked against the asset account, not on a revenue
     * account, so it shows as output VAT without a base (VatReturn::$unexplained).
     */
    public const array NOT_SALES = [[3200, 3299], [3400, 3499], [3700, 3799], [3960, 3989]];

    /** Output VAT boxes, summed against box 48 into box 49. */
    public const array OUTPUT_VAT = [10, 11, 12, 30, 31, 32, 60, 61, 62];

    /**
     * The BAS group of VAT accounts (2610–2649). Only the accounts in it that
     * feed an output VAT box or box 48 are moved to 2650 by the settlement;
     * the rest, such as vilande moms on 2618, 2628, 2638 and 2648, stay where
     * they are and are shown as a warning (ADR-010, #58).
     *
     * TODO(legal-verify): 2649 Ingående moms, blandad verksamhet is read into
     * box 48 as in the BAS chart; confirm that its balance is the deductible
     * share and not an amount still to be apportioned.
     */
    public const array VAT_ACCOUNTS = [2610, 2649];

    public const int SETTLEMENT_ACCOUNT = 2650;

    public const int ROUNDING_ACCOUNT = 3740;

    /** The element of box 49, "Moms att betala eller få tillbaka". */
    public const string PAYABLE_ELEMENT = 'MomsBetala';

    /**
     * Whether the settlement moves this account to 2650: it is a VAT account
     * that feeds an output VAT box (10–12, 30–32, 60–62) or box 48.
     */
    public static function isSettled(int $account): bool
    {
        $box = self::boxFor($account);

        return $box !== null && ($box === self::INPUT_VAT || in_array($box, self::OUTPUT_VAT, true));
    }

    public static function canHaveBox(int $account): bool
    {
        return $account >= self::ACCOUNTS_WITH_BOX[0] && $account <= self::ACCOUNTS_WITH_BOX[1];
    }

    public static function isVatAccount(int $account): bool
    {
        return $account >= self::VAT_ACCOUNTS[0] && $account <= self::VAT_ACCOUNTS[1];
    }

    /**
     * A revenue account without a box that may carry a taxable sale in
     * Sweden: its credit counts in box 05 as far as the output VAT on the
     * same voucher explains it (#61). 3990 Övriga ersättningar och intäkter,
     * 3500–3699 fakturerade kostnader and sidointäkter are examples.
     */
    public static function isOtherRevenue(int $account, ?int $override = null): bool
    {
        if ($account < 3000 || $account > 3999 || self::boxFor($account, $override) !== null) {
            return false;
        }

        foreach (self::NOT_SALES as [$from, $to]) {
            if ($account >= $from && $account <= $to) {
                return false;
            }
        }

        return true;
    }

    /**
     * The box the account feeds: the box it is tied to (accounts.vat_box),
     * otherwise the box its number falls in.
     */
    public static function boxFor(int $account, ?int $override = null): ?int
    {
        if ($override !== null && self::canHaveBox($account) && in_array($override, self::ACCOUNT_BOXES, true)) {
            return $override;
        }

        foreach (self::BOXES as $box => $definition) {
            foreach ($definition['accounts'] as [$from, $to]) {
                if ($account >= $from && $account <= $to) {
                    return $box;
                }
            }
        }

        return null;
    }
}
