<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Company\OrganisationNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SwedishOrganisationNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || OrganisationNumber::tryFrom($value) === null) {
            $fail(__('Organisationsnumret stämmer inte. Skriv tio siffror, till exempel 556123-4567.'));
        }
    }
}
