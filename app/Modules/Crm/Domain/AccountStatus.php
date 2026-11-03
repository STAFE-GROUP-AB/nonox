<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * Where an account stands (ADR-043): a prospect becomes an active customer
 * with its first invoice, an active customer becomes inactive after a
 * number of months without an invoice, and lost is set by hand.
 */
enum AccountStatus: string
{
    case Prospect = 'PROSPECT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Lost = 'LOST';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => __('Prospekt'),
            self::Active => __('Aktiv kund'),
            self::Inactive => __('Inaktiv'),
            self::Lost => __('Förlorad'),
        };
    }

    /** The tone of the status badge (x-ui.badge). */
    public function tone(): string
    {
        return match ($this) {
            self::Prospect => 'accent',
            self::Active => 'success',
            self::Inactive, self::Lost => 'neutral',
        };
    }

    /** Open accounts show their next step. */
    public function isOpen(): bool
    {
        return $this === self::Prospect || $this === self::Active;
    }
}
