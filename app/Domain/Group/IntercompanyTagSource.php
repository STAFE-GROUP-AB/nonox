<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * How an intercompany row tag came about (ADR-060, addendum 2026-11-07, 2.3).
 * Only COUNTERPARTY tags are kept up to date by "Uppdatera
 * koncernmarkeringar"; the other two were set by a person and are never
 * changed or removed by a rule.
 */
enum IntercompanyTagSource: string
{
    /** From a marked customer or supplier, on a voucher made from its invoice or payment. */
    case Counterparty = 'COUNTERPARTY';

    /** Tagged by a person in bulk, with the kind of the account's mark in the per-user overview. */
    case AccountMark = 'ACCOUNT_MARK';

    /** Tagged by a person, with a kind they chose. */
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Counterparty => __('Från kund eller leverantör'),
            self::AccountMark => __('Från kontots markering'),
            self::Manual => __('Manuellt'),
        };
    }
}
