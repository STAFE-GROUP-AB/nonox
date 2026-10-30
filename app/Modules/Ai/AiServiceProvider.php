<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Modules\Ai\Livewire\AiSettingsForm;
use App\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers NONOX AI. The providers themselves are the Laravel AI SDK's
 * built-in drivers, built per company with its own key (AnalyzeDocument).
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new AiModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'ai');

        Livewire::component(AiSettingsForm::NAME, AiSettingsForm::class);
    }
}
