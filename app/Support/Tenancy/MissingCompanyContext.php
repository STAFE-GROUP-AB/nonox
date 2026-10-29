<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use LogicException;

/**
 * Thrown when company-owned data is written without knowing which company it
 * belongs to, or when a row would be moved to another company.
 */
final class MissingCompanyContext extends LogicException
{
    public static function whileCreating(string $model): self
    {
        return new self("Cannot create {$model} without a current company. Use CurrentCompany::set() or run().");
    }

    public static function whileMoving(string $model): self
    {
        return new self("A {$model} can never be moved to another company.");
    }

    public static function foreignCompany(string $model): self
    {
        return new self("Cannot create {$model} for a company other than the current one.");
    }
}
