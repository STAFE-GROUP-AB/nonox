<?php

declare(strict_types=1);

namespace App\Modules;

use App\Modules\Livewire\CompanyModulesTab;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * The module framework itself. Each module has its own service provider
 * that registers the module in the ModuleRegistry.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        $this->app->scoped(ModuleManager::class);

        $this->app->booting(fn () => $this->checkModules());
    }

    /**
     * Runs when every provider has registered and none has booted (ADR-079
     * §2.2): closes the registry to further modules and checks them, so a
     * refused module stops the boot before its routes, listeners or role
     * grants exist. The check reads roles(), whose labels are translated;
     * that caches the JSON strings before any provider has added its own in
     * boot() (loadJsonTranslationsFrom), so the cache is emptied again.
     */
    public function checkModules(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $registry->close();

        try {
            $registry->verify();
        } finally {
            if ($this->app->resolved('translator')) {
                $this->app->make('translator')->setLoaded([]);
            }
        }
    }

    public function boot(): void
    {
        Livewire::component('modules.company-modules-tab', CompanyModulesTab::class);
    }
}
