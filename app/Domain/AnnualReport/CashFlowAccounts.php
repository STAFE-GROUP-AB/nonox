<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * Which part of the K3 cash flow statement each BAS account belongs to
 * (ADR-059 A.4 S1-P8; rule sheet S1-R2.1). The statement (CashFlowStatement)
 * is built from each account's movement in the year, so every account with a
 * movement must be in exactly one category here; an account in none of them,
 * or in a range whose cash effect NONOX cannot yet place (NOT_BUILT), blocks
 * the statement with a list (spec Behaviour 4). There is no residual
 * "övrigt" category: a silent misclassification would be worse than a block.
 *
 * What K3 states (quotes in docs/specs/rules/s1.md, S1-R2.1):
 * - likvida medel are cash, available bank balances and short-term liquid
 *   investments (7.4); "en utnyttjad checkräkningskredit [hör], i likhet med
 *   lån, normalt till finansieringsverksamheten" (7.4 kommentar), so 2330 and
 *   2480 are loans, never cash. NONOX uses BAS 1900–1999 as likvida medel, the
 *   default confirmed by the user (ADR-059 A.4 S1-P8);
 * - the indirect method adjusts the result for non-cash items and items whose
 *   cash effect is investing or financing (7.9 a), and adds the changes of
 *   inventory, receivables, payables and other operating items (7.9 b);
 * - income tax paid is shown separately under operating (7.17); dividends paid
 *   are financing (7.16); interest paid and received stay in operating (the
 *   7.15 option, F-S1-a assumption A7).
 *
 * TODO(legal-verify): every account range below is NONOX's mapping, not K3's
 * text. In particular: 1800–1899 short-term investments as investing rather
 * than likvida medel (7.4 c lets "kortfristiga, likvida placeringar" be cash,
 * "vanligen tre månader eller kortare"); 1970 Särskilda bankkonton and 1990
 * Redovisningsmedel inside likvida medel; 1660–1699 and 2860–2879 (amounts
 * with group companies and owners: trade or loans?); 2893 Skulder till
 * närstående personer as a loan; 1640–1649, 2250–2259, 2510–2512,
 * 2517–2519, 8910–8939 and 8980–8989 as income tax (7.17) while 2513–2516
 * (property tax, special payroll tax, yield tax) are operating payables;
 * the fixed asset groups netted per year (acquisitions and disposals not
 * shown gross); loans netted (borrowing and repayment not shown gross).
 */
final class CashFlowAccounts
{
    /** Likvida medel (K3 7.4): BAS 1900–1999, the default the user confirmed. */
    public const string CASH = 'cash';

    /** Result accounts behind "Resultat efter finansiella poster" (3000–8799) that need no adjustment. */
    public const string RESULT = 'result';

    /** Depreciation and write-downs of fixed assets (K3Schedule line depreciation), adjusted out (7.9 a). */
    public const string DEPRECIATION = 'depreciation';

    /** Accumulated depreciation and write-downs (BAS x8, x9 in 1000–1299), the other side of DEPRECIATION. */
    public const string ACCUMULATED = 'accumulated';

    public const string INTANGIBLE = 'intangible';

    public const string TANGIBLE = 'tangible';

    public const string FINANCIAL_ASSETS = 'financial_assets';

    public const string SHORT_INVESTMENTS = 'short_investments';

    public const string INVENTORY = 'inventory';

    public const string RECEIVABLES = 'receivables';

    public const string PAYABLES = 'payables';

    /** Provisions other than for taxes: their change is a non-cash item (7.9 a). */
    public const string PROVISIONS = 'provisions';

    /** Income tax, shown separately under operating (7.17): the expense and the tax accounts in the balance sheet. */
    public const string INCOME_TAX = 'income_tax';

    /** Deferred tax (1370, 2240, 8940): never cash, must net to zero. */
    public const string DEFERRED_TAX = 'deferred_tax';

    /** Untaxed reserves and their appropriations (2100–2199, 88xx): never cash, must net to zero. */
    public const string UNTAXED_RESERVES = 'untaxed_reserves';

    /** Equity (2080–2099), the year's result booked to it (8990–8999) and unpaid dividends (2898): read with K3EquityStatement. */
    public const string EQUITY = 'equity';

    /** Borrowing and repayment, used overdrafts included (financing). */
    public const string LOANS = 'loans';

