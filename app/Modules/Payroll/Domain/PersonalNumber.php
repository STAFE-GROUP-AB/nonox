<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Support\Luhn;
use Carbon\CarbonImmutable;

/**
 * A Swedish personal or coordination number with all twelve digits
 * (ÅÅÅÅMMDD-NNNN), checked with the Luhn digit over the last ten. A
 * coordination number has 60 added to the day. Twelve digits are required so
 * the century is never guessed.
 */
final readonly class PersonalNumber
{
    private function __construct(public string $digits, public CarbonImmutable $birthDate, public bool $coordination) {}

    public static function tryFrom(string $input): ?self
    {
        $digits = (string) preg_replace('/\D/', '', $input);

        if (strlen($digits) !== 12 || ! Luhn::isValid(substr($digits, 2))) {
            return null;
        }

        $day = (int) substr($digits, 6, 2);
        $coordination = $day > 60;
        $birthDay = $coordination ? $day - 60 : $day;
        $year = (int) substr($digits, 0, 4);
        $month = (int) substr($digits, 4, 2);

        if (! checkdate($month, $birthDay, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $birthDay);

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return new self($digits, $date, $coordination);
    }

    /** ÅÅÅÅMMDD-NNNN */
    public function formatted(): string
    {
        return substr($this->digits, 0, 8).'-'.substr($this->digits, 8);
    }

    /** ÅÅÅÅMMDD-XXXX, for lists. */
    public static function masked(string $number): string
    {
        return substr((string) preg_replace('/\D/', '', $number), 0, 8).'-XXXX';
    }
}
