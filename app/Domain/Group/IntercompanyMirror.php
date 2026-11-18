<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * How one company's intra-group voucher looks from the other company
 * (ADR-081 E27, paired drafts): debit and credit change sides, and an
 * account kept for a group item in BAS becomes its counterpart (a
 * receivable on a group company becomes a debt to one, a group contribution
 * given becomes one received). Every other account keeps its number, and a
 * person checks and changes the accounts before the draft is made. Plain
 * rules anyone can recompute by hand (principle 4); only a proposal.
 *
 * The ranges are the ones IntercompanyKindRule::suggest() names; the same
 * TODO(legal-verify) applies: 1320–1329 ↔ 2360–2369 (long-term loans),
 * 1560–1569 ↔ 2460–2469 (trade receivables and payables), 1660–1669 ↔
 * 2860–2869 (other short-term items), 8820–8829 ↔ 8830–8839 (group
 * contributions), against the BAS 2026 chart.
 */
final class IntercompanyMirror
{
    /** @var list<array{0: int, 1: int}> pairs of range starts, each ten accounts wide */
    private const array ACCOUNT_PAIRS = [[1320, 2360], [1560, 2460], [1660, 2860], [8820, 8830]];

    public static function account(int $number): int
    {
        foreach (self::ACCOUNT_PAIRS as [$left, $right]) {
            if ($number >= $left && $number <= $left + 9) {
                return $right + ($number - $left);
            }

            if ($number >= $right && $number <= $right + 9) {
                return $left + ($number - $right);
            }
        }

        return $number;
    }

    /**
     * Whether an account that keeps its number needs a person's look: interest
     * (8300–8499) and dividends (8010–8019) have no one-to-one counterpart in
     * BAS, so the form warns instead of guessing. TODO(legal-verify): whether
     * a fixed mirror (for example 831x ↔ 841x, 8010 ↔ 2898) is safe to propose.
     */
    public static function needsCheck(int $number): bool
    {
        return ($number >= 8300 && $number <= 8499) || ($number >= 8010 && $number <= 8019);
    }

    public static function kind(IntercompanyKind $kind): IntercompanyKind
    {
        return match ($kind) {
            IntercompanyKind::Sales => IntercompanyKind::Purchases,
            IntercompanyKind::Purchases => IntercompanyKind::Sales,
            IntercompanyKind::Receivable => IntercompanyKind::Payable,
            IntercompanyKind::Payable => IntercompanyKind::Receivable,
            IntercompanyKind::LoanReceivable => IntercompanyKind::LoanPayable,
            IntercompanyKind::LoanPayable => IntercompanyKind::LoanReceivable,
            IntercompanyKind::InterestIncome => IntercompanyKind::InterestExpense,
            IntercompanyKind::InterestExpense => IntercompanyKind::InterestIncome,
            IntercompanyKind::DividendIncome => IntercompanyKind::DividendDecided,
            IntercompanyKind::DividendDecided => IntercompanyKind::DividendIncome,
            IntercompanyKind::GroupContributionReceived => IntercompanyKind::GroupContributionGiven,
            IntercompanyKind::GroupContributionGiven => IntercompanyKind::GroupContributionReceived,
            IntercompanyKind::Shares => IntercompanyKind::Equity,
            IntercompanyKind::Equity => IntercompanyKind::Shares,
        };
    }

    /** The kinds a paired draft is for: transactions without an invoice. */
    public const array PAIRED_KINDS = [
        IntercompanyKind::LoanReceivable,
        IntercompanyKind::LoanPayable,
        IntercompanyKind::InterestIncome,
        IntercompanyKind::InterestExpense,
        IntercompanyKind::GroupContributionReceived,
        IntercompanyKind::GroupContributionGiven,
        IntercompanyKind::DividendIncome,
        IntercompanyKind::DividendDecided,
        IntercompanyKind::Receivable,
        IntercompanyKind::Payable,
    ];
}
