<?php

declare(strict_types=1);

namespace App\Modules\Signing;

use App\Domain\Signing\SignableTypes;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\ModuleRegistry;
use App\Modules\Signing\Livewire\EnvelopePage;
use App\Modules\Signing\Livewire\EnvelopesPage;
use App\Modules\Signing\Livewire\SendWizard;
use App\Modules\Signing\Livewire\TemplatePage;
use App\Modules\Signing\Livewire\TemplatesPage;
use App\Modules\Signing\Models\SigningDocument;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Registers Signering (ADR-046 amendment, gap 10, package S7). The send
 * wizard carries module:signing and answers 404 while the module is off;
 * the list and the envelope page are reachable with signing:view also then,
 * showing only signed standalone documents (the archive). The engine itself
 * is core (App\Providers\SigningServiceProvider); this provider adds the
 * DOCUMENT type to its registry.
 */
class SigningModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new SigningModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'signing-module');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->app->make(SignableTypes::class)->register(SigningDocument::SIGNING_TYPE, SignableTypes::GENERIC_COLUMN, function (string $key, bool $lock): ?SigningDocument {
            if (! Str::isUuid($key)) {
                return null;
            }

            $query = SigningDocument::query()->whereKey($key);

            return ($lock ? $query->lockForUpdate() : $query)->first();
        });

        Livewire::component('signing.envelopes', EnvelopesPage::class);
        Livewire::component('signing.envelope', EnvelopePage::class);
        Livewire::component('signing.wizard', SendWizard::class);
        Livewire::component('signing.templates', TemplatesPage::class);
        Livewire::component('signing.template', TemplatePage::class);

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.SigningModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
            ->group(__DIR__.'/routes/archive.php');
    }
}
