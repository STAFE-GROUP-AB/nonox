<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Console\ImportPayrollRulesCommand;
use App\Modules\Payroll\Console\ImportTaxTablesCommand;
use App\Modules\Payroll\Console\VerifyPayrollRuleCommand;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Livewire\PayrollHome;
use App\Modules\Payroll\Livewire\PayrollRules;
use App\Modules\Payroll\Livewire\PayrollSettingsForm;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Policies\EmployeePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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

        Livewire::component(PayrollSettingsForm::NAME, PayrollSettingsForm::class);
        Livewire::component('payroll.home', PayrollHome::class);
        Livewire::component('payroll.rules', PayrollRules::class);
        Livewire::component('payroll.employees', Employees::class);

        Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.PayrollModule::KEY])
            ->group(__DIR__.'/routes/web.php');
    }
}
