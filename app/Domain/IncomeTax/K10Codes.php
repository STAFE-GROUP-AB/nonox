<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * The field codes of blankett K10 (Kvalificerade aktier i fåmansföretag,
 * SKV 2110) for beskattningsperiod 2026P4, from Skatteverket's field table
 * K10_SKV2110-39-04-26-01.xls in "_Nyheter_from_beskattningsperiod_2026P4"
 * (Teknisk information om filöverföring, ändrad 2026-10-07), read
 * 2026-11-06. The 2026P4 edition added 4535, 4554, 4558, 4567, 4585–4588,
 * 4736 and 4747 and removed the förenklingsregel and huvudregel fields
 * (4501–4509, 4515, 4523, 4527, 4528, 4549, 4550, 4555–4557, 4560–4563,
 * 4573, 4580, 4720–4725, 4735, 4744–4746). A period not listed here is
 * refused for the SRU file (ADR-062).
 */
final class K10Codes
{
    public const array VERIFIED_PERIODS = ['2026P4'];

    public const string FIELD_TABLE = 'K10_SKV2110-39-04-26-01';

    public const string SOURCE_URL = 'https://skatteverket.se/foretag/inkomstdeklaration/forredovisningsbyraer/tekniskinformationomfiloverforing.4.13948c0e18e810bfa0cca8.html';

    public const int COMPANY_ORG_NUMBER = 4530;

    public const int SHARES_OWNED = 4531;

    public const int SHARES_TOTAL = 4532;

    /** Form point => field code, in the order of the form. */
    public const array FIELDS = [
        '1.1' => 4511,
        '1.2' => 4521,
        '1.3' => 4520,
        '1.4' => 4522,
        '1.5' => 4730,
        '1.6' => 4524,
        '2.1' => 4525,
        '2.2' => 4731,
        '2.3' => 4526,
        '2.4' => 4732,
        '2.5' => 4733,
        '2.6' => 4734,
        '2.7' => 4736,
        '2.8' => 4529,
        '2.9' => 4540,
        '2.10' => 4541,
        '4.1' => 4570,
        '4.2' => 4571,
        '4.3' => 4572,
        '4.4' => 4576,
        '4.5' => 4577,
        '4.6' => 4578,
        '4.7' => 4579,
        '4.8' => 4583,
        '4.9' => 4584,
        '4.10' => 4585,
        '4.11' => 4586,
        '4.12' => 4587,
        '4.13' => 4588,
    ];

    /** The labels of the form, abridged where the form explains the arithmetic. */
    public const array LABELS = [
        '1.1' => 'Grundbelopp',
        '1.2' => 'Lönebaserat utrymme enligt avsnitt D',
        '1.3' => 'Ränta på omkostnadsbelopp över 100 000 kr',
        '1.4' => 'Sparat utdelningsutrymme från föregående år',
        '1.5' => 'Gränsbelopp som hör till överlåtna aktier',
        '1.6' => 'Gränsbelopp',
        '2.1' => 'Utdelning',
        '2.2' => 'Gränsbelopp från p. 1.6',
        '2.3' => 'Inkomst av tjänst',
        '2.4' => 'Sparat utdelningsutrymme',
        '2.5' => 'Gränsbelopp som hör till aktier överlåtna efter utdelning',
        '2.6' => 'Sparat utdelningsutrymme till nästa år',
        '2.7' => 'Utdelning inom gränsbeloppet',
        '2.8' => 'Skattepliktig del av utdelning inom gränsbeloppet (2/3)',
        '2.9' => 'Kvarvarande utdelning som beskattas i sin helhet',
        '2.10' => 'Inkomst av kapital',
        '4.1' => 'Kontant ersättning till arbetstagare i företaget',
        '4.2' => 'Kontant ersättning till arbetstagare i dotterföretag',
        '4.3' => 'Löneunderlag för aktier ägda hela året',
        '4.4' => 'Din eller er andel av löneunderlaget',
        '4.5' => 'Kontant ersättning i företaget den del av året aktierna ägts',
        '4.6' => 'Kontant ersättning i dotterföretag den del av året aktierna ägts',
        '4.7' => 'Löneunderlag för anskaffade aktier',
        '4.8' => 'Din eller er andel av löneunderlaget för anskaffade aktier',
        '4.9' => 'Totalt löneunderlag',
        '4.10' => 'Löneavdrag (8 inkomstbasbelopp)',
        '4.11' => 'Löneunderlag efter löneavdrag',
        '4.12' => 'Sammanlagt lönebaserat utrymme (50 %)',
        '4.13' => 'Ditt lönebaserade utrymme (makar)',
    ];

    public static function isVerifiedPeriod(string $period): bool
    {
        return in_array($period, self::VERIFIED_PERIODS, true);
    }
}
