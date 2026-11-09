<?php

declare(strict_types=1);

namespace App\Modules;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns modules on and off per company and answers whether one is on
 * (PAYROLL_MODULE.md A.4–A.6). The answer is cached per request and in the
 * cache store, and forgotten on every change. Every change is audited.
 */
final class ModuleManager
{
    /** @var array<string, array<int, list<string>>> enabled module keys per database connection and company id, this request */
    private array $enabled = [];

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * Whether the module is on for the company (the current one when none is
     * given). Queued jobs pass the company id from their payload and stop
     * quietly when this is false (PAYROLL_MODULE.md A.5).
     */
    public function isEnabled(string $key, Company|int|null $company = null): bool
    {
        $companyId = match (true) {
            $company instanceof Company => $company->id,
            is_int($company) => $company,
            default => CurrentCompany::id(),
        };

        if ($companyId === null || ! $this->registry->has($key)) {
            return false;
        }

        if (! in_array($key, $this->enabledKeys($companyId), true)) {
            return false;
        }

        // A demo company (ADR-077) reaches nothing outside NONOX: these modules are off while a visitor is in it.
        return ! DemoMode::moduleBlocked($key);
    }

    /**
     * @throws ModuleDisabledException
     */
    public function ensureEnabled(string $key, ?Company $company = null): void
    {
        if (! $this->isEnabled($key, $company)) {
            throw ModuleDisabledException::for($key);
        }
    }

    /**
     * Company ids that have the module on, for schedules that run across
     * companies.
     *
     * @return list<int>
     */
    public function companiesWith(string $key): array
    {
        // Schedules look across every company; each id is then run in its own company.
        return array_values(array_map('intval', CompanyScope::bypass(fn (): array => CompanyModule::query()
            ->where('module_key', $key)
            ->where('enabled', true)
            ->pluck('company_id')
            ->all())));
    }

    /**
     * Whether the module can be turned on now: its own check and that every
     * module it depends on is on.
     */
    public function enableCheck(string $key, Company $company): ModuleCheck
    {
        $module = $this->registry->get($key);
        $check = $module->canEnable($company);

        foreach ($module->dependencies() as $dependency) {
            if (! $this->isEnabled($dependency, $company)) {
                $check = $check->withBlocker(__('Modulen :module måste slås på först.', ['module' => $this->registry->get($dependency)->name()]));
            }
        }

        return $check;
    }

    /**
     * Whether the module can be turned off now: its own blockers and
     * warnings, and that no module that depends on it is on.
     */
    public function disableCheck(string $key, Company $company): ModuleCheck
    {
        $check = $this->registry->get($key)->canDisable($company);

        foreach ($this->registry->all() as $other) {
            if (in_array($key, $other->dependencies(), true) && $this->isEnabled($other->key(), $company)) {
                $check = $check->withBlocker(__('Modulen :module använder den här modulen. Stäng av den först.', ['module' => $other->name()]));
            }
        }

        return $check;
    }

    public function enable(string $key, Company $company, User $by): void
    {
        $this->authorize($by, $company);
        $module = $this->registry->get($key);
        $check = $this->enableCheck($key, $company);

        if (! $check->allowed()) {
            throw ValidationException::withMessages(['module' => $check->blockers]);
        }

        CurrentCompany::run($company, function () use ($module, $company, $by): void {
            DB::transaction(function () use ($module, $company, $by): void {
                $row = CompanyModule::query()->firstOrNew(['module_key' => $module->key()]);
                $before = (bool) $row->enabled;

                $row->forceFill(['enabled' => true, 'enabled_at' => now(), 'enabled_by' => $by->id])->save();
                $this->forget($company);
                $module->onEnable($company, $by);

                Audit::record('module.enabled', $row, ['module' => $module->key(), 'before' => $before, 'after' => true], $company, $by);
            });
        });

        $this->forget($company);
    }

