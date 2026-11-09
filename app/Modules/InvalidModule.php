<?php

declare(strict_types=1);

namespace App\Modules;

use InvalidArgumentException;

/**
 * A module the registry refuses to register (ADR-079 §2.2). A wrong key is
 * refused at register(); the rest is checked when the application starts to
 * boot, before any service provider boots, and before the core roles are
 * built (JetstreamServiceProvider). So a refused module stops the boot before
 * its routes, listeners or role grants exist. Messages are for developers.
 */
final class InvalidModule extends InvalidArgumentException
{
    public static function because(string $key, string $reason): self
    {
        return new self("Module {$key} {$reason}");
    }
}
