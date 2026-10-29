<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * Account types from AGENTS.md §6.1.
 */
enum AccountType: string
{
    case Asset = 'ASSET';
    case LiabilityEquity = 'LIABILITY_EQUITY';
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';

    /**
     * The default type from the first digit of a BAS account number:
     * 1 assets, 2 equity and liabilities, 3 revenue, 4-9 expenses and financial items.
     */
    public static function forNumber(int $number): self
    {
        return match (intdiv($number, 1000)) {
            1 => self::Asset,
            2 => self::LiabilityEquity,
            3 => self::Revenue,
            default => self::Expense,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Tillgång'),
            self::LiabilityEquity => __('Eget kapital och skulder'),
            self::Revenue => __('Intäkt'),
            self::Expense => __('Kostnad'),
        };
    }
}
