<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Models\FiscalYear;

/**
 * The schablonintäkt on periodiseringsfonder (tax allocation reserves) for
 * INK2S 4.6 a, field 7654.
 *
 * Inkomstskattelag (1999:1229) 30 kap. 6 a § (Lag 2018:1206): a legal person
 * with periodiseringsfonder takes up a standard income equal to the
 * statslåneränta at the end of November of the year before the calendar year
 * in which the tax year ends, at least 0,5 %, times the sum of the deductions
 * for the funds it has at the start of the tax year; adjusted in proportion
 * when the tax year is longer or shorter than twelve months.
 * https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/inkomstskattelag-19991229_sfs-1999-1229/
 * (läst 2026-11-02). Skatteverket, "Periodiseringsfond" for aktiebolag
 * (https://www.skatteverket.se/foretag/drivaforetag/foretagsformer/aktiebolag/periodiseringsfond,
 * läst 2026-11-02): reported in INK2 point 4.6 a, never booked.
 *
 * Applied enligt granskning 2026-11-02, juristgranskning återstår
 * (BS-AUD-BOK-8, #83).
 *
 * TODO(legal-verify): the rates below; that the opening balance of BAS
 * 2110–2149 equals the deductions made (a fund taken over or booked at
 * another amount must be corrected by hand in field 7654); the rounding to
 * whole kronor (half up here); the month count for a broken tax year.
 */
final class TaxAllocationReserves
{
    /** BAS accounts for periodiseringsfonder (credit balances). */
    public const array ACCOUNTS = [2110, 2149];

    /**
     * Statslåneräntan at the end of November, in hundredths of a per cent,
     * by that year. A tax year ending in year Y uses the rate of Y - 1.
     *
     * @var array<int, int>
     */
    public const array RATES = [
        2024 => 196,
        2025 => 255,
    ];

    public const string SOURCE = 'Skatteverket, Periodiseringsfond (aktiebolag): statslåneräntan vid utgången av november 2024 var 1,96 % och vid utgången av november 2025 2,55 %, https://www.skatteverket.se/foretag/drivaforetag/foretagsformer/aktiebolag/periodiseringsfond (läst 2026-11-02)';

    /** 30 kap. 6 a § första stycket sista meningen: at least 0,5 %. */
    public const int MINIMUM_RATE = 50;

    /** The rate in hundredths of a per cent for a tax year, or null when it is not in the data. */
    public static function rateFor(FiscalYear $fiscalYear): ?int
    {
        $rate = self::RATES[$fiscalYear->end_date->year - 1] ?? null;

        return $rate === null ? null : max($rate, self::MINIMUM_RATE);
    }

    /** The funds at the start of the year in öre, as a positive amount. */
    public static function openingFundsOre(AnnualAccounts $accounts): int
    {
        return max(-$accounts->openingOf(self::ACCOUNTS[0], self::ACCOUNTS[1]), 0);
    }

    /** The months of the tax year, for the proportional adjustment. */
    public static function months(FiscalYear $fiscalYear): int
    {
        return (int) round($fiscalYear->start_date->diffInMonths($fiscalYear->end_date->addDay()));
    }

    /**
     * The standard income in öre, or null when the company has funds but
     * the rate for the year is not known.
     */
    public static function standardIncomeOre(FiscalYear $fiscalYear, AnnualAccounts $accounts): ?int
    {
        $funds = self::openingFundsOre($accounts);

        if ($funds === 0) {
            return 0;
        }

        $rate = self::rateFor($fiscalYear);

        if ($rate === null) {
            return null;
        }

        return intdiv($funds * $rate * self::months($fiscalYear) + 60_000, 120_000);
    }
}
