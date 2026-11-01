<?php

declare(strict_types=1);

namespace App\Modules\Banking;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Banking\Console\FetchBankDataCommand;
use App\Modules\Banking\Console\RemindBankConsentCommand;
use App\Modules\Banking\Livewire\BankingPage;
use App\Modules\Banking\Livewire\BankingSettingsForm;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Policies\BankConnectionPolicy;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers NONOX Banking (ADR-034). Its pages carry module:banking and
 * answer 404 for companies that have not turned it on, the bank's answer
 * (callback) included, which is also rate limited.
 */
class BankingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new BankingModule);
        $this->app->singleton(FakeBankDataProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'banking');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([FetchBankDataCommand::class, RemindBankConsentCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('bank:fetch')->hourlyAt(17)->withoutOverlapping();
            $schedule->command('bank:remind-consent')->dailyAt('07:10')->withoutOverlapping();
        });

        Gate::policy(BankConnection::class, BankConnectionPolicy::class);

        Livewire::component(BankingSettingsForm::NAME, BankingSettingsForm::class);
        Livewire::component('banking.page', BankingPage::class);

        RateLimiter::for('banking-callback', fn (Request $request): Limit => Limit::perMinute(10)->by('banking-callback:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.BankingModule::KEY])
            ->group(__DIR__.'/routes/web.php');
    }
}
