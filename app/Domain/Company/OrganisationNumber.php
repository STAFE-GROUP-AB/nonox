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
