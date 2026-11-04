<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

/**
 * A source of the company register (ADR-047): SCB's bulk file, Bolagsverket's
 * bulk file or SCB's API. rows() streams one item per record of the source:
 * a RegistryRow for a legal person, null for a record that is skipped (a
 * natural person, a malformed line). position() is a safe point to resume
 * from once everything yielded so far is written.
 */
interface RegistrySource
{
    /** scb-bulk, bolagsverket-bulk or scb-api */
    public function key(): string;

    /** A version of the data (a file's ETag); an unchanged version is not read twice. */
    public function version(): ?string;

    /** Whether the source adds companies, or only completes those another source added. */
    public function createsRows(): bool;

    /** @return iterable<int, ?RegistryRow> */
    public function rows(?string $resumeFrom): iterable;

    public function position(): string;

    /**
     * The columns this source writes on an existing row (the rest are kept).
     *
     * @return list<string>
     */
    public function updateColumns(): array;
}
