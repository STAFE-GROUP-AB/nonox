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

        // Every provider has registered and booted its modules by now, and translations exist (ADR-079 §2.2).
        $this->app->booted(fn () => $this->app->make(ModuleRegistry::class)->verify());
    }

    public function boot(): void
    {
        Livewire::component('modules.company-modules-tab', CompanyModulesTab::class);
    }
}
