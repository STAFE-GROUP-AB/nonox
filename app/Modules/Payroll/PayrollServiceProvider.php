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
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Livewire\MyAbsence;
use App\Modules\Payroll\Livewire\MyExpenses;
use App\Modules\Payroll\Livewire\MyPayslips;
use App\Modules\Payroll\Livewire\PayrollHome;
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
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'payroll');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPayrollRulesCommand::class, VerifyPayrollRuleCommand::class, ImportTaxTablesCommand::class]);
        }

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
        Livewire::component('payroll.my-absence', MyAbsence::class);
        Livewire::component('payroll.my-expenses', MyExpenses::class);
        Livewire::component('payroll.vacation-year', VacationYearPage::class);
        Livewire::component('payroll.absence-requests', AbsenceRequestList::class);

        Blade::anonymousComponentPath(__DIR__.'/views/components', 'payroll');
        Event::listen(TeamMemberAdded::class, LinkEmployeeLogin::class);
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', SendEmployeesToPortal::class);

        $middleware = ['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.PayrollModule::KEY];
        Route::middleware($middleware)->group(__DIR__.'/routes/web.php');
        Route::middleware($middleware)->group(__DIR__.'/routes/portal.php');
    }
}
