<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * What a task or a to-do on Mina företag is about. The system's own to-dos
 * (CompanyOverview) are mapped to the same types so the firm can filter
 * both with one list.
 */
enum TaskType: string
{
    case Vat = 'VAT';
    case Agi = 'AGI';
    case YearEnd = 'YEAR_END';
    case AnnualReport = 'ANNUAL_REPORT';
    case Invoices = 'INVOICES';
    case Approval = 'APPROVAL';
    case Bank = 'BANK';
    case Inbox = 'INBOX';
    case Payroll = 'PAYROLL';
    case Documents = 'DOCUMENTS';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Vat => __('Moms'),
            self::Agi => __('AGI'),
            self::YearEnd => __('Bokslut'),
            self::AnnualReport => __('Årsredovisning'),
            self::Invoices => __('Kundfakturor'),
            self::Approval => __('Attest och betalning'),
            self::Bank => __('Bankavstämning'),
            self::Inbox => __('Inkorg'),
            self::Payroll => __('Lön'),
            self::Documents => __('Underlag'),
            self::Other => __('Övrigt'),
        };
    }

    /** The Swedish word used in URLs and filters. */
    public function slug(): string
    {
        return match ($this) {
            self::Vat => 'moms',
            self::Agi => 'agi',
            self::YearEnd => 'bokslut',
            self::AnnualReport => 'arsredovisning',
            self::Invoices => 'kundfakturor',
            self::Approval => 'attest',
            self::Bank => 'bank',
            self::Inbox => 'inkorg',
            self::Payroll => 'lon',
            self::Documents => 'underlag',
            self::Other => 'ovrigt',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }
}
