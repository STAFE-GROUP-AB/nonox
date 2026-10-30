<?php

declare(strict_types=1);

namespace App\Modules;

use App\Modules\Contracts\Module;
use InvalidArgumentException;

/**
 * Every installed module, registered by its service provider. Installed is
 * not the same as on: that is per company (ModuleManager).
 */
final class ModuleRegistry
{
    /** @var array<string, Module> */
    private array $modules = [];

    public function register(Module $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    public function get(string $key): Module
    {
        return $this->modules[$key] ?? throw new InvalidArgumentException("Module {$key} is not installed.");
    }

    /** @return array<string, Module> */
    public function all(): array
    {
        return $this->modules;
    }

    /** The module that adds the permission, or null for a core permission. */
    public function moduleForPermission(string $permission): ?Module
    {
        foreach ($this->modules as $module) {
            if (in_array($permission, $module->permissions(), true)) {
                return $module;
            }
        }

        return null;
    }

    /** The module that adds the role, or null for a core role. */
    public function moduleForRole(string $role): ?Module
    {
        foreach ($this->modules as $module) {
            if (array_key_exists($role, $module->roles())) {
                return $module;
            }
        }

        return null;
    }
}
