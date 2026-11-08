<?php

declare(strict_types=1);

namespace Tests\Support\Modules;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;

/**
 * The third-party twin of DummyModule (ADR-079 §2.2): a `vendor.name` key,
 * and permissions and roles that all start with that key. The registry tests
 * replace parts of it to try what a third-party module must not do.
 */
final class ThirdPartyDummyModule implements Module
{
    public function __construct(
        private readonly string $key = 'acme.dummy',
        /** @var list<string> */
        private readonly array $dependencies = [],
        /** @var list<string>|null */
        private readonly ?array $permissions = null,
        /** @var array<string, string>|null */
        private readonly ?array $roles = null,
        /** @var array<string, list<string>>|null */
        private readonly ?array $rolePermissions = null,
        /** @var list<string>|null */
        private readonly ?array $archivePermissions = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return 'Acme testtillägg';
    }

    public function description(): string
    {
        return 'En testmodul från en annan leverantör.';
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
        return $this->roles ?? ["{$this->key}:admin" => 'Acme-administratör'];
    }

    public function rolePermissions(): array
    {
        return $this->rolePermissions ?? [
            'owner' => ["{$this->key}:view", "{$this->key}:manage", "{$this->key}:archive-view"],
            'admin' => ["{$this->key}:view"],
            'auditor' => ["{$this->key}:archive-view"],
            "{$this->key}:admin" => ["{$this->key}:view", "{$this->key}:manage"],
        ];
    }

    public function archivePermissions(): array
    {
        return $this->archivePermissions ?? ["{$this->key}:archive-view"];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [];
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
        return ModuleCheck::allow();
    }

    public function onEnable(Company $company, User $by): void {}

    public function onDisable(Company $company, User $by): void {}
}
