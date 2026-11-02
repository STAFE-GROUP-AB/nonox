<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The kinds of ställda säkerheter in the K2 note (BFNAR 2016:10/2025:2
 * p. 18.13 a–e, ÅRL 5 kap. 14 §). For a mortgage the amount is the
 * mortgage's amount, for the others the carrying value of the asset
 * (p. 18.14).
 */
enum PledgedAssetKind: string
{
    case CompanyMortgage = 'company_mortgage';
    case PropertyMortgage = 'property_mortgage';
    case RetentionOfTitle = 'retention_of_title';
    case PledgedReceivables = 'pledged_receivables';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CompanyMortgage => __('Företagsinteckningar'),
            self::PropertyMortgage => __('Fastighetsinteckningar'),
            self::RetentionOfTitle => __('Tillgångar med äganderättsförbehåll'),
            self::PledgedReceivables => __('Belånade fordringar'),
            self::Other => __('Övriga ställda säkerheter'),
        };
    }

    /** What the amount is, per p. 18.14. */
    public function amountHint(): string
    {
        return $this->isMortgage() ? __('Inteckningarnas belopp') : __('Tillgångens redovisade värde');
    }

    public function isMortgage(): bool
    {
        return in_array($this, [self::CompanyMortgage, self::PropertyMortgage], true);
    }
}
