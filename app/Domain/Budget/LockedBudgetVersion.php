<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use LogicException;

/**
 * Thrown when something tries to change a locked budget version or its lines.
 */
final class LockedBudgetVersion extends LogicException
{
    public static function make(): self
    {
        return new self('A locked budget version cannot be changed.');
    }
}
