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
    }

    public function boot(): void
    {
        Livewire::component('modules.company-modules-tab', CompanyModulesTab::class);
    }
}
