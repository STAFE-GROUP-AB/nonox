<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Import VAT on goods from outside the EU, booked from Tullverket's
 * tullräkning or tullkvitto (ADR-057). A VAT-registered company declares
 * it in its own momsdeklaration: the base in box 50, the output VAT in box
 * 60, 61 or 62 by rate, and the same amount as input VAT in box 48.
 *
 * The base is not the supplier's invoice. It is the customs value
 * Tullverket fixed, plus the duty and other charges Tullverket takes, plus
 * the bikostnader (transport, packaging, insurance, commission) up to the
 * first destination in Sweden that the customs value does not already
 * contain (ML 8 kap. 24–27 §§, SOURCES). The person types those amounts from
 * the tullräkning; nothing here guesses them.
 *
 * The voucher moves the base onto the BAS account that feeds box 50
 * (4085–4087 for trading goods, 4545–4547 for raw materials, by rate) and
 * back off the group's parent account (4080 or 4540), so the result is not
 * touched: the goods themselves are booked from the supplier's invoice, and
 * the duty and the forwarder's fee from their own invoices, as costs.
 *
 * TODO(legal-verify): BAS 2026 has no account for the counter-entry of the
 * import base (older charts had a "Justering, omvänd moms" account in group
 * 45). NONOX uses the parent accounts 4080 and 4540; confirm with BAS or an
 * accountant that this is acceptable.
 * TODO(legal-verify): a lowering decision from Tullverket is booked on
 * the decision's date; whether it belongs to that period or to the
 * original tullräkning's period is not verified (ML 7 kap. 27 § names only
 * the tullräkning).
 * TODO(legal-verify): full deduction is assumed. A company with blandad
 * verksamhet, or goods not used in taxable business, may deduct less
 * (ML 13 kap.); the person then changes the 2645 row before posting.
 */
final class ImportVat
{
    public const string GOODS = 'handelsvaror';

    public const string MATERIALS = 'ravaror';

    public const array KINDS = [self::GOODS, self::MATERIALS];

    public const array RATES = [2500, 1200, 600];

    /** BAS 2026: Beräknad ingående moms på förvärv från utlandet (box 48). */
    public const int INPUT_VAT = 2645;

    /** Output VAT on import by rate (boxes 60, 61, 62). */
    public const array OUTPUT_VAT = [2500 => 2615, 1200 => 2625, 600 => 2635];

    /** The account the base goes on, per kind and rate (box 50). */
    public const array BASE_ACCOUNTS = [
        self::GOODS => [2500 => 4085, 1200 => 4086, 600 => 4087],
        self::MATERIALS => [2500 => 4545, 1200 => 4546, 600 => 4547],
    ];

    /** The parent account the base is moved off, so the voucher leaves the result alone. */
    public const array COUNTER_ACCOUNTS = [self::GOODS => 4080, self::MATERIALS => 4540];

    /** Names copied verbatim from BAS kontoplan 2026 (bas.se, BAS_kontoplan_2026_v2.xlsx). */
    public const array ACCOUNT_NAMES = [
        2615 => 'Utgående moms import av varor, 25 %',
        2625 => 'Utgående moms import av varor, 12 %',
        2635 => 'Utgående moms import av varor, 6 %',
        2645 => 'Beräknad ingående moms på förvärv från utlandet',
        4080 => 'Import av handelsvaror',
        4085 => 'Import av handelsvaror, 25 % moms',
        4086 => 'Import av handelsvaror, 12 % moms',
        4087 => 'Import av handelsvaror, 6 % moms',
        4540 => 'Import av råvaror och material',
        4545 => 'Import av råvaror och material, 25 % moms',
        4546 => 'Import av råvaror och material, 12 % moms',
        4547 => 'Import av råvaror och material, 6 % moms',
    ];

