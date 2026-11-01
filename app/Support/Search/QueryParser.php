<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Reads the spotlight field: "faktura 1029", "lev Elverket", "konto 1930",
 * "> 10000 kr", "8 750,00", "14 okt", "oktober", "obetald", "A47". What is
 * understood becomes a filter and a chip; the rest is free text.
 */
final class QueryParser
{
    /** First word → the kinds of records it asks for. */
    public const array TYPE_WORDS = [
        'faktura' => ['invoices'], 'fakturor' => ['invoices'], 'kundfaktura' => ['invoices'], 'kundfakturor' => ['invoices'], 'fakt' => ['invoices'],
        'lev' => ['suppliers', 'supplier-invoices'], 'leverantör' => ['suppliers', 'supplier-invoices'], 'leverantörer' => ['suppliers', 'supplier-invoices'], 'leverantor' => ['suppliers', 'supplier-invoices'],
        'levfaktura' => ['supplier-invoices'], 'levfakt' => ['supplier-invoices'], 'leverantörsfaktura' => ['supplier-invoices'], 'leverantörsfakturor' => ['supplier-invoices'],
        'ver' => ['vouchers'], 'verif' => ['vouchers'], 'verifikation' => ['vouchers'], 'verifikationer' => ['vouchers'],
        'konto' => ['accounts'], 'konton' => ['accounts'],
        'kund' => ['customers'], 'kunder' => ['customers'],
        'artikel' => ['articles'], 'artiklar' => ['articles'],
        'offert' => ['quotes'], 'offerter' => ['quotes'],
        'order' => ['orders'], 'ordrar' => ['orders'],
        'anställd' => ['employees'], 'anställda' => ['employees'], 'anstalld' => ['employees'],
        'lönebesked' => ['payslips'], 'lonebesked' => ['payslips'], 'lön' => ['payslips', 'employees'],
        'personal' => ['staff'], 'personalliggare' => ['staff'],
        'inkorg' => ['inbox'], 'mejl' => ['inbox'],
        'anläggning' => ['assets'], 'anläggningar' => ['assets'], 'inventarie' => ['assets'], 'inventarier' => ['assets'],
        'periodisering' => ['accruals'], 'periodiseringar' => ['accruals'],
        'projekt' => ['projects'],
        'kst' => ['cost-centers'], 'kostnadsställe' => ['cost-centers'], 'kostnadsställen' => ['cost-centers'],
        'företag' => ['companies'],
    ];

    /** The chip for each set of types. */
    private const array TYPE_LABELS = [
        'invoices' => 'Kundfakturor', 'suppliers' => 'Leverantörer', 'supplier-invoices' => 'Leverantörsfakturor', 'vouchers' => 'Verifikationer',
        'accounts' => 'Konton', 'customers' => 'Kunder', 'articles' => 'Artiklar', 'quotes' => 'Offerter', 'orders' => 'Order',
        'employees' => 'Anställda', 'payslips' => 'Lönebesked', 'staff' => 'Personal', 'inbox' => 'Inkorg', 'assets' => 'Anläggningar',
        'accruals' => 'Periodiseringar', 'projects' => 'Projekt', 'cost-centers' => 'Kostnadsställen', 'companies' => 'Mina företag',
    ];

    /** Words for a status, longest first so "ej betald" wins over "betald". */
    private const array STATUS_WORDS = [
        'att attestera' => SearchQuery::TO_APPROVE, 'attestera' => SearchQuery::TO_APPROVE,
        'ej betalda' => SearchQuery::UNPAID, 'ej betald' => SearchQuery::UNPAID, 'obetalda' => SearchQuery::UNPAID, 'obetald' => SearchQuery::UNPAID, 'öppna' => SearchQuery::UNPAID,
        'förfallna' => SearchQuery::OVERDUE, 'förfallen' => SearchQuery::OVERDUE, 'forfallna' => SearchQuery::OVERDUE, 'forfallen' => SearchQuery::OVERDUE,
        'betalda' => SearchQuery::PAID, 'betald' => SearchQuery::PAID,
        'utkast' => SearchQuery::DRAFT,
        'bokförda' => SearchQuery::POSTED, 'bokförd' => SearchQuery::POSTED,
    ];

    private const array STATUS_LABELS = [
        SearchQuery::UNPAID => 'Obetalda', SearchQuery::OVERDUE => 'Förfallna', SearchQuery::PAID => 'Betalda',
        SearchQuery::DRAFT => 'Utkast', SearchQuery::POSTED => 'Bokförda', SearchQuery::TO_APPROVE => 'Att attestera',
    ];

