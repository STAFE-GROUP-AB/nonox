<?php

declare(strict_types=1);

namespace App\Modules;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when something of a module is used while the module is off for the
 * company. Over HTTP it answers 404, so the module's existence is not shown.
 */
final class ModuleDisabledException extends RuntimeException implements HttpExceptionInterface
{
    public static function for(string $key): self
    {
        return new self("Module {$key} is not enabled for this company.");
    }

    public function getStatusCode(): int
    {
        return 404;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
