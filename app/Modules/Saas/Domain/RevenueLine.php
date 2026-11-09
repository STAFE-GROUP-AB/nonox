<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

use InvalidArgumentException;

/**
 * One recurring priced item of a contract (SA-R2): an avtalsfakturering row,
 * a CRM agreement's value, or later a Stripe subscription item. The amount is
 * per billing period, in minor units of its currency, excluding VAT and after
 * recurring discounts (SA-R3, SA-R5).
 */
final readonly class RevenueLine
{
    public const array UNITS = ['month', 'year', 'week', 'day'];

    public function __construct(
        public string $key,
        /** Only for display in tests and imports; never stored (ADR-080 addendum, review 7). */
        public string $description,
        public string $currency,
        public int $amountMinor,
        public string $intervalUnit = 'month',
        public int $intervalCount = 1,
        public bool $usage = false,
        /** A time-limited discount ends on this date (first day at full price). */
        public ?string $discountUntil = null,
        public ?int $undiscountedMinor = null,
        /** A free trial lasts until this date (first paid day). */
        public ?string $trialUntil = null,
    ) {
        if (! in_array($intervalUnit, self::UNITS, true) || $intervalCount < 1) {
            throw new InvalidArgumentException("Unknown interval {$intervalCount} {$intervalUnit}.");
        }
    }

    /**
     * The line's monthly amount at an instant (a date, Y-m-d), in minor units
     * of its currency, half up (SA-R4). A usage line has none (SA-R8); a line in
     * a free trial is 0 (SA-R6); a time-limited discount applies only before it
     * ends (SA-R5).
     */
    public function monthlyMinorAt(string $instant): int
    {
        if ($this->usage) {
            return 0;
        }

        if ($this->trialUntil !== null && $instant < $this->trialUntil) {
            return 0;
        }

        $amount = $this->discountUntil !== null && $this->undiscountedMinor !== null && $instant >= $this->discountUntil
            ? $this->undiscountedMinor
            : $this->amountMinor;

        return match ($this->intervalUnit) {
            'month' => Arithmetic::divideHalfUp($amount, $this->intervalCount),
            'year' => Arithmetic::divideHalfUp($amount, 12 * $this->intervalCount),
            'week' => Arithmetic::divideHalfUp($amount * 52, 12 * $this->intervalCount),
            'day' => Arithmetic::divideHalfUp($amount * 365, 12 * $this->intervalCount),
            default => throw new InvalidArgumentException("Unknown interval {$this->intervalUnit}."),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            // No free text in the ledger (GDPR, review 7): the description is read from the source when shown.
            'currency' => $this->currency,
            'amount_minor' => $this->amountMinor,
            'interval_unit' => $this->intervalUnit,
            'interval_count' => $this->intervalCount,
            'usage' => $this->usage,
            'discount_until' => $this->discountUntil,
            'undiscounted_minor' => $this->undiscountedMinor,
            'trial_until' => $this->trialUntil,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            (string) ($data['description'] ?? ''),
            (string) ($data['currency'] ?? 'SEK'),
            (int) $data['amount_minor'],
            (string) ($data['interval_unit'] ?? 'month'),
            (int) ($data['interval_count'] ?? 1),
            (bool) ($data['usage'] ?? false),
            isset($data['discount_until']) ? (string) $data['discount_until'] : null,
            isset($data['undiscounted_minor']) ? (int) $data['undiscounted_minor'] : null,
            isset($data['trial_until']) ? (string) $data['trial_until'] : null,
        );
    }
}
