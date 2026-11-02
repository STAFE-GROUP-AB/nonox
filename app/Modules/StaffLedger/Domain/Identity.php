<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Modules\Payroll\Domain\PersonalNumber;

/**
 * How a person in the staff ledger is identified: a Swedish personal or
 * coordination number (twelve digits, Luhn-checked when it is saved), or a
 * number from another country together with the issuing country (ISO 3166-1
 * alpha-2). 9 kap. 5 § skatteförfarandeförordningen (2011:1261) asks for
 * "personnummer, samordningsnummer eller motsvarande utländska nummer";
 * enligt AI-utredning 2026-11-02, juristgranskning återstår.
 *
 * A foreign number is never Luhn-checked: there is no common format. It is
 * stored as typed, trimmed and with runs of spaces collapsed, encrypted like
 * the personal number. The same helpers format an employer's identity:
 * a Swedish organisation number, or a foreign number with its country.
 */
final readonly class Identity
{
    public const int FOREIGN_MAX_LENGTH = 30;

    public function __construct(public string $number, public ?string $country = null) {}

    public function isForeign(): bool
    {
        return $this->country !== null;
    }

    /** The number in full: ÅÅÅÅMMDD-NNNN, or the foreign number with its country. */
    public function formatted(): string
    {
        if ($this->country !== null) {
            return "{$this->number} ({$this->country})";
        }

        return strlen($this->number) === 12 && ctype_digit($this->number) ? substr($this->number, 0, 8).'-'.substr($this->number, 8) : $this->number;
    }

    /** For lists: the date of birth of a Swedish number, nothing of a foreign one. */
    public function masked(): string
    {
        if ($this->country !== null) {
            return __('Utländskt nummer (:country), dolt', ['country' => $this->country]);
        }

        return PersonalNumber::masked($this->number);
    }

    /** Trimmed, with runs of white space collapsed to one space. */
    public static function cleanForeign(string $number): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($number));
    }

    /**
     * What the blind index is computed from: the twelve digits of a Swedish
     * number (unchanged from before foreign numbers), or the country and the
     * letters and digits of a foreign one in upper case, so "ab 12-3" and
     * "AB123" from the same country are the same person.
     */
    public function indexKey(): string
    {
        if ($this->country === null) {
            return $this->number;
        }

        return 'foreign:'.$this->country.':'.mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $this->number));
    }

    /** An employer as shown in lists and exports: "Name (number)" or "Name (number, country)". */
    public static function employer(?string $name, ?string $number, ?string $country): string
    {
        if ($name === null || $name === '') {
            return '';
        }

        if ($number === null || $number === '') {
            return $name;
        }

        return $country === null ? "{$name} ({$number})" : "{$name} ({$number}, {$country})";
    }
}
