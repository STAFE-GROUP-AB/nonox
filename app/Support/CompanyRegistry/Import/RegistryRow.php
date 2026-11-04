<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

/**
 * One legal person read from a source (ADR-047): its ten-digit
 * organisation number and the columns of company_registry this source
 * fills. A source only sets what it knows; the rest of the row is left as
 * it is.
 */
final readonly class RegistryRow
{
    /**
     * @param  array<string, mixed>  $attributes  company_registry columns
     */
    public function __construct(
        public string $orgNumber,
        public array $attributes,
    ) {}
}
