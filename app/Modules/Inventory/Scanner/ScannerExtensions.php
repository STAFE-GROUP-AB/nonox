<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Scanner;

use App\Models\Company;
use App\Models\User;

/**
 * The scanner extensions other modules register (ADR-073 §6). Lager knows
 * nothing about them beyond this interface.
 */
final class ScannerExtensions
{
    /** @var array<string, ScannerExtension> */
    private array $extensions = [];

    public function register(ScannerExtension $extension): void
    {
        $this->extensions[$extension->key()] = $extension;
    }

    /** @return array<string, ScannerExtension> */
    public function all(): array
    {
        return $this->extensions;
    }

    public function forKind(string $kind): ?ScannerExtension
    {
        foreach ($this->extensions as $extension) {
            if (in_array($kind, $extension->kinds(), true)) {
                return $extension;
            }
        }

        return null;
    }

    /** @return array<string, ScannerExtension> the extensions this user may use in the company */
    public function allowed(User $user, Company $company): array
    {
        return array_filter($this->extensions, fn (ScannerExtension $extension): bool => $extension->allows($user, $company));
    }
}
