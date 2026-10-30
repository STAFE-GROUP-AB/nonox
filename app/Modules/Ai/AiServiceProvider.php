<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Modules\Ai\Livewire\AiSettingsForm;
use App\Modules\Ai\Support\GozeroCore\AnalysisReceipt;
use App\Modules\Ai\Support\GozeroCore\GozeroCoreProvider;
use App\Modules\ModuleRegistry;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Livewire\Livewire;

/**
 * Registers NONOX AI and teaches the Laravel AI SDK the "gozerocore" driver.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new AiModule);
        $this->app->scoped(AnalysisReceipt::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'ai');

        $this->app->make(AiManager::class)->extend('gozerocore', fn (Application $app, array $config): GozeroCoreProvider => new GozeroCoreProvider($config, $app->make(Dispatcher::class)));

        Livewire::component(AiSettingsForm::NAME, AiSettingsForm::class);
    }
}
