<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The organisation number that names the other group company in a marking
 * or a tag: a valid number of a legal person (a group company is never a
 * natural person, so a personnummer is refused and none is ever stored) and
 * not the company's own.
 */
final class IntercompanyOrgNumber
{
    /** @throws ValidationException */
    public static function validated(Company $company, mixed $value, string $field): string
    {
        $number = OrganisationNumber::tryFrom(trim((string) $value));

        if ($number === null) {
            throw ValidationException::withMessages([$field => __('Organisationsnumret stämmer inte. Kontrollera siffrorna.')]);
        }

        if ($number->isPersonalIdentityNumber()) {
            throw ValidationException::withMessages([$field => __('Ett koncernföretag är en juridisk person. Ange dess organisationsnummer, inte ett personnummer.')]);
        }

        if ($number->formatted() === OrganisationNumber::tryFrom((string) $company->org_number)?->formatted()) {
            throw ValidationException::withMessages([$field => __('Det är företagets eget organisationsnummer. Ange det andra koncernföretagets.')]);
        }

        return $number->formatted();
    }
}
