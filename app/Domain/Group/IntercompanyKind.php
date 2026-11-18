<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * What an intra-group voucher row is (ADR-060, addendum 2026-11-07, 2.3):
 * the kind decides which elimination pair the row's amount goes into in
 * the consolidation (S2-1.4, S2-1.5). The first six have the same values as
 * MarkKind, the account marks of the per-user overview, so a mark becomes a
 * kind without translation.
 */
enum IntercompanyKind: string
{
    case Sales = 'SALES';
    case Purchases = 'PURCHASES';
    case Receivable = 'RECEIVABLE';
    case Payable = 'PAYABLE';
    case LoanReceivable = 'LOAN_RECEIVABLE';
    case LoanPayable = 'LOAN_PAYABLE';
    case InterestIncome = 'INTEREST_INCOME';
    case InterestExpense = 'INTEREST_EXPENSE';
    case DividendIncome = 'DIVIDEND_INCOME';
    case DividendDecided = 'DIVIDEND_DECIDED';
    case GroupContributionReceived = 'GROUP_CONTRIBUTION_RECEIVED';
    case GroupContributionGiven = 'GROUP_CONTRIBUTION_GIVEN';
    case Shares = 'SHARES';
    case Equity = 'EQUITY';

    public function label(): string
    {
        return match ($this) {
            self::Sales => __('Försäljning till koncernföretag'),
            self::Purchases => __('Inköp från koncernföretag'),
            self::Receivable => __('Fordran på koncernföretag'),
            self::Payable => __('Skuld till koncernföretag'),
            self::LoanReceivable => __('Lån till koncernföretag'),
            self::LoanPayable => __('Lån från koncernföretag'),
            self::InterestIncome => __('Ränteintäkt från koncernföretag'),
            self::InterestExpense => __('Räntekostnad till koncernföretag'),
            self::DividendIncome => __('Utdelning från koncernföretag'),
            self::DividendDecided => __('Beslutad utdelning till koncernföretag'),
            self::GroupContributionReceived => __('Mottaget koncernbidrag'),
            self::GroupContributionGiven => __('Lämnat koncernbidrag'),
            self::Shares => __('Andelar i koncernföretag'),
            self::Equity => __('Eget kapital i dotterföretag'),
        };
    }

    public static function fromMark(MarkKind $mark): self
    {
        return self::from($mark->value);
    }
}
