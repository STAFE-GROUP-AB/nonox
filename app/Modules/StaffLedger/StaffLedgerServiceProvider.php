<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\ModuleRegistry;
use App\Modules\StaffLedger\Console\RemindForgottenCheckOutsCommand;
use App\Modules\StaffLedger\Console\VerifyStaffLedgerCommand;
use App\Modules\StaffLedger\Livewire\ControlVisitPage;
use App\Modules\StaffLedger\Livewire\LedgerPage;
use App\Modules\StaffLedger\Livewire\LocationsPage;
use App\Modules\StaffLedger\Livewire\MyCheckIn;
use App\Modules\StaffLedger\Livewire\OverviewPage;
use App\Modules\StaffLedger\Livewire\PeoplePage;
use App\Modules\StaffLedger\Livewire\StaffLedgerSettingsForm;
use App\Modules\StaffLedger\Livewire\TimeProposalsPage;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Policies\EntryPolicy;
use App\Modules\StaffLedger\Policies\LocationPolicy;
use App\Modules\StaffLedger\Policies\PersonPolicy;
use App\Modules\StaffLedger\Support\Export\LedgerXmlFormat;
use App\Modules\StaffLedger\Support\Export\MockSkatteverketTransfer;
use App\Modules\StaffLedger\Support\Export\ProvisionalLedgerXml;
use App\Modules\StaffLedger\Support\Export\SkatteverketTransfer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers the staff ledger module (ADR-022). The app's pages carry
 * module:staff_ledger and answer 404 while it is off; the ledger and the
 * control visit stay reachable for the retention period. The kiosk's routes
 * have no session: they are authenticated by the device token.
 */
class StaffLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new StaffLedgerModule);
        $this->app->singleton(LedgerXmlFormat::class, ProvisionalLedgerXml::class);
        $this->app->singleton(SkatteverketTransfer::class, MockSkatteverketTransfer::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'staff-ledger');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyStaffLedgerCommand::class, RemindForgottenCheckOutsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Nightly, like ledger:verify; reminders every quarter of an hour.
            $schedule->command('personalliggare:verify')->dailyAt('03:30')->withoutOverlapping();
            $schedule->command('personalliggare:remind')->everyFifteenMinutes()->withoutOverlapping();
        });

        Gate::policy(Location::class, LocationPolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);
        Gate::policy(Entry::class, EntryPolicy::class);

        Livewire::component(StaffLedgerSettingsForm::NAME, StaffLedgerSettingsForm::class);
        Livewire::component('staff-ledger.overview', OverviewPage::class);
        Livewire::component('staff-ledger.locations', LocationsPage::class);
        Livewire::component('staff-ledger.people', PeoplePage::class);
        Livewire::component('staff-ledger.ledger', LedgerPage::class);
        Livewire::component('staff-ledger.control-visit', ControlVisitPage::class);
        Livewire::component('staff-ledger.proposals', TimeProposalsPage::class);
        Livewire::component('staff-ledger.mine', MyCheckIn::class);

        RateLimiter::for('staff-ledger-kiosk', fn (Request $request): Limit => Limit::perMinute(120)->by('kiosk:'.$request->ip()));
        RateLimiter::for('staff-ledger-pairing', fn (Request $request): Limit => Limit::perMinute(10)->by('kiosk-pair:'.$request->ip()));

        $middleware = ['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class];
        Route::middleware([...$middleware, 'module:'.StaffLedgerModule::KEY])->group(__DIR__.'/routes/web.php');
        Route::middleware($middleware)->group(__DIR__.'/routes/archive.php');
        Route::middleware([SecurityHeaders::class, 'throttle:staff-ledger-kiosk'])->group(__DIR__.'/routes/kiosk.php');
    }
}
