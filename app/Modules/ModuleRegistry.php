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
 * The lists a module had when it was checked (permissions, role keys, role
 * permissions, archive permissions) are kept and served from here, so a
 * module cannot hand out something else later. Role labels stay live: they
 * are translated per request.
 *
 * Modules register while the providers register, before translations exist,
 * and roles() returns translated labels. So the key is checked at once and
 * the rest when the application starts to boot, before any provider boots
 * (ModuleServiceProvider); a module registered after that is checked at once
 * in full.
 */
final class ModuleRegistry
{
    /** Keys of the modules that ship with NONOX. */
    public const array FIRST_PARTY = [
        'agreements', 'ai', 'banking', 'connect', 'crm', 'inbox',
        'inventory', 'payroll', 'peppol', 'planning', 'pos', 'staff_ledger',
    ];

    /** The namespace a first-party module's class lives in. */
    public const string FIRST_PARTY_NAMESPACE = 'App\\Modules\\';

    /** `vendor.name` in lowercase; at most 50 characters (company_modules.module_key). `D`: no trailing newline. */
    private const string THIRD_PARTY_KEY = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/D';

    /** @var array<string, Module> */
    private array $modules = [];

    /** @var array<string, array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}> the checked lists per key */
    private array $checked = [];

    /**
     * @param  list<string>  $firstParty  keys treated as first-party
     * @param  list<string>  $firstPartyNamespaces  where a first-party module's class must live; tests add their own
     */
    public function __construct(
        private readonly array $firstParty = self::FIRST_PARTY,
        private readonly array $firstPartyNamespaces = [self::FIRST_PARTY_NAMESPACE],
    ) {}

    /**
     * @throws InvalidModule
     */
    public function register(Module $module): void
    {
        $key = $module->key();

        if (isset($this->modules[$key])) {
            throw InvalidModule::because($key, 'is already registered.');
        }

        if ($this->isThirdParty($module) && (strlen($key) > 50 || preg_match(self::THIRD_PARTY_KEY, $key) !== 1)) {
            throw InvalidModule::because($key, 'is not first-party, so its key must have the form vendor.name.');
        }

        if (! app()->isBooted()) {
            $this->modules[$key] = $module;

            return;
        }

        $this->checked[$key] = $this->check($module, $this->lists());
        $this->modules[$key] = $module;
    }