    /**
     * @param  bool  $confirmed  the user has confirmed the warnings
     */
    public function disable(string $key, Company $company, User $by, bool $confirmed = false): void
    {
        $this->authorize($by, $company);
        $module = $this->registry->get($key);
        $check = $this->disableCheck($key, $company);

        if (! $check->allowed()) {
            throw ValidationException::withMessages(['module' => $check->blockers]);
        }

        if ($check->warnings !== [] && ! $confirmed) {
            throw ValidationException::withMessages(['module' => [__('Bekräfta att du har läst varningarna.')]]);
        }

        CurrentCompany::run($company, function () use ($module, $company, $by): void {
            DB::transaction(function () use ($module, $company, $by): void {
                $row = CompanyModule::query()->firstOrNew(['module_key' => $module->key()]);
                $before = (bool) $row->enabled;

                $module->onDisable($company, $by);
                $row->forceFill(['enabled' => false, 'disabled_at' => now(), 'disabled_by' => $by->id])->save();

                Audit::record('module.disabled', $row, ['module' => $module->key(), 'before' => $before, 'after' => false], $company, $by);
            });
        });

        $this->forget($company);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(string $key, Company $company): array
    {
        return CurrentCompany::run($company, fn (): array => (array) CompanyModule::query()->where('module_key', $key)->first()?->settings);
    }

    /**
     * Merges settings into the module's settings, keeping the others.
     *
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $key, Company $company, User $by, array $settings): void
    {
        $this->ensureEnabled($key, $company);

        CurrentCompany::run($company, function () use ($key, $company, $by, $settings): void {
            $row = CompanyModule::query()->where('module_key', $key)->firstOrFail();
            $before = (array) $row->settings;
            $row->forceFill(['settings' => [...$before, ...$settings]])->save();

            // Secrets (such as encrypted API keys) never reach the permanent audit log: only that they changed.
            $changes = Audit::changes($before, (array) $row->settings);
            $secret = fn (int|string $field): bool => str_contains((string) $field, 'key') || str_contains((string) $field, 'secret') || str_contains((string) $field, 'token');

            foreach (['before', 'after'] as $side) {
                foreach (array_keys($changes[$side]) as $field) {
                    if ($secret($field)) {
                        $changes[$side][$field] = '[ändrad]';
                    }
                }
            }

            Audit::record('module.settings_updated', $row, $changes, $company, $by);
        });
    }

    /**
     * Sets settings only where none are stored yet, for onEnable.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function seedSettings(string $key, Company $company, array $defaults): void
    {
        CurrentCompany::run($company, function () use ($key, $defaults): void {
            $row = CompanyModule::query()->where('module_key', $key)->firstOrFail();
            $row->forceFill(['settings' => [...$defaults, ...(array) $row->settings]])->save();
        });
    }

    /** @return array<string, Module> */
    public function enabledModules(?Company $company = null): array
    {
        return array_filter($this->registry->all(), fn (Module $module): bool => $this->isEnabled($module->key(), $company));
    }

    private function authorize(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'modules:manage')) {
            throw new AuthorizationException;
        }
    }

    /** @return list<string> */
    private function enabledKeys(int $companyId): array
    {
        // Per connection too: the demo database has companies with the same ids as the main one (ADR-077).
        return $this->enabled[DB::getDefaultConnection()][$companyId] ??= Cache::rememberForever(
            self::cacheKey($companyId),
            // Read for a given company, whichever company is current.
            fn (): array => CompanyScope::bypass(fn (): array => array_values(CompanyModule::query()
                ->where('company_id', $companyId)
                ->where('enabled', true)
                ->pluck('module_key')
                ->all())),
        );
    }

    private function forget(Company $company): void
    {
        unset($this->enabled[DB::getDefaultConnection()][$company->id]);
        Cache::forget(self::cacheKey($company->id));
    }

    private static function cacheKey(int $companyId): string
    {
        return "company:{$companyId}:modules";
    }
}
