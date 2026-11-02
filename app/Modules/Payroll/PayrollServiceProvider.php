<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Console\ImportPayrollRulesCommand;
use App\Modules\Payroll\Console\ImportTaxTablesCommand;
use App\Modules\Payroll\Console\VerifyPayrollRuleCommand;
use App\Modules\Payroll\Http\Middleware\SendEmployeesToPortal;
use App\Modules\Payroll\Listeners\LinkEmployeeLogin;
use App\Modules\Payroll\Livewire\AbsenceRequestList;
use App\Modules\Payroll\Livewire\AgiPage;
use App\Modules\Payroll\Livewire\CompTimePage;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Livewire\MyAbsence;
use App\Modules\Payroll\Livewire\MyExpenses;
use App\Modules\Payroll\Livewire\MyPayslips;
use App\Modules\Payroll\Livewire\PayrollArchive;
use App\Modules\Payroll\Livewire\PayrollFileImportPage;
use App\Modules\Payroll\Livewire\PayrollHome;
use App\Modules\Payroll\Livewire\PayrollImportSettings;
use App\Modules\Payroll\Livewire\PayrollMigrationPage;
use App\Modules\Payroll\Livewire\PayrollReportsPage;
use App\Modules\Payroll\Livewire\PayrollRules;
use App\Modules\Payroll\Livewire\PayrollRunList;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Livewire\PayrollSettingsForm;
use App\Modules\Payroll\Livewire\VacationLiabilityReport;
use App\Modules\Payroll\Livewire\VacationYearPage;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Policies\EmployeePolicy;
use App\Modules\Payroll\Policies\PayrollRunPolicy;
use App\Modules\Payroll\Policies\PayslipPolicy;
use App\Modules\Payroll\Search\EmployeesSearchProvider;
use App\Modules\Payroll\Search\PayslipsSearchProvider;
use App\Modules\Payroll\Support\Run\PayrollExtensions;
use App\Modules\Payroll\Support\Run\TimeReportPay;
use App\Support\Search\SearchAction;
use App\Support\Search\SearchActions;
use App\Support\Search\SearchRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Livewire\Livewire;

/**
 * Registers the payroll module. Its routes always carry module:payroll, so
 * they answer 404 for companies that have not turned payroll on.
 */
class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new PayrollModule);

        // Spotlight (ADR-026): found only while payroll is on for the company.
        $this->callAfterResolving(SearchRegistry::class, function (SearchRegistry $search): void {
            $search->register(new EmployeesSearchProvider);
            $search->register(new PayslipsSearchProvider);
        });
        $this->callAfterResolving(SearchActions::class, function (SearchActions $actions): void {
            $actions->register(
                new SearchAction('payroll-run', 'Ny lönekörning', 'payroll.runs.index', 'payroll:run', keywords: ['lön', 'betala lön', 'lönekörning'], module: PayrollModule::KEY, icon: 'payslip'),
                new SearchAction('employee', 'Lägg till anställd', 'payroll.employees', 'employees:manage', query: ['ny' => 1], keywords: ['ny anställd', 'nyanställning'], module: PayrollModule::KEY),
            );
        });
        // One instance, so other modules can add the hours they hold for approval.
        $this->app->singleton(TimeReportPay::class);
        // One instance, so other modules can add their part of a run (NONOX Avtal, ADR-033).
        $this->app->singleton(PayrollExtensions::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'payroll');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPayrollRulesCommand::class, VerifyPayrollRuleCommand::class, ImportTaxTablesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Every year's rule file into the database, unverified; checked versions are never touched (PAYROLL_MODULE.md B.6).
            $schedule->command('payroll:import-rules')->dailyAt('03:40')->withoutOverlapping();
        });

        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(Payslip::class, PayslipPolicy::class);

        Livewire::component(PayrollSettingsForm::NAME, PayrollSettingsForm::class);
        Livewire::component('payroll.home', PayrollHome::class);
        Livewire::component('payroll.rules', PayrollRules::class);
        Livewire::component('payroll.employees', Employees::class);
        Livewire::component('payroll.run-list', PayrollRunList::class);
        Livewire::component('payroll.run-page', PayrollRunPage::class);
        Livewire::component('payroll.my-payslips', MyPayslips::class);
        Livewire::component('payroll.vacation-liability', VacationLiabilityReport::class);
        Livewire::component('payroll.comp-time', CompTimePage::class);
        Livewire::component('payroll.my-absence', MyAbsence::class);
        Livewire::component('payroll.my-expenses', MyExpenses::class);
        Livewire::component('payroll.vacation-year', VacationYearPage::class);
        Livewire::component('payroll.absence-requests', AbsenceRequestList::class);
        Livewire::component('payroll.agi', AgiPage::class);
        Livewire::component('payroll.reports', PayrollReportsPage::class);
        Livewire::component('payroll.archive', PayrollArchive::class);
        Livewire::component('payroll.migration', PayrollMigrationPage::class);
        Livewire::component('payroll.file-import', PayrollFileImportPage::class);
        Livewire::component('payroll.import-sources', PayrollImportSettings::class);

        Blade::anonymousComponentPath(__DIR__.'/views/components', 'payroll');
        Event::listen(TeamMemberAdded::class, LinkEmployeeLogin::class);
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', SendEmployeesToPortal::class);

        $middleware = ['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.PayrollModule::KEY];
        Route::middleware($middleware)->group(__DIR__.'/routes/web.php');
        Route::middleware($middleware)->group(__DIR__.'/routes/portal.php');
        Route::middleware(array_values(array_diff($middleware, ['module:'.PayrollModule::KEY])))->group(__DIR__.'/routes/archive.php');
    }
}
