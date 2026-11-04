<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Support\Luhn;

/**
 * A Swedish organisation number (or personal number for sole traders),
 * stored in the canonical ten-digit form "NNNNNN-NNNN".
 */
final readonly class OrganisationNumber
{
    private function __construct(public string $digits) {}

    public static function tryFrom(string $input): ?self
    {
        $digits = preg_replace('/[\s+-]/', '', $input) ?? '';

        if (strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (! preg_match('/^\d{10}$/', $digits)) {
            return null;
        }

        if (! Luhn::isValid($digits)) {
            return null;
        }

        return new self($digits);
    }

    /**
     * Whether the number is a personal identity number (a sole trader's)
     * rather than a legal person's organisation number: the third digit of
     * an organisation number is always at least 2 (Skatteverket SKV 709,
     * version 8), and of a personnummer, the month, 0 or 1.
     */
    public function isPersonalIdentityNumber(): bool
    {
        return (int) $this->digits[2] < 2;
    }

    public function formatted(): string
    {
        return substr($this->digits, 0, 6).'-'.substr($this->digits, 6);
    }

    /**
     * The Swedish VAT registration number: "SE", the ten digits and "01".
     */
    public function vatNumber(): string
    {
        return "SE{$this->digits}01";
    }
}
