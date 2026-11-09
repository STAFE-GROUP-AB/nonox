<?php

declare(strict_types=1);

namespace App\Modules\Saas;

use App\Events\RecurringInvoiceChanged;
use App\Events\RecurringInvoiceStatusChanged;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Crm\Events\CrmAgreementChanged;
use App\Modules\ModuleRegistry;
use App\Modules\Saas\Console\CloseSaasMonthCommand;
use App\Modules\Saas\Console\RecomputeSaasCommand;
use App\Modules\Saas\Console\VerifySaasCommand;
use App\Modules\Saas\Listeners\RecordContractVersion;
use App\Modules\Saas\Livewire\CohortsPage;
use App\Modules\Saas\Livewire\CorrectionsPage;
use App\Modules\Saas\Livewire\CustomerPage;
use App\Modules\Saas\Livewire\CustomersPage;
use App\Modules\Saas\Livewire\MonthsPage;
use App\Modules\Saas\Livewire\MovementPage;
use App\Modules\Saas\Livewire\MovementsPage;
use App\Modules\Saas\Livewire\OverviewPage;
use App\Modules\Saas\Livewire\ScheduledPage;
use App\Modules\Saas\Livewire\SourcesPage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers NONOX SaaS (ADR-080): the module, its listeners on the agreement
 * events, the nightly recompute and verification, and the pages under
 * /aterkommande-intakter, which answer 404 while the module is off.
 */
class SaasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new SaasModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'saas');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        Event::listen(RecurringInvoiceChanged::class, [RecordContractVersion::class, 'recurringInvoice']);
        Event::listen(RecurringInvoiceStatusChanged::class, [RecordContractVersion::class, 'recurringInvoiceStatus']);
        Event::listen(CrmAgreementChanged::class, [RecordContractVersion::class, 'crmAgreement']);

        if ($this->app->runningInConsole()) {
            $this->commands([RecomputeSaasCommand::class, VerifySaasCommand::class, CloseSaasMonthCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // After midnight, so the current month follows the calendar; verification after ledger:verify.
            $schedule->command('saas:recompute')->dailyAt('02:20')->withoutOverlapping();
            $schedule->command('saas:verify')->dailyAt('03:50')->withoutOverlapping();
        });

        Livewire::component('saas.overview', OverviewPage::class);
        Livewire::component('saas.movements', MovementsPage::class);
        Livewire::component('saas.movement', MovementPage::class);
        Livewire::component('saas.customers', CustomersPage::class);
        Livewire::component('saas.customer', CustomerPage::class);
        Livewire::component('saas.cohorts', CohortsPage::class);
        Livewire::component('saas.scheduled', ScheduledPage::class);
        Livewire::component('saas.corrections', CorrectionsPage::class);
        Livewire::component('saas.months', MonthsPage::class);
        Livewire::component('saas.sources', SourcesPage::class);

        $middleware = ['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.SaasModule::KEY];
        Route::middleware($middleware)->group(__DIR__.'/routes/web.php');
    }
}
