<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

use App\Domain\Company\OrganisationNumber;
use Carbon\CarbonImmutable;

/**
 * The twelve-digit identifier Skatteverket's APIs take in the path
 * (momsdeklaration-library 1.0.38, "redovisare"): "16" and the ten digits
 * for a legal person, the century and the ten digits for a personnummer or
 * samordningsnummer (a sole trader).
 */
final class Redovisare
{
    public static function of(string $organisationNumber, ?CarbonImmutable $today = null): ?string
    {
        $number = OrganisationNumber::tryFrom($organisationNumber);

        if ($number === null) {
            return null;
        }

        if (! $number->isPersonalIdentityNumber()) {
            return "16{$number->digits}";
        }

        $digits = preg_replace('/\D/', '', $organisationNumber) ?? '';

        if (strlen($digits) === 12) {
            return $digits;
        }

        // A samordningsnummer adds 60 to the day.
        $day = (int) substr($number->digits, 4, 2);
        $born = CarbonImmutable::createFromFormat('!Ymd', '20'.substr($number->digits, 0, 4).sprintf('%02d', $day > 60 ? $day - 60 : $day));

        // Folkbokföringslagen 18 §: the ten-digit form has "+" instead of "-" from the year the person turns 100.
        $century = $born !== null && $born->lessThanOrEqualTo($today ?? CarbonImmutable::today()) ? 20 : 19;

        if (str_contains($organisationNumber, '+')) {
            $century--;
        }

        // TODO(legal-verify): companies store the ten-digit form with "-", so a sole trader aged 100 or more is read a century too late until the number is stored with "+" or all twelve digits.
        return $century.$number->digits;
    }
}
