<?php

declare(strict_types=1);

namespace App\Modules\Peppol;

use App\Modules\ModuleRegistry;
use App\Modules\Peppol\Console\LinkPeppolAccountCommand;
use App\Modules\Peppol\Console\SyncPeppolCommand;
use App\Modules\Peppol\Http\PeppolWebhookController;
use App\Modules\Peppol\Livewire\InvoicePeppolPanel;
use App\Modules\Peppol\Livewire\PeppolSettingsForm;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Peppol e-faktura (ADR-050). The webhook has no session or
 * CSRF: the driver verifies the provider's signature, and the module is
 * checked per company when an event is handled.
 */
class PeppolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new PeppolModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'peppol');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPeppolCommand::class, LinkPeppolAccountCommand::class]);
        }

        Livewire::component(PeppolSettingsForm::NAME, PeppolSettingsForm::class);
        Livewire::component(InvoicePeppolPanel::NAME, InvoicePeppolPanel::class);

        Route::post('/webhooks/peppol/{driver}', PeppolWebhookController::class)
            ->whereAlpha('driver')
            ->middleware('throttle:600,1')
            ->name('peppol.webhook');
    }
}
