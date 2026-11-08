<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Company\CompanyRole;
use App\Modules\Contracts\Module;
use InvalidArgumentException;

/**
 * Every installed module, registered by its service provider. Installed is
 * not the same as on: that is per company (ModuleManager).
 *
 * Registration is checked (ADR-079 §2.2), for our own modules too: a key is
 * registered once, a module never claims a core permission, a core role or
 * another module's permission or role, and it only hands out permissions it
 * adds itself. A module that is not first-party has a `vendor.name` key and
 * keeps every permission and role inside it (`acme.tidrapport:view`). These
 * checks catch mistakes early; they do not contain malicious code, which runs
 * in the same process (ADR-079, threat model).
 *
 * Modules register while the providers register, before translations exist,
 * and roles() returns translated labels. So the key is checked at once and
 * the rest when the application has booted (ModuleServiceProvider); a module
 * registered after boot is checked at once in full.
 */
final class ModuleRegistry
{
    /** Keys of the modules that ship with NONOX. */
    public const array FIRST_PARTY = [
        'agreements', 'ai', 'banking', 'connect', 'crm', 'inbox',
        'inventory', 'payroll', 'peppol', 'planning', 'pos', 'staff_ledger',
    ];

    /** `vendor.name` in lowercase; at most 50 characters (company_modules.module_key). */
    private const string THIRD_PARTY_KEY = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/';

    /** @var array<string, Module> */
    private array $modules = [];

    /** @var array<string, true> modules registered before boot, not yet checked beyond their key */
    private array $unchecked = [];

    /**
     * @param  list<string>  $firstParty  keys treated as first-party; tests add their own
     */
    public function __construct(private readonly array $firstParty = self::FIRST_PARTY) {}

    /**
     * @throws InvalidModule
     */
    public function register(Module $module): void
    {
        $key = $module->key();

        if (isset($this->modules[$key])) {
            throw InvalidModule::because($key, 'is already registered.');
        }

        if ($this->isThirdParty($key) && (strlen($key) > 50 || preg_match(self::THIRD_PARTY_KEY, $key) !== 1)) {
            throw InvalidModule::because($key, 'is not first-party, so its key must have the form vendor.name.');
        }

        if (! app()->isBooted()) {
            $this->modules[$key] = $module;
            $this->unchecked[$key] = true;

            return;
        }

        $this->check($module, $this->modules);
        $this->modules[$key] = $module;
    }

    /**
     * Checks the modules registered before boot, each against those
     * registered before it. A refused module is removed before the error.
     *
     * @throws InvalidModule
     */
    public function verify(): void
    {
        $before = [];

        foreach ($this->modules as $key => $module) {
            if (isset($this->unchecked[$key])) {
                try {
                    $this->check($module, $before);
                } catch (InvalidModule $exception) {
                    unset($this->modules[$key], $this->unchecked[$key]);

                    throw $exception;
                }

                unset($this->unchecked[$key]);
            }

            $before[$key] = $module;
        }
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

    /** @return list<string> the company owner and the member roles of AGENTS.md §4 */
    public static function coreRoles(): array
    {
        return ['owner', ...array_map(fn (CompanyRole $role): string => $role->value, CompanyRole::cases())];
    }

    /**
     * @param  array<string, Module>  $others  the modules registered before it
     */
    private function check(Module $module, array $others): void
    {
        $thirdParty = $this->isThirdParty($module->key());

        $this->checkPermissions($module, $others, $thirdParty);
        $this->checkRoles($module, $others, $thirdParty);
        $this->checkGrants($module, $thirdParty);
    }

    /**
     * @param  array<string, Module>  $others
     */
    private function checkPermissions(Module $module, array $others, bool $thirdParty): void
    {
        $key = $module->key();

        foreach ($module->permissions() as $permission) {
            if (in_array($permission, CompanyRole::PERMISSIONS, true)) {
                throw InvalidModule::because($key, "claims the core permission {$permission}.");
            }

            $owner = self::find($others, fn (Module $other): bool => in_array($permission, $other->permissions(), true));

            if ($owner !== null) {
                throw InvalidModule::because($key, "claims {$permission}, which belongs to module {$owner->key()}.");
            }

            if ($thirdParty && ! self::isOwn($key, $permission)) {
                throw InvalidModule::because($key, "adds the permission {$permission}, which does not start with {$key}:.");
            }
        }
    }

    /**
     * @param  array<string, Module>  $others
     */
    private function checkRoles(Module $module, array $others, bool $thirdParty): void
    {
        $key = $module->key();

        foreach (array_keys($module->roles()) as $role) {
            if (in_array($role, self::coreRoles(), true)) {
                throw InvalidModule::because($key, "adds the role {$role}, which is a core role.");
            }

            $owner = self::find($others, fn (Module $other): bool => array_key_exists($role, $other->roles()));

            if ($owner !== null) {
                throw InvalidModule::because($key, "adds the role {$role}, which belongs to module {$owner->key()}.");
            }

            if ($thirdParty && ! self::isOwn($key, $role)) {
                throw InvalidModule::because($key, "adds the role {$role}, which does not start with {$key}:.");
            }
        }
    }

    /**
     * A module hands out only its own permissions. Our own modules may give
     * them to another module's role (NONOX Avtal to the payroll
     * administrator); a third-party module only to core roles and its own.
     */
    private function checkGrants(Module $module, bool $thirdParty): void
    {
        $key = $module->key();
        $own = $module->permissions();

        foreach ($module->rolePermissions() as $role => $permissions) {
            if ($thirdParty && ! in_array($role, self::coreRoles(), true) && ! array_key_exists($role, $module->roles())) {
                throw InvalidModule::because($key, "gives permissions to role {$role}, which is neither a core role nor its own.");
            }

            foreach ($permissions as $permission) {
                if (! in_array($permission, $own, true)) {
                    throw InvalidModule::because($key, "gives {$permission} to role {$role}, but does not add that permission.");
                }
            }
        }

        foreach ($module->archivePermissions() as $permission) {
            if (! in_array($permission, $own, true)) {
                throw InvalidModule::because($key, "lists {$permission} as an archive permission, but does not add that permission.");
            }
        }
    }

    private function isThirdParty(string $key): bool
    {
        return ! in_array($key, $this->firstParty, true);
    }

    /**
     * @param  array<string, Module>  $modules
     * @param  callable(Module): bool  $matches
     */
    private static function find(array $modules, callable $matches): ?Module
    {
        foreach ($modules as $module) {
            if ($matches($module)) {
                return $module;
            }
        }

        return null;
    }

    private static function isOwn(string $key, string $name): bool
    {
        return str_starts_with($name, "{$key}:") && strlen($name) > strlen($key) + 1;
    }
}