    private const array MONTHS = [
        'januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'mars' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
        'maj' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'augusti' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    /** An amount in Swedish or plain notation: "8750", "8 750", "8 750,00", "8750.5". */
    private const string AMOUNT = '(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+)(?:[,.](\d{1,2}))?';

    public function __construct(private readonly ?CarbonImmutable $today = null) {}

    public function parse(string $raw): SearchQuery
    {
        $raw = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $raw)), 0, 200);
        $text = $raw;
        $chips = [];
        $types = [];
        $typeWord = null;
        $status = null;
        $amountMin = null;
        $amountMax = null;
        $dateFrom = null;
        $dateTo = null;

        // The first word may ask for a kind of record: "faktura 1029", "lev Elverket".
        if (preg_match('/^(\S+)(?:\s+|$)/u', $text, $match) === 1 && isset(self::TYPE_WORDS[mb_strtolower($match[1])])) {
            $typeWord = $match[1];
            $types = self::TYPE_WORDS[mb_strtolower($match[1])];
            $chips[] = ['kind' => 'type', 'label' => __(self::TYPE_LABELS[$types[count($types) - 1]]), 'token' => $match[1]];

            if (count($types) > 1) {
                $chips[count($chips) - 1]['label'] = __(self::TYPE_LABELS[$types[0]]).' + '.mb_strtolower(__(self::TYPE_LABELS[$types[1]]));
            }

            $text = mb_substr($text, mb_strlen($match[0]));
        }

        foreach (self::STATUS_WORDS as $word => $value) {
            if (preg_match('/(?<!\pL)'.preg_quote($word, '/').'(?!\pL)/iu', $text, $match) === 1) {
                $status = $value;
                $chips[] = ['kind' => 'status', 'label' => __(self::STATUS_LABELS[$value]), 'token' => $match[0]];
                $text = $this->remove($text, $match[0]);

                break;
            }
        }

        // "> 10000 kr", "över 5 000", "<500", "1000-2000 kr"
        $comparison = '/(>=|<=|>|<|över|under|mer än|mindre än)\s*'.self::AMOUNT.'\s*(?:kr|kronor|sek|:-)?(?!\d)/iu';
        $range = '/(?<![\d,.])'.self::AMOUNT.'\s*[-–]\s*'.self::AMOUNT.'\s*(?:kr|kronor|sek|:-)(?!\pL)/iu';
        $explicit = '/(?<![\d,.\pL])'.self::AMOUNT.'\s*(kr|kronor|sek|:-)(?!\pL)/iu';
        $decimals = '/(?<![\d,.\pL])(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+),(\d{2})(?![\d])/u';

        if (preg_match($comparison, $text, $match) === 1) {
            $ore = $this->ore($match[2], $match[3] ?? '');
            [$amountMin, $amountMax] = match (mb_strtolower($match[1])) {
                '>=' => [$ore, null],
                '>', 'över', 'mer än' => [$ore + 1, null],
                '<=' => [null, $ore],
                default => [null, $ore - 1],
            };
            $label = $amountMin !== null ? __('Belopp över :amount', ['amount' => Money::ofOre($ore)->format()]) : __('Belopp under :amount', ['amount' => Money::ofOre($ore)->format()]);
            $chips[] = ['kind' => 'amount', 'label' => $label, 'token' => $match[0]];
            $text = $this->remove($text, $match[0]);
        } elseif (preg_match($range, $text, $match) === 1) {
            $amountMin = $this->ore($match[1], $match[2]);
            $amountMax = $this->ore($match[3], $match[4] ?? '');

            if ($amountMin > $amountMax) {
                [$amountMin, $amountMax] = [$amountMax, $amountMin];
            }

            $chips[] = ['kind' => 'amount', 'label' => __('Belopp :from–:to', ['from' => Money::ofOre($amountMin)->format(false), 'to' => Money::ofOre($amountMax)->format()]), 'token' => $match[0]];
            $text = $this->remove($text, $match[0]);
        } elseif (preg_match($explicit, $text, $match) === 1 || preg_match($decimals, $text, $match) === 1) {
            $amountMin = $amountMax = $this->ore($match[1], $match[2]);
            $chips[] = ['kind' => 'amount', 'label' => __('Belopp :amount', ['amount' => Money::ofOre($amountMin)->format()]), 'token' => $match[0]];
            $text = $this->remove($text, $match[0]);
        }

        [$text, $dateFrom, $dateTo, $dateChip] = $this->date($text);

        if ($dateChip !== null) {
            $chips[] = $dateChip;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $series = null;
        $number = null;
        $impliedAmount = null;

        // "A47" or "A 47": a voucher.
        if (preg_match('/^([a-zA-Z])\s?(\d{1,7})$/u', $text, $match) === 1) {
            $series = mb_strtoupper($match[1]);
            $number = (int) $match[2];
        } elseif (preg_match('/^\d{1,9}$/', $text) === 1) {
            $number = (int) $text;
            $impliedAmount = $amountMin === null && $amountMax === null ? $number * 100 : null;
        } elseif ($amountMin === null && $amountMax === null && preg_match('/^\d{1,3}(?:[ \x{00A0}]\d{3})+$/u', $text) === 1) {
            // "8 750": a sum written with a thousands space is an amount, not a number.
            $impliedAmount = (int) preg_replace('/\D/', '', $text) * 100;
        }

        return new SearchQuery(
            raw: $raw,
            text: $text,
            types: $types,
            typeWord: $typeWord,
            amountMin: $amountMin,
            amountMax: $amountMax,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            status: $status,
            number: $number,
            impliedAmount: $impliedAmount,
            series: $series,
            chips: $chips,
        );
    }

    /**
     * "2026-10-14", "14/10", "14/10 2026", "14 okt", "14 oktober 2026", "oktober", "okt 2025".
     *
     * @return array{0: string, 1: ?CarbonImmutable, 2: ?CarbonImmutable, 3: ?array{kind: string, label: string, token: string}}
     */
    private function date(string $text): array
    {
        $today = $this->today ?? CarbonImmutable::today();
        $months = implode('|', array_keys(self::MONTHS));

        if (preg_match('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $text, $match) === 1 && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $day = CarbonImmutable::createFromDate((int) $match[1], (int) $match[2], (int) $match[3])->startOfDay();

            return [$this->remove($text, $match[0]), $day, $day, $this->dayChip($day, $match[0])];
        }

        if (preg_match('/(?<![\d,.])(\d{1,2})\/(\d{1,2})(?:[\/ ](\d{4}))?(?![\d,.])/', $text, $match) === 1) {
            $year = isset($match[3]) ? (int) $match[3] : $this->yearFor((int) $match[2], $today);

            if (checkdate((int) $match[2], (int) $match[1], $year)) {
                $day = CarbonImmutable::createFromDate($year, (int) $match[2], (int) $match[1])->startOfDay();

                return [$this->remove($text, $match[0]), $day, $day, $this->dayChip($day, $match[0])];
            }
        }

        if (preg_match('/(?<![\d\pL])(\d{1,2})\s+('.$months.')\.?(?:\s+(\d{4}))?(?!\pL)/iu', $text, $match) === 1) {
            $month = self::MONTHS[mb_strtolower($match[2])];
            $year = isset($match[3]) ? (int) $match[3] : $this->yearFor($month, $today);

            if (checkdate($month, (int) $match[1], $year)) {
                $day = CarbonImmutable::createFromDate($year, $month, (int) $match[1])->startOfDay();

                return [$this->remove($text, $match[0]), $day, $day, $this->dayChip($day, $match[0])];
            }
        }

        if (preg_match('/(?<![\d\pL])('.$months.')\.?(?:\s+(\d{4}))?(?!\pL)/iu', $text, $match) === 1) {
            $month = self::MONTHS[mb_strtolower($match[1])];
            $year = isset($match[2]) ? (int) $match[2] : $this->yearFor($month, $today);
            $from = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();
            $label = mb_convert_case($from->translatedFormat('F Y'), MB_CASE_TITLE);

            return [$this->remove($text, $match[0]), $from, $from->endOfMonth()->startOfDay(), ['kind' => 'date', 'label' => $label, 'token' => $match[0]]];
        }

        return [$text, null, null, null];
    }

    /** A month without a year is the latest one that has begun. */
    private function yearFor(int $month, CarbonImmutable $today): int
    {
        return $month <= $today->month ? $today->year : $today->year - 1;
    }

    /** @return array{kind: string, label: string, token: string} */
    private function dayChip(CarbonImmutable $day, string $token): array
    {
        return ['kind' => 'date', 'label' => $day->translatedFormat('j M Y'), 'token' => $token];
    }

    private function ore(string $kronor, string $decimals): int
    {
        $whole = (int) preg_replace('/\D/', '', $kronor);

        return $whole * 100 + (int) str_pad($decimals, 2, '0');
    }

    private function remove(string $text, string $token): string
    {
        $position = mb_strpos($text, $token);

        if ($position === false) {
            return $text;
        }

        return mb_substr($text, 0, $position).' '.mb_substr($text, $position + mb_strlen($token));
    }
}
