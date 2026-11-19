<?php

declare(strict_types=1);

namespace App\Domain\Signing\Retention;

/**
 * How long a finished signing envelope is kept (ADR-046 amendment, gap 11).
 * The class only moves up: UNSIGNED → OTHER → ACCOUNTING (PostgreSQL trigger).
 */
enum SigningRetentionClass: string
{
    /** Räkenskapsinformation: kept until 31 December seven years after its fiscal year (BFL 7 kap. 2 §), at least. */
    case Accounting = 'ACCOUNTING';

    /** A signed document that is not räkenskapsinformation: the company's setting, 7–25 years after completion. */
    case Other = 'OTHER';

    /** Declined, withdrawn or expired: twelve months after it closed. */
    case Unsigned = 'UNSIGNED';

    public function label(): string
    {
        return match ($this) {
            self::Accounting => __('Räkenskapsinformation'),
            self::Other => __('Annat signerat dokument'),
            self::Unsigned => __('Inte signerat'),
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Unsigned => 0,
            self::Other => 1,
            self::Accounting => 2,
        };
    }
}
