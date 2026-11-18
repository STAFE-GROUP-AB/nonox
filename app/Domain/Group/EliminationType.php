<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * The elimination pairs of stage 1 (ADR-060, addendum 2026-11-07, 3.2,
 * S2-1.5; spec S2 scope 4): six pairs matched from intercompany row tags,
 * shares against equity from the balances, and OTHER for a manual
 * adjustment. Each tag pair has a credit side (income or liability: the
 * rows are debited when eliminated) and a debit side (cost, asset or a
 * decided dividend: credited when eliminated).
 */
enum EliminationType: string
{
    case SalesPurchases = 'SALES_PURCHASES';
    case ReceivablePayable = 'RECEIVABLE_PAYABLE';
    case Loan = 'LOAN';
    case Interest = 'INTEREST';
    case Dividend = 'DIVIDEND';
    case GroupContribution = 'GROUP_CONTRIBUTION';
    case SharesEquity = 'SHARES_EQUITY';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SalesPurchases => __('Försäljning och inköp'),
            self::ReceivablePayable => __('Fordringar och skulder'),
            self::Loan => __('Lån'),
            self::Interest => __('Ränta'),
            self::Dividend => __('Utdelning'),
            self::GroupContribution => __('Koncernbidrag'),
            self::SharesEquity => __('Andelar och eget kapital'),
            self::Other => __('Övrig justering'),
        };
    }

    /** The tag kind on the side that is debited when eliminated (income or liability); null for pairs not matched from tags. */
    public function creditSide(): ?IntercompanyKind
    {
        return match ($this) {
            self::SalesPurchases => IntercompanyKind::Sales,
            self::ReceivablePayable => IntercompanyKind::Payable,
            self::Loan => IntercompanyKind::LoanPayable,
            self::Interest => IntercompanyKind::InterestIncome,
            self::Dividend => IntercompanyKind::DividendIncome,
            self::GroupContribution => IntercompanyKind::GroupContributionReceived,
            self::SharesEquity, self::Other => null,
        };
    }

    /** The tag kind on the side that is credited when eliminated. */
    public function debitSide(): ?IntercompanyKind
    {
        return match ($this) {
            self::SalesPurchases => IntercompanyKind::Purchases,
            self::ReceivablePayable => IntercompanyKind::Receivable,
            self::Loan => IntercompanyKind::LoanReceivable,
            self::Interest => IntercompanyKind::InterestExpense,
            self::Dividend => IntercompanyKind::DividendDecided,
            self::GroupContribution => IntercompanyKind::GroupContributionGiven,
            self::SharesEquity, self::Other => null,
        };
    }

    /** @return list<self> the pairs matched from tags */
    public static function tagged(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->creditSide() !== null));
    }

    public function kind(): GroupEntryKind
    {
        return $this === self::Other ? GroupEntryKind::Adjustment : GroupEntryKind::Elimination;
    }
}
