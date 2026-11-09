<?php

declare(strict_types=1);

namespace Tests\Support\Modules;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;

/**
 * An empty module that proves the framework works without payroll
 * (PAYROLL_MODULE.md M-4). Its blockers and warnings are set by the tests.
 */
final class DummyModule implements Module
{
    /** @var list<string> */
    public static array $blockers = [];

    /** @var list<string> */
    public static array $warnings = [];

    public static int $enabledCount = 0;

    public function __construct(
        private readonly string $key = 'dummy',
        /** @var list<string> */
        private readonly array $dependencies = [],
        /** @var list<string>|null replaces the permissions below, for registry tests */
        private readonly ?array $permissions = null,
        /** @var array<string, string>|null */
        private readonly ?array $roles = null,
        /** @var array<string, list<string>>|null */
        private readonly ?array $rolePermissions = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return ucfirst($this->key);
    }

    public function description(): string
    {
        return 'En testmodul.';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function permissions(): array
    {
        return $this->permissions ?? ["{$this->key}:view", "{$this->key}:manage", "{$this->key}:archive-view"];
    }

    public function roles(): array
    {
        return $this->roles ?? ["{$this->key}_admin" => 'Testadministratör'];
    }

    public function rolePermissions(): array
    {
        return $this->rolePermissions ?? [
            'owner' => ["{$this->key}:view", "{$this->key}:archive-view"],
            'admin' => ["{$this->key}:view"],
            'auditor' => ["{$this->key}:archive-view"],
            "{$this->key}_admin" => ["{$this->key}:view", "{$this->key}:manage"],
        ];
    }

    public function archivePermissions(): array
    {
        return array_values(array_intersect(["{$this->key}:archive-view"], $this->permissions()));
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [['label' => "Modul {$this->key}", 'route' => 'dummy.index', 'permission' => "{$this->key}:view", 'active' => 'dummy.*']];
    }

    public function settingsComponent(): ?string
    {
        return null;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(self::$blockers, self::$warnings);
    }

    public function onEnable(Company $company, User $by): void
    {
        self::$enabledCount++;
    }

    public function onDisable(Company $company, User $by): void {}
}
