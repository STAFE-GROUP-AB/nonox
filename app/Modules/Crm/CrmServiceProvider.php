<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\Customer;
use App\Modules\Crm\Console\RefreshStatusesCommand;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Livewire\CustomerFieldsForm;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Policies\CrmPolicy;
use App\Modules\Crm\Support\CustomerAccounts;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers CRM (ADR-043). Its own pages carry module:crm and answer 404
 * while it is off; /kunder is the core register's route and shows the CRM
 * list only while the module is on.
 */
class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new CrmModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'crm');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([Account::class, Person::class, Activity::class, AccountPerson::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }

        Customer::observe(CustomerAccounts::class);

        Livewire::component(CrmSettingsForm::NAME, CrmSettingsForm::class);
        Livewire::component('crm.accounts', AccountsPage::class);
        Livewire::component('crm.account-card', AccountCard::class);
        Livewire::component('crm.customer-fields', CustomerFieldsForm::class);
        Livewire::component('crm.activities', ActivitiesPage::class);
        Livewire::component('crm.people', PeoplePage::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshStatusesCommand::class]);
        }

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.CrmModule::KEY])
            ->group(__DIR__.'/routes/web.php');
    }
}
