<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Accounting\AccountType;
use App\Domain\Company\LegalForm;
use App\Models\Account;
use App\Models\Company;

/**
 * The BAS accounts the year-end closing books on.
 *
 * TODO(legal-verify): confirm the accounts per legal form, especially for
 * partnerships (HB/KB), where equity is kept per partner, and whether 2512
 * and 2898 are the accounts to use for the estimated tax and the dividend.
 */
final class ClosingAccounts
{
    public const int TAX_EXPENSE = 8910;

    public const int TAX_LIABILITY = 2512;

    public const int YEAR_RESULT = 8999;

    public const int RETAINED = 2098;

    public const int DIVIDEND = 2898;

    public const int OWNER_EQUITY = 2010;

    /** Sole traders and partnerships: the owners are taxed, and equity is not split into restricted and free. */
    public static function isOwnerTaxed(Company $company): bool
    {
        return in_array($company->legal_form, [LegalForm::Ef, LegalForm::Hb, LegalForm::Kb], true);
    }

    /** 2099 Årets resultat for companies and associations, 2019 for sole traders and partnerships. */
    public static function resultNumber(Company $company): int
    {
        return self::isOwnerTaxed($company) ? 2019 : 2099;
    }

    /** The account, created with its BAS name if the chart lacks it. */
    public static function ensure(int $number): Account
    {
        $names = [
            self::TAX_EXPENSE => __('Skatt som belastar årets resultat'),
            self::TAX_LIABILITY => __('Beräknad inkomstskatt'),
            self::YEAR_RESULT => __('Årets resultat'),
            self::RETAINED => __('Vinst eller förlust från föregående år'),
            self::DIVIDEND => __('Outtagen vinstutdelning'),
            self::OWNER_EQUITY => __('Eget kapital'),
            2019 => __('Årets resultat'),
            2099 => __('Årets resultat'),
        ];

        return Account::query()->firstOrCreate(['number' => $number], [
            'name' => $names[$number] ?? (string) $number,
            'type' => AccountType::forNumber($number),
        ]);
    }
}
