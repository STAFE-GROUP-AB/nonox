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
 * TODO(legal-verify): the account-to-box mapping follows the BAS chart's
 * VAT accounts. Confirm it against the current BAS momsrapport and
 * Skatteverket's box definitions, and decide boxes 06, 07, 08, 37 and 38,
 * which have no account here yet.
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
        5 => ['label' => 'Momspliktig försäljning som inte ingår i ruta 06, 07 eller 08', 'element' => 'ForsMomsEjAnnan', 'side' => self::CREDIT, 'accounts' => [[3000, 3003], [3005, 3099]]],
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
        35 => ['label' => 'Försäljning av varor till ett annat EU-land', 'element' => 'ForsVaruAnnatEg', 'side' => self::CREDIT, 'accounts' => [[3106, 3106]]],
        36 => ['label' => 'Försäljning av varor utanför EU', 'element' => 'ForsVaruUtomEg', 'side' => self::CREDIT, 'accounts' => [[3105, 3105]]],
        39 => ['label' => 'Försäljning av tjänster till näringsidkare i ett annat EU-land', 'element' => 'ForsTjSkskAnnatEg', 'side' => self::CREDIT, 'accounts' => [[3308, 3308]]],
        40 => ['label' => 'Övrig försäljning av tjänster omsatta utanför Sverige', 'element' => 'ForsTjOvrUtomEg', 'side' => self::CREDIT, 'accounts' => [[3305, 3305]]],
        41 => ['label' => 'Försäljning när köparen är betalningsskyldig i Sverige', 'element' => 'ForsKopareSkskSverige', 'side' => self::CREDIT, 'accounts' => [[3231, 3231]]],
        42 => ['label' => 'Övrig försäljning m.m.', 'element' => 'ForsOvrigt', 'side' => self::CREDIT, 'accounts' => [[3004, 3004]]],
        48 => ['label' => 'Ingående moms att dra av', 'element' => 'MomsIngAvdr', 'side' => self::DEBIT, 'accounts' => [[2640, 2649]]],
    ];

    /** Output VAT boxes, summed against box 48 into box 49. */
    public const array OUTPUT_VAT = [10, 11, 12, 30, 31, 32, 60, 61, 62];

    /** The VAT accounts that the settlement moves to 2650. */
    public const array VAT_ACCOUNTS = [2610, 2649];

    public const int SETTLEMENT_ACCOUNT = 2650;

    public const int ROUNDING_ACCOUNT = 3740;

    /** The element of box 49, "Moms att betala eller få tillbaka". */
    public const string PAYABLE_ELEMENT = 'MomsBetala';

    public static function boxFor(int $account): ?int
    {
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
