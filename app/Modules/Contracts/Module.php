<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleCheck;

/**
 * An optional feature area a company can turn on or off under Moduler
 * (PAYROLL_MODULE.md A.3). Turning a module off never deletes its data.
 */
interface Module
{
    public function key(): string;

    public function name(): string;

    public function description(): string;

    public function icon(): string;

    /** @return list<string> keys of modules that must be on first */
    public function dependencies(): array;

    /** @return list<string> permission strings this module adds */
    public function permissions(): array;

    /** @return array<string, string> extra roles: key => translated label */
    public function roles(): array;

    /**
     * The module's permissions each role gets, by role key; 'owner' is the
     * company owner, who otherwise has every permission.
     *
     * @return array<string, list<string>>
     */
    public function rolePermissions(): array;

    /** @return list<string> permissions that still work while the module is off (archive access) */
    public function archivePermissions(): array;

    /** @return list<array{label: string, route: string, permission: string, active: string}> */
    public function navigation(): array;

    /** @return list<array{label: string, route: string, permission: string, active: string}> navigation while the module is off, for its archive */
    public function archiveNavigation(Company $company): array;

    /** Livewire component name for the module's settings, shown when it is on. */
    public function settingsComponent(): ?string;

    public function canEnable(Company $company): ModuleCheck;

    public function canDisable(Company $company): ModuleCheck;

    /** Seeds defaults. Must be idempotent and never overwrite settings or data. */
    public function onEnable(Company $company, User $by): void;

    /** Stops schedules and access. Never deletes data. */
    public function onDisable(Company $company, User $by): void;
}
