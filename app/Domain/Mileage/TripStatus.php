<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

/**
 * Where a trip is on its way to a payment or a voucher. PAID and BOOKED are
 * final: the trip is then underlag to a verifikation and a database trigger
 * refuses every change (ADR-072).
 */
enum TripStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Paid = 'PAID';
    case Booked = 'BOOKED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Inte inskickad'),
            self::Submitted => __('Väntar på svar'),
            self::Approved => __('Godkänd, betalas med lönen'),
            self::Rejected => __('Inte godkänd'),
            self::Paid => __('Utbetald'),
            self::Booked => __('Bokförd'),
        };
    }

    /** Whether the person who logged the trip may still change or remove it. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Booked], true);
    }
}
