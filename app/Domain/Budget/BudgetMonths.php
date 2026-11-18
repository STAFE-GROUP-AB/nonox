<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Models\FiscalYear;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The month grid of a budget and the plain rules every budget tool here uses:
 * the months of a fiscal year, the sign shown in the UI, an even spread and a
 * percentage change. Every rule is something a person can recompute by hand
 * (ADR-081 principle 4).
 */
final class BudgetMonths
{
    /** Result accounts only in v1 (owner decision 6). */
    public const int FIRST_ACCOUNT = 3000;

    /** 8999 Årets resultat is the year's result itself, never budgeted. */
    public const int LAST_ACCOUNT = 8998;

    /** The largest amount of one cell, ±10^13 öre (100 000 000 000 kr). */
    public const int MAX_CELL_ORE = 10_000_000_000_000;

    /** The most rows a CSV file or a pasted block may have. */
    public const int MAX_IMPORT_ROWS = 2000;

    /**
     * The first day of every month of the fiscal year, as 'Y-m' keys.
     *
     * @return list<string>
     */
    public static function of(FiscalYear $fiscalYear): array
    {
        $months = [];
        $month = $fiscalYear->start_date->startOfMonth();

        while ($month->lessThanOrEqualTo($fiscalYear->end_date)) {
            $months[] = $month->format('Y-m');
            $month = $month->addMonthNoOverflow();
        }

        return $months;
    }

    public static function isBudgetable(int $accountNumber): bool
    {
        return $accountNumber >= self::FIRST_ACCOUNT && $accountNumber <= self::LAST_ACCOUNT;
    }

    /**
     * Accounts whose natural side is credit, typed and shown as positive
     * revenue: class 3 and the financial income 8000–8399 (results from
     * shares, interest income). Every other result account is a cost, debit
     * positive; revenue on one of them (8400–8998) is typed with a minus.
     */
    public static function isRevenue(int $accountNumber): bool
    {
        return ($accountNumber >= 3000 && $accountNumber <= 3999)
            || ($accountNumber >= 8000 && $accountNumber <= 8399);
    }

    /**
     * The sign people type and read (see isRevenue). The same function turns
     * a typed amount back into ledger sign.
     */
    public static function shown(int $accountNumber, int $ledgerOre): int
    {
        return self::isRevenue($accountNumber) ? -$ledgerOre : $ledgerOre;
    }

    /**
     * Reads an amount as typed into a cell, in öre; null when it is not an
     * amount or is larger than MAX_CELL_ORE.
     */
    public static function parseCell(string $typed): ?int
    {
        try {
            $ore = Money::parse($typed)->ore;
        } catch (InvalidArgumentException|MathException) {
            return null;
        }

        return abs($ore) > self::MAX_CELL_ORE ? null : $ore;
    }

    /**
     * A year's amount spread evenly in whole kronor over $months months; the
     * kronor left over go to the first months, so the months add up exactly.
     *
     * @return list<int> öre per month
     */
    public static function spreadEvenly(int $ore, int $months): array
    {
        if ($months < 1) {
            return [];
        }

        $kronor = BigDecimal::of($ore)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
        $sign = $kronor < 0 ? -1 : 1;
        $base = intdiv(abs($kronor), $months);
        $left = abs($kronor) - $base * $months;
        $spread = [];

        for ($month = 0; $month < $months; $month++) {
            $spread[] = $sign * ($base + ($month < $left ? 1 : 0)) * 100;
        }

        return $spread;
    }

    /**
     * An amount changed by a percentage in basis points (500 = +5 %), rounded
     * half up to whole kronor.
     */
    public static function adjusted(int $ore, int $basisPoints): int
    {
        return BigDecimal::of($ore)
            ->multipliedBy(10000 + $basisPoints)
            ->dividedBy(1_000_000, 0, RoundingMode::HalfUp)
            ->toInt() * 100;
    }

    /**
     * Reads a percentage as people type it ("5", "-2,5", "3.75 %") into basis
     * points; null when it is not a percentage.
     */
    public static function percent(string $input): ?int
    {
        $value = str_replace([' ', '%', ','], ['', '', '.'], trim($input));

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^[-+]?\d{1,4}(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return BigDecimal::of($value)->multipliedBy(100)->toScale(0)->toInt();
    }

    /** Months from one month key to another ('2026-01' to '2027-01' is 12). */
    public static function between(string $from, string $to): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m', $to);

        if ($start === null || $end === null) {
            return 0;
        }

        return ((int) $end->format('Y') - (int) $start->format('Y')) * 12 + (int) $end->format('n') - (int) $start->format('n');
    }

    public static function shift(string $month, int $months): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m', $month);

        return $date === null ? $month : $date->addMonthsNoOverflow($months)->format('Y-m');
    }
}
