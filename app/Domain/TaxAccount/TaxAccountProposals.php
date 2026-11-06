<?php

declare(strict_types=1);

namespace App\Domain\TaxAccount;

use App\Domain\Company\LegalForm;

/**
 * The counter account NONOX proposes for a transaction on the tax account
 * that is not in the books (ADR-054 stage 3). The 1630 side is always
 * account 1630; this is only the other side, and only a proposal: a person
 * chooses the account and confirms every posting.
 *
 * A text is proposed only when Skatteverket's own examples show it: the
 * RAML example transaktionerResponse.json (Skattekonto 2.1.0) and the
 * example file of Skattekontoutdrag 1.2. Anything else, kostnadsränta
 * included (no source shows its text yet), is left for the person to
 * choose. The accounts are BAS 2026. account() gives the usual BAS account;
 * ReconcileTaxAccount preselects it only when it is active in the company's
 * chart, and picks between 2730 and 2731 for employer contributions
 * (contributions()).
 */
final class TaxAccountProposals
{
    public const int TAX_ACCOUNT = 1630;

    public const int BANK = 1930;

    public const int PRELIMINARY_TAX_PAID = 2518;

    public const int VAT = 2650;

    public const int EMPLOYEE_TAX = 2710;

    /** BAS: Avräkning lagstadgade sociala avgifter, in the seeded charts. */
    public const int EMPLOYER_CONTRIBUTIONS = 2731;

    /** BAS: Lagstadgade sociala avgifter och särskild löneskatt, where the payroll module books the liability (SalaryVoucher::CONTRIBUTIONS_LIABILITY). */
    public const int EMPLOYER_CONTRIBUTIONS_PAYROLL = 2730;

    public const int TAX_FREE_INTEREST = 8314;

    public const int TAX_INTEREST_EXPENSE = 8423;

    /** The accounts shown first in the account list, in this order. */
    public const array COMMON = [self::BANK, 2510, self::PRELIMINARY_TAX_PAID, self::VAT, self::EMPLOYEE_TAX, self::EMPLOYER_CONTRIBUTIONS_PAYROLL, self::EMPLOYER_CONTRIBUTIONS, self::TAX_FREE_INTEREST, self::TAX_INTEREST_EXPENSE];

    /**
     * @param  int  $amount  the amount on the tax account in kronor, minus = a debit of tax
     */
    public static function account(string $text, int $amount, ?LegalForm $legalForm): ?int
    {
        $text = trim($text);

        if ($amount === 0) {
            return null;
        }

        // "Inbetalning bokförd 190412": money paid in, from the company's bank account.
        if ($amount > 0 && preg_match('/^Inbetalning bokförd\b/iu', $text) === 1) {
            return self::BANK;
        }

        // "Moms okt 2025", "Moms sep 2025": the VAT of a period, paid or to get back, against the VAT settlement account.
        if (preg_match('/^Moms\s+\p{L}+\s+\d{4}$/iu', $text) === 1) {
            return self::VAT;
        }

        // "Arbetsgivaravgift mars 2019": the employer contributions of a month.
        if ($amount < 0 && preg_match('/^Arbetsgivaravgift\s+\p{L}+\s+\d{4}$/iu', $text) === 1) {
            return self::EMPLOYER_CONTRIBUTIONS;
        }

        // "Avdragen skatt mars 2019": the tax withheld from employees in a month. Without the month the text is also used for the owner's own final tax (Skattekontoutdrag example), so it is not proposed.
        if ($amount < 0 && preg_match('/^Avdragen skatt\s+\p{L}+\s+\d{4}$/iu', $text) === 1) {
            return self::EMPLOYEE_TAX;
        }

        // TODO(legal-verify): for a sole trader or a partnership the preliminary tax and the interest on the tax account are the owners' own, not the company's; booking them (for example as a private withdrawal) is left to the person until verified.
        if (! self::taxedItself($legalForm)) {
            return null;
        }

        // "Debiterad preliminärskatt" (Skatteverket's example also spells it "prelimniärskatt").
        if ($amount < 0 && preg_match('/^Debiterad prel\p{L}*skatt$/iu', $text) === 1) {
            return self::PRELIMINARY_TAX_PAID;
        }

        // "Intäktsränta", "Korrigerad intäktsränta". TODO(legal-verify): interest income on the tax account is assumed tax-free (BAS 8314); confirm against inkomstskattelagen before the proposal leaves Beta.
        if ($amount > 0 && preg_match('/^(Korrigerad\s+)?intäktsränta$/iu', $text) === 1) {
            return self::TAX_FREE_INTEREST;
        }

        return null;
    }

    /**
     * The account for employer contributions on the tax account: the one the
     * payroll module books the liability on (2730) when payroll is on and the
     * account is in the chart; else the one of 2730 and 2731 that is in the
     * chart; with both, the one with a credit balance (a liability to clear),
     * and none when that does not tell them apart.
     *
     * @param  list<int>  $active  active account numbers among 2730 and 2731
     * @param  array<int, int>  $balances  balances in öre, debit positive, by account number
     */
    public static function contributions(array $active, bool $payroll, array $balances): ?int
    {
        $payrollAccount = in_array(self::EMPLOYER_CONTRIBUTIONS_PAYROLL, $active, true);
        $bas = in_array(self::EMPLOYER_CONTRIBUTIONS, $active, true);

        if ($payrollAccount && ($payroll || ! $bas)) {
            return self::EMPLOYER_CONTRIBUTIONS_PAYROLL;
        }

        if (! $payrollAccount) {
            return $bas ? self::EMPLOYER_CONTRIBUTIONS : null;
        }

        $payrollCredit = ($balances[self::EMPLOYER_CONTRIBUTIONS_PAYROLL] ?? 0) < 0;
        $basCredit = ($balances[self::EMPLOYER_CONTRIBUTIONS] ?? 0) < 0;

        return match (true) {
            $payrollCredit && ! $basCredit => self::EMPLOYER_CONTRIBUTIONS_PAYROLL,
            $basCredit && ! $payrollCredit => self::EMPLOYER_CONTRIBUTIONS,
            default => null,
        };
    }

    /** A legal person pays its own income tax; a sole trader and a partnership do not. */
    private static function taxedItself(?LegalForm $legalForm): bool
    {
        return in_array($legalForm, [LegalForm::Ab, LegalForm::EkonomiskForening, LegalForm::IdeellForening, LegalForm::Stiftelse], true);
    }
}
