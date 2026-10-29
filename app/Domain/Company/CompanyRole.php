<?php

declare(strict_types=1);

namespace App\Domain\Company;

/**
 * Member roles from AGENTS.md §4. The owner is not a member role: Jetstream
 * gives the company owner (`teams.user_id`) every permission. See ADR-003.
 */
enum CompanyRole: string
{
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Bookkeeper = 'bookkeeper';
    case Approver = 'approver';
    case Auditor = 'auditor';

    public const array PERMISSIONS = [
        'company:update', 'company:archive', 'members:manage',
        'accounts:manage', 'fiscal-years:manage', 'periods:lock',
        'vouchers:create', 'vouchers:post', 'vouchers:correct', 'vouchers:view',
        'invoices:create', 'invoices:send', 'invoices:credit', 'invoices:view',
        'supplier-invoices:create', 'supplier-invoices:approve', 'payments:create', 'payments:approve',
        'vat:prepare', 'vat:submit',
        'reports:view', 'export:sie', 'export:archive', 'import:sie', 'import:fortnox',
        'api-tokens:manage', 'settings:manage', 'audit-log:view',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administratör'),
            self::Accountant => __('Redovisningskonsult'),
            self::Bookkeeper => __('Bokförare'),
            self::Approver => __('Attestant'),
            self::Auditor => __('Revisor'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => __('Kan göra allt utom att arkivera företaget.'),
            self::Accountant => __('Sköter hela bokföringen, bokslut, moms, rapporter och SIE.'),
            self::Bookkeeper => __('Bokför verifikationer och hanterar kund- och leverantörsfakturor. Kan inte låsa perioder eller göra bokslut.'),
            self::Approver => __('Attesterar leverantörsfakturor och betalningar.'),
            self::Auditor => __('Kan läsa och exportera allt, men inte bokföra något.'),
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => array_values(array_diff(self::PERMISSIONS, ['company:archive'])),
            self::Accountant => [
                'accounts:manage', 'fiscal-years:manage', 'periods:lock',
                'vouchers:create', 'vouchers:post', 'vouchers:correct', 'vouchers:view',
                'invoices:create', 'invoices:send', 'invoices:credit', 'invoices:view',
                'supplier-invoices:create', 'payments:create',
                'vat:prepare', 'vat:submit',
                'reports:view', 'export:sie', 'export:archive', 'import:sie', 'import:fortnox',
                'audit-log:view',
            ],
            self::Bookkeeper => [
                'vouchers:create', 'vouchers:post', 'vouchers:correct', 'vouchers:view',
                'invoices:create', 'invoices:send', 'invoices:credit', 'invoices:view',
                'supplier-invoices:create', 'payments:create',
                'reports:view',
            ],
            self::Approver => [
                'supplier-invoices:approve', 'payments:approve',
                'invoices:view', 'vouchers:view',
            ],
            self::Auditor => [
                'vouchers:view', 'invoices:view', 'reports:view',
                'export:sie', 'export:archive', 'audit-log:view',
            ],
        };
    }
}
