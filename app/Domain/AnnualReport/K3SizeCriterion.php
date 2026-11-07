<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The three size criteria of ÅRL 1 kap. 3 § första stycket 4 a–c and K2
 * 1.1C a–c, in the order the law lists them.
 */
enum K3SizeCriterion: string
{
    case Employees = 'employees';
    case BalanceSheetTotal = 'balance_sheet_total';
    case NetTurnover = 'net_turnover';

    public function label(): string
    {
        return match ($this) {
            self::Employees => __('medelantal anställda'),
            self::BalanceSheetTotal => __('balansomslutning'),
            self::NetTurnover => __('nettoomsättning'),
        };
    }
}
