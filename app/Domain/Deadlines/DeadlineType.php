<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

/** The kinds of dates on Mina datum (ADR-051, stage 2). */
enum DeadlineType: string
{
    case Vat = 'vat';
    case EuSalesList = 'eu_sales_list';
    case Agi = 'agi';
    case PreliminaryTax = 'preliminary_tax';
    case IncomeTaxReturn = 'income_tax_return';
    case AnnualMeeting = 'annual_meeting';
    case AnnualReport = 'annual_report';
    case Closing = 'closing';

    public function label(): string
    {
        return match ($this) {
            self::Vat => __('Moms'),
            self::EuSalesList => __('Periodisk sammanställning'),
            self::Agi => __('Arbetsgivardeklaration'),
            self::PreliminaryTax => __('Preliminärskatt'),
            self::IncomeTaxReturn => __('Inkomstdeklaration'),
            self::AnnualMeeting => __('Årsstämma'),
            self::AnnualReport => __('Årsredovisning'),
            self::Closing => __('Bokslut'),
        };
    }
}