    /**
     * Checks the modules not yet checked, each against those registered
     * before it. A refused module is removed before the error; the modules
     * after it stay unchecked until the next call.
     *
     * @throws InvalidModule
     */
    public function verify(): void
    {
        $before = [];

        foreach ($this->modules as $key => $module) {
            if (! isset($this->checked[$key])) {
                try {
                    $this->checked[$key] = $this->check($module, $before);
                } catch (InvalidModule $exception) {
                    unset($this->modules[$key]);

                    throw $exception;
                }
            }

            $before[$key] = $this->checked[$key];
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
        foreach ($this->lists() as $key => $lists) {
            if (in_array($permission, $lists['permissions'], true)) {
                return $this->modules[$key];
            }
        }

        return null;
    }

    /** The module that adds the role, or null for a core role. */
    public function moduleForRole(string $role): ?Module
    {
        foreach ($this->lists() as $key => $lists) {
            if (in_array($role, $lists['roles'], true)) {
                return $this->modules[$key];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function permissionsOf(Module $module): array
    {
        return $this->listsOf($module)['permissions'];
    }

    /** @return list<string> */
    public function roleKeysOf(Module $module): array
    {
        return $this->listsOf($module)['roles'];
    }

    /** @return array<string, list<string>> */
    public function rolePermissionsOf(Module $module): array
    {
        return $this->listsOf($module)['rolePermissions'];
    }

    /** @return list<string> */
    public function archivePermissionsOf(Module $module): array
    {
        return $this->listsOf($module)['archivePermissions'];
    }

    /** @return list<string> the company owner and the member roles of AGENTS.md §4 */
    public static function coreRoles(): array
    {
        return ['owner', ...array_map(fn (CompanyRole $role): string => $role->value, CompanyRole::cases())];
    }

    /**
     * @return array<string, array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}>
     */
    private function lists(): array
    {
        if (count($this->checked) !== count($this->modules)) {
            $this->verify();
        }

        return $this->checked;
    }

    /**
     * @return array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}
     */
    private function listsOf(Module $module): array
    {
        return $this->lists()[$module->key()] ?? throw new InvalidArgumentException("Module {$module->key()} is not installed.");
    }

    /**
     * Checks the module and returns the lists it was checked with.
     *
     * @param  array<string, array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}>  $others  the modules registered before it
     * @return array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}
     */
    private function check(Module $module, array $others): array
    {
        // PHP arrays are values: these are copies, whatever the module returns later.
        $lists = [
            'permissions' => $module->permissions(),
            'roles' => array_map('strval', array_keys($module->roles())),
            'rolePermissions' => $module->rolePermissions(),
            'archivePermissions' => $module->archivePermissions(),
        ];

        $key = $module->key();
        $thirdParty = $this->isThirdParty($module);

        $this->checkPermissions($key, $lists, $others, $thirdParty);
        $this->checkRoles($key, $lists, $others, $thirdParty);
        $this->checkGrants($key, $lists, $thirdParty);

        return $lists;
    }

    /**
     * @param  array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}  $lists
     * @param  array<string, array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}>  $others
     */
    private function checkPermissions(string $key, array $lists, array $others, bool $thirdParty): void
    {
        foreach ($lists['permissions'] as $permission) {
            if (in_array($permission, CompanyRole::PERMISSIONS, true)) {
                throw InvalidModule::because($key, "claims the core permission {$permission}.");
            }

            foreach ($others as $other => $theirs) {
                if (in_array($permission, $theirs['permissions'], true)) {
                    throw InvalidModule::because($key, "claims {$permission}, which belongs to module {$other}.");
                }
            }

            if ($thirdParty && ! self::isOwn($key, $permission)) {
                throw InvalidModule::because($key, "adds the permission {$permission}, which does not start with {$key}:.");
            }
        }
    }

    /**
     * @param  array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}  $lists
     * @param  array<string, array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}>  $others
     */
    private function checkRoles(string $key, array $lists, array $others, bool $thirdParty): void
    {
        foreach ($lists['roles'] as $role) {
            if (in_array($role, self::coreRoles(), true)) {
                throw InvalidModule::because($key, "adds the role {$role}, which is a core role.");
            }

            foreach ($others as $other => $theirs) {
                if (in_array($role, $theirs['roles'], true)) {
                    throw InvalidModule::because($key, "adds the role {$role}, which belongs to module {$other}.");
                }
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
     *
     * @param  array{permissions: list<string>, roles: list<string>, rolePermissions: array<string, list<string>>, archivePermissions: list<string>}  $lists
     */
    private function checkGrants(string $key, array $lists, bool $thirdParty): void
    {
        foreach ($lists['rolePermissions'] as $role => $permissions) {
            if ($thirdParty && ! in_array($role, self::coreRoles(), true) && ! in_array($role, $lists['roles'], true)) {
                throw InvalidModule::because($key, "gives permissions to role {$role}, which is neither a core role nor its own.");
            }

            foreach ($permissions as $permission) {
                if (! in_array($permission, $lists['permissions'], true)) {
                    throw InvalidModule::because($key, "gives {$permission} to role {$role}, but does not add that permission.");
                }
            }
        }

        foreach ($lists['archivePermissions'] as $permission) {
            if (! in_array($permission, $lists['permissions'], true)) {
                throw InvalidModule::because($key, "lists {$permission} as an archive permission, but does not add that permission.");
            }
        }
    }

    /** First-party means a first-party key and a class in a first-party namespace. */
    private function isThirdParty(Module $module): bool
    {
        if (! in_array($module->key(), $this->firstParty, true)) {
            return true;
        }

        foreach ($this->firstPartyNamespaces as $namespace) {
            if (str_starts_with($module::class, $namespace)) {
                return false;
            }
        }

        return true;
    }

    private static function isOwn(string $key, string $name): bool
    {
        return str_starts_with($name, "{$key}:") && strlen($name) > strlen($key) + 1;
    }
}
