<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * Tells an organisation number from a personal number (ADR-043): the third
 * digit of an organisation number is 2 or more, of a personal number 0 or
 * 1, the month (as LookUpCompany::identity reads it). The CRM never keeps a
 * personal number in plain text: a sole trader's number stays in the
 * customer register, where it can be protected.
 */
final class IdentityNumber
{
    public static function isPersonal(?string $number): bool
    {
        $digits = (string) preg_replace('/\D/', '', (string) $number);

        if (strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) === 10 && (int) $digits[2] < 2;
    }

    /** The number as the CRM may keep it: null for a personal number. */
    public static function forCrm(?string $number): ?string
    {
        return $number === null || self::isPersonal($number) ? null : $number;
    }
}
