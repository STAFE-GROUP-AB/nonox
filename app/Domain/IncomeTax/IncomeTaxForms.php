<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\IncomeTaxReturn;

/**
 * Which income tax return NONOX prepares for a company (ADR-053): INK2 for
 * a limited company or an ekonomisk förening, the NE-bilaga for a sole
 * trader, INK4 for a handelsbolag or kommanditbolag, none otherwise. One
 * place, so the page and the SRU download cannot disagree.
 */
final class IncomeTaxForms
{
    public static function for(Company $company): ?string
    {
        return match (true) {
            AnnualReportDocument::supports($company) => IncomeTaxReturn::INK2,
            $company->legal_form === LegalForm::Ef => IncomeTaxReturn::NE,
            in_array($company->legal_form, [LegalForm::Hb, LegalForm::Kb], true) => IncomeTaxReturn::INK4,
            default => null,
        };
    }

    /** A share in basis points as Swedish per cent with two decimals, without a float: 6000 is "60,00". */
    public static function percent(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).','.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Whole kronor in Swedish: "-32 345 kr", with non-breaking spaces. */
    public static function kronor(int $kronor): string
    {
        return ($kronor < 0 ? '-' : '').number_format(abs($kronor), 0, ',', "\u{a0}")."\u{a0}kr";
    }
}
