<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use Carbon\CarbonImmutable;

/**
 * The employer declaration's days by the law (REGLER.md §2), for a payment
 * month "Y-m": declared the month after, on the 12th (the 17th in January
 * and August) when the VAT base is up to 40 million kronor or the company is
 * not VAT registered (SFL 26 kap. 26 och 32 §§), on the 26th (the 27th in
 * December) above it (26 kap. 30 §); paid on the declaration day, or for a
 * large employer on the 12th (the 17th in January), two weeks before (62
 * kap. 3 §). Moved to the next weekday (Workdays). Used by Mina datum and by
 * the payroll module's AgiDeadlines.
 */
final class AgiDates
{
    public static function declaration(string $period, bool $large): CarbonImmutable
    {
        $next = CarbonImmutable::parse("{$period}-01")->addMonthNoOverflow();

        return $large ? Workdays::onDay($next, 26, [12 => 27]) : Workdays::onDay($next, 12, [1 => 17, 8 => 17]);
    }

    public static function payment(string $period, bool $large): CarbonImmutable
    {
        return $large
            ? Workdays::onDay(CarbonImmutable::parse("{$period}-01")->addMonthNoOverflow(), 12, [1 => 17])
            : self::declaration($period, false);
    }
}