    /**
     * Account ranges, first match wins: [from, to, category].
     *
     * @var list<array{0: int, 1: int, 2: string}>
     */
    public const array RANGES = [
        [1900, 1999, self::CASH],
        [1370, 1379, self::DEFERRED_TAX],
        [1300, 1399, self::FINANCIAL_ASSETS],
        [1400, 1499, self::INVENTORY],
        [1640, 1649, self::INCOME_TAX],
        [1500, 1689, self::RECEIVABLES],
        [1700, 1799, self::RECEIVABLES],
        [1800, 1899, self::SHORT_INVESTMENTS],
        [2080, 2099, self::EQUITY],
        [2100, 2199, self::UNTAXED_RESERVES],
        [2240, 2249, self::DEFERRED_TAX],
        [2250, 2259, self::INCOME_TAX],
        [2200, 2299, self::PROVISIONS],
        [2300, 2399, self::LOANS],
        [2410, 2419, self::LOANS],
        [2480, 2489, self::LOANS],
        [2400, 2499, self::PAYABLES],
        [2510, 2512, self::INCOME_TAX],
        [2513, 2516, self::PAYABLES],
        [2517, 2519, self::INCOME_TAX],
        [2600, 2799, self::PAYABLES],
        [2800, 2839, self::PAYABLES],
        [2840, 2849, self::LOANS],
        [2850, 2859, self::PAYABLES],
        [2880, 2892, self::PAYABLES],
        [2893, 2893, self::LOANS],
        [2894, 2897, self::PAYABLES],
        [2898, 2898, self::EQUITY],
        [2899, 2899, self::PAYABLES],
        [2900, 2999, self::PAYABLES],
        [7700, 7739, self::DEPRECIATION],
        [7750, 7789, self::DEPRECIATION],
        [7800, 7899, self::DEPRECIATION],
        [3000, 8799, self::RESULT],
        [8800, 8819, self::UNTAXED_RESERVES],
        [8840, 8899, self::UNTAXED_RESERVES],
        [8910, 8939, self::INCOME_TAX],
        [8940, 8949, self::DEFERRED_TAX],
        [8980, 8989, self::INCOME_TAX],
        [8990, 8999, self::EQUITY],
    ];

    /**
     * Result accounts inside "Resultat efter finansiella poster" whose cash
     * effect belongs to investing or financing, or which are not cash at
     * all, so 7.9 a requires an adjustment NONOX does not yet make. A movement
     * on one of them blocks the statement with the reason (never a silent
     * misclassification). Also group contributions (koncernbidrag).
     *
     * @var list<array{0: int, 1: int, 2: string}> [from, to, reason]
     */
    public const array NOT_BUILT = [
        [3970, 3979, 'disposal'],
        [7970, 7979, 'disposal'],
        [8020, 8039, 'financial'],
        [8070, 8089, 'financial'],
        [8120, 8139, 'financial'],
        [8170, 8189, 'financial'],
        [8220, 8249, 'financial'],
        [8270, 8289, 'financial'],
        [8320, 8329, 'financial'],
        [8350, 8359, 'financial'],
        [8370, 8389, 'financial'],
        [8450, 8469, 'financial'],
        [8480, 8489, 'financial'],
        [8820, 8839, 'group_contribution'],
    ];

    /** Fixed assets with accumulated depreciation accounts (x8, x9): BAS 1000–1299. */
    public const array DEPRECIABLE = [1000, 1299];

    /** Interest expense (BAS 8400–8429) and accrued interest (2960–2969), for "betald ränta". */
    public const array INTEREST_EXPENSE = [[8400, 8429]];

    public const array ACCRUED_INTEREST_EXPENSE = [[2960, 2969]];

    /** Interest income (BAS 8250–8269, 8310–8319) and accrued interest income (1760–1769), for "erhållen ränta". */
    public const array INTEREST_INCOME = [[8250, 8269], [8310, 8319]];

    public const array ACCRUED_INTEREST_INCOME = [[1760, 1769]];

    /** The category of an account, or null when it has none (or is NOT_BUILT). */
    public static function categoryOf(int $number): ?string
    {
        if (self::notBuilt($number) !== null) {
            return null;
        }

        if ($number >= self::DEPRECIABLE[0] && $number <= self::DEPRECIABLE[1]) {
            if (in_array($number % 10, [8, 9], true)) {
                return self::ACCUMULATED;
            }

            return $number < 1100 ? self::INTANGIBLE : self::TANGIBLE;
        }

        foreach (self::RANGES as [$from, $to, $category]) {
            if ($number >= $from && $number <= $to) {
                return $category;
            }
        }

        return null;
    }

    /** Why the statement cannot yet place an account's movement ('disposal', 'financial', 'group_contribution'), or null. */
    public static function notBuilt(int $number): ?string
    {
        foreach (self::NOT_BUILT as [$from, $to, $reason]) {
            if ($number >= $from && $number <= $to) {
                return $reason;
            }
        }

        return null;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    public static function inRanges(int $number, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($number >= $from && $number <= $to) {
                return true;
            }
        }

        return false;
    }
}
