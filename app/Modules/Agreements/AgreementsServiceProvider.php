<?php

declare(strict_types=1);

namespace App\Modules\Agreements;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Agreements\Console\ImportAgreementsCommand;
use App\Modules\Agreements\Console\VerifyAgreementCommand;
use App\Modules\Agreements\Domain\AgreementChecksStep;
use App\Modules\Agreements\Livewire\AgreementAiPage;
use App\Modules\Agreements\Livewire\AgreementsPage;
use App\Modules\Agreements\Livewire\AgreementsSettingsForm;
use App\Modules\Agreements\Livewire\AgreementVersionsPage;
use App\Modules\Agreements\Support\AgreementContextExtension;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Support\Run\PayrollExtensions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers NONOX Avtal (ADR-033). Its pages carry module:agreements (404
 * while off) and sit under Lön. It plugs into payroll runs through
 * PayrollExtensions: the extension and the step check per company that the
 * module is on, so payroll without it works exactly as before.
 */
class AgreementsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new AgreementsModule);
        $this->app->singleton(AgreementContextExtension::class);

        $this->callAfterResolving(PayrollExtensions::class, function (PayrollExtensions $extensions): void {
            $extensions->addContextExtension(fn (): AgreementContextExtension => $this->app->make(AgreementContextExtension::class));
            $extensions->addStep(fn (): AgreementChecksStep => new AgreementChecksStep);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'agreements');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportAgreementsCommand::class, VerifyAgreementCommand::class]);
        }

        Livewire::component(AgreementsSettingsForm::NAME, AgreementsSettingsForm::class);
        Livewire::component('agreements.page', AgreementsPage::class);
        Livewire::component('agreements.versions', AgreementVersionsPage::class);
        Livewire::component('agreements.ai', AgreementAiPage::class);

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.AgreementsModule::KEY])
            ->group(__DIR__.'/routes/web.php');
    }
}
