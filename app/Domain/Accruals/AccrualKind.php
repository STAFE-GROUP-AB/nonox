<?php

declare(strict_types=1);

namespace App\Domain\Accruals;

/**
 * The four kinds of periodisering. A prepaid cost or income was paid or
 * invoiced before the months it belongs to and is spread over them; an
 * accrued cost or income belongs to a month but is invoiced later, so it is
 * booked at the month's end and reversed on the first day of the next.
 */
enum AccrualKind: string
{
    case PrepaidExpense = 'PREPAID_EXPENSE';
    case PrepaidIncome = 'PREPAID_INCOME';
    case AccruedExpense = 'ACCRUED_EXPENSE';
    case AccruedIncome = 'ACCRUED_INCOME';

    public function label(): string
    {
        return match ($this) {
            self::PrepaidExpense => __('Förutbetald kostnad'),
            self::PrepaidIncome => __('Förutbetald intäkt'),
            self::AccruedExpense => __('Upplupen kostnad'),
            self::AccruedIncome => __('Upplupen intäkt'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PrepaidExpense => __('Betald i förväg, till exempel en årsförsäkring. Kostnaden fördelas över månaderna.'),
            self::PrepaidIncome => __('Fakturerad i förväg, till exempel ett årsabonnemang. Intäkten fördelas över månaderna.'),
            self::AccruedExpense => __('Hör till månaden men faktureras senare, till exempel el. Bokförs vid månadens slut och återförs nästa månad.'),
            self::AccruedIncome => __('Utfört arbete som faktureras senare. Bokförs vid månadens slut och återförs nästa månad.'),
        };
    }

    public function isSpread(): bool
    {
        return $this === self::PrepaidExpense || $this === self::PrepaidIncome;
    }

    /** +1 when the result account is a cost (debited), -1 when it is income (credited). */
    public function sign(): int
    {
        return $this === self::PrepaidExpense || $this === self::AccruedExpense ? 1 : -1;
    }

    /** The BAS balance account suggested for the kind. */
    public function balanceAccount(): int
    {
        return match ($this) {
            self::PrepaidExpense, self::AccruedIncome => 1790,
            self::PrepaidIncome, self::AccruedExpense => 2990,
        };
    }
}
