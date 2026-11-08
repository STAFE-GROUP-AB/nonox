<?php

declare(strict_types=1);

namespace App\Modules;

use InvalidArgumentException;

/**
 * A module the registry refuses to register (ADR-079 §2.2). Thrown while the
 * application boots, so a mistake in a module is found at once and never
 * changes another module or the core roles. Messages are for developers.
 */
final class InvalidModule extends InvalidArgumentException
{
    public static function because(string $key, string $reason): self
    {
        return new self("Module {$key} {$reason}");
    }
}
