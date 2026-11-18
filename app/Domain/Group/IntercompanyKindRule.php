<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * Which kind a voucher row gets, as plain rules anyone can recompute by hand
 * (ADR-081, principle 4).
 *
 * 1. forCounterparty(): a row of a voucher made from an invoice, a payment
 *    or a supplier invoice of a marked customer or supplier. The kind follows
 *    from the side (customer or supplier) and the account's BAS range; a row
 *    outside every range (bank, VAT, exchange differences, write-offs) is
 *    not tagged. Not caught either: intra-group sales of fixed assets
 *    (12xx, 3970, 7970) and advances (1480, 2420); a person tags those in
 *    bulk until the consolidation (S2-1.4, S2-1.5). The direction (debit or credit) does not change the kind:
 *    a credit note or a payment moves the same item the other way.
 *
 *    Customer side:  1500–1599 and 1660–1669 receivable, 3000–3999 sales
 *                    except 3740–3749 (öresavrundning) and 3960–3969,
 *                    8300–8399 interest income.
 *    Supplier side:  2400–2499 and 2860–2869 payable, 4000–7999 purchases
 *                    except 7960–7969, 8400–8499 interest expense.
 *
 * 2. suggest(): the default kind for bulk tagging of manual vouchers (loans,
 *    interest, dividends, group contributions), from the BAS accounts kept
 *    for group items and, failing that, from an account name that says
 *    "koncern". Only a default: a person chooses the kind before anything
 *    is tagged.
 *
 * TODO(legal-verify): the BAS ranges and numbers above and in suggest()
 * (1320, 1560, 1660, 2360, 2460, 2860, 8010, 8820, 8830) against the BAS
 * 2026 chart; NONOX's own templates carry only 1310 and 8010 of them.
 */
final class IntercompanyKindRule
{
    public static function forCounterparty(IntercompanySide $side, int $account): ?IntercompanyKind
    {
        return match ($side) {
            IntercompanySide::Customer => match (true) {
                self::between($account, 1500, 1599), self::between($account, 1660, 1669) => IntercompanyKind::Receivable,
                self::between($account, 3740, 3749), self::between($account, 3960, 3969) => null,
                self::between($account, 3000, 3999) => IntercompanyKind::Sales,
                self::between($account, 8300, 8399) => IntercompanyKind::InterestIncome,
                default => null,
            },
            IntercompanySide::Supplier => match (true) {
                self::between($account, 2400, 2499), self::between($account, 2860, 2869) => IntercompanyKind::Payable,
                self::between($account, 7960, 7969) => null,
                self::between($account, 4000, 7999) => IntercompanyKind::Purchases,
                self::between($account, 8400, 8499) => IntercompanyKind::InterestExpense,
                default => null,
            },
        };
    }

    public static function suggest(int $account, string $name): ?IntercompanyKind
    {
        $byNumber = match (true) {
            self::between($account, 1310, 1319) => IntercompanyKind::Shares,
            self::between($account, 1320, 1329) => IntercompanyKind::LoanReceivable,
            self::between($account, 1560, 1569), self::between($account, 1660, 1669) => IntercompanyKind::Receivable,
            self::between($account, 2360, 2369) => IntercompanyKind::LoanPayable,
            self::between($account, 2460, 2469), self::between($account, 2860, 2869) => IntercompanyKind::Payable,
            self::between($account, 8010, 8019) => IntercompanyKind::DividendIncome,
            self::between($account, 8820, 8829) => IntercompanyKind::GroupContributionReceived,
            self::between($account, 8830, 8839) => IntercompanyKind::GroupContributionGiven,
            default => null,
        };

        if ($byNumber !== null) {
            return $byNumber;
        }

        $lower = mb_strtolower($name);

        if (! str_contains($lower, 'koncern')) {
            return null;
        }

        if (str_contains($lower, 'ränteintäkt')) {
            return IntercompanyKind::InterestIncome;
        }

        if (str_contains($lower, 'räntekostnad')) {
            return IntercompanyKind::InterestExpense;
        }

        $mark = MarkKind::suggest($account, $name);

        return $mark === null ? null : IntercompanyKind::fromMark($mark);
    }

    private static function between(int $account, int $from, int $to): bool
    {
        return $account >= $from && $account <= $to;
    }
}