    /**
     * The rules this follows, with where they come from. None is marked as
     * legally verified; they are quoted from the primary sources.
     *
     * @var list<array{rule: string, source: string, url: string, quote: string, read: string, status: string}>
     */
    public const array SOURCES = [
        [
            'rule' => 'base',
            'source' => 'Mervärdesskattelag (2023:200) 8 kap. 24–25 §§',
            'url' => 'https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/',
            'quote' => 'Beskattningsunderlaget vid import utgörs av varans värde för tulländamål, fastställt av Tullverket, enligt unionstullkodexen. […] I beskattningsunderlaget ska det även ingå tull och andra statliga skatter eller avgifter, utom skatt enligt denna lag, som 1. tas ut av Tullverket vid import, och 2. inte redan ingår i varans värde.',
            'read' => '2026-11-06',
            'status' => 'TODO(legal-verify)',
        ],
        [
            'rule' => 'incidental_costs',
            'source' => 'Mervärdesskattelag (2023:200) 8 kap. 26–27 §§',
            'url' => 'https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/',
            'quote' => 'I beskattningsunderlaget ska det dessutom ingå bikostnader som provisions-, emballage-, transport- och försäkringskostnader som uppkommer fram till första bestämmelseorten här i landet.',
            'read' => '2026-11-06',
            'status' => 'TODO(legal-verify)',
        ],
        [
            'rule' => 'period',
            'source' => 'Mervärdesskattelag (2023:200) 7 kap. 27 § och 41 §',
            'url' => 'https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/',
            'quote' => 'Utgående skatt som hänför sig till import av varor ska redovisas för den redovisningsperiod då Tullverket har ställt ut en tullräkning eller ett tullkvitto för importen. […] Ingående skatt som hänför sig till import av varor ska dras av för den redovisningsperiod då utgående skatt för den import som avdraget avser ska redovisas.',
            'read' => '2026-11-06',
            'status' => 'TODO(legal-verify)',
        ],
        [
            'rule' => 'boxes',
            'source' => 'Skatteverket, Köpa varor från länder utanför EU',
            'url' => 'https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/inkopfranlanderutanforeu/kopavarorfranlanderutanforeuimport.4.361dc8c15312eff6fd37208.html',
            'quote' => 'beskattningsunderlaget i fält 50 (i svenska kronor) […] beräknad utgående moms i fält 60–62, beroende på vilken momssats som gäller för den vara som du har importerat. […] Du får göra avdrag för den moms som du själv har beräknat enligt de allmänna reglerna för ingående moms i fält 48.',
            'read' => '2026-11-06',
            'status' => 'TODO(legal-verify)',
        ],
        [
            'rule' => 'method',
            'source' => 'Skatteverket, Köpa varor från länder utanför EU',
            'url' => 'https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/inkopfranlanderutanforeu/kopavarorfranlanderutanforeuimport.4.361dc8c15312eff6fd37208.html',
            'quote' => 'Du redovisar den utgående momsen i den redovisningsperiod då Tullverket har ställt ut tullräkningen […] Det gäller oavsett om du använder bokslutsmetoden eller faktureringsmetoden.',
            'read' => '2026-11-06',
            'status' => 'TODO(legal-verify)',
        ],
        [
            'rule' => 'rate',
            'source' => 'Skatteverket, Köpa varor från länder utanför EU',
            'url' => 'https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/inkopfranlanderutanforeu/kopavarorfranlanderutanforeuimport.4.361dc8c15312eff6fd37208.html',
            'quote' => 'Momssatsen vid import är samma som vid försäljning i Sverige.',
            'read' => '2026-11-06',
            'status' => 'TODO(legal-verify)',
        ],
    ];

    /**
     * The accounts that carry the base into box 50. They are for the
     * tullräkning's voucher only, never the supplier's invoice.
     *
     * @return list<int>
     */
    public static function baseAccounts(): array
    {
        return array_merge(...array_map(array_values(...), array_values(self::BASE_ACCOUNTS)));
    }

    /**
     * The base of one goods line: customs value + duty and charges + bikostnader.
     */
    public static function base(int $customsValue, int $duty, int $incidental): int
    {
        return $customsValue + $duty + $incidental;
    }

    /**
     * The voucher rows for a tullräkning, debit and credit in öre. Each line
     * is one rate's goods: the customs value, the duty and the bikostnader
     * in kronor as on the tullräkning. The VAT is the rate on the sum of
     * that rate's bases, rounded half up to öre. $reduce books the rows the
     * other way round, for a decision from Tullverket that lowers an earlier
     * base (the amounts are the reduction).
     *
     * @param  list<array{rate: int, customs_value: int, duty: int, incidental: int}>  $lines
     * @return list<array{account: int, debit_ore: int, credit_ore: int}>
     */
    public static function rows(string $kind, array $lines, bool $reduce = false): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown import kind {$kind}.");
        }

        $bases = [];

        foreach ($lines as $line) {
            if (! in_array($line['rate'], self::RATES, true)) {
                throw new InvalidArgumentException("Unknown VAT rate {$line['rate']}.");
            }

            $bases[$line['rate']] = ($bases[$line['rate']] ?? 0) + self::base($line['customs_value'], $line['duty'], $line['incidental']);
        }

        $rows = [];
        $totalBase = 0;
        $totalVat = 0;

        foreach (self::RATES as $rate) {
            $base = $bases[$rate] ?? 0;

            if ($base === 0) {
                continue;
            }

            $vat = Money::ofOre($base)->percent($rate)->ore;
            $totalBase += $base;
            $totalVat += $vat;
            $rows[] = self::row(self::BASE_ACCOUNTS[$kind][$rate], $base, $reduce);
            $rows[] = self::row(self::OUTPUT_VAT[$rate], -$vat, $reduce);
        }

        if ($totalBase === 0) {
            return [];
        }

        $rows[] = self::row(self::COUNTER_ACCOUNTS[$kind], -$totalBase, $reduce);
        $rows[] = self::row(self::INPUT_VAT, $totalVat, $reduce);

        return $rows;
    }

    /**
     * The accounts the rows of this kind and these rates need.
     *
     * @param  list<int>  $rates
     * @return list<int>
     */
    public static function accountsFor(string $kind, array $rates): array
    {
        $accounts = [self::COUNTER_ACCOUNTS[$kind], self::INPUT_VAT];

        foreach (array_unique($rates) as $rate) {
            $accounts[] = self::BASE_ACCOUNTS[$kind][$rate];
            $accounts[] = self::OUTPUT_VAT[$rate];
        }

        return $accounts;
    }

    /** @return array{account: int, debit_ore: int, credit_ore: int} */
    private static function row(int $account, int $amount, bool $reduce): array
    {
        $amount = $reduce ? -$amount : $amount;

        return ['account' => $account, 'debit_ore' => max($amount, 0), 'credit_ore' => max(-$amount, 0)];
    }
}
