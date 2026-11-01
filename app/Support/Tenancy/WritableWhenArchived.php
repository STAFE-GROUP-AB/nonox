<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Marks a company-scoped model that may still be written while its company
 * is archived (ADR-024): rows that are not räkenskapsinformation and that
 * the read-only company itself needs, such as the archive packages built
 * from it. Everything else is refused by BelongsToCompany.
 */
interface WritableWhenArchived {}
