<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Events\CustomerAnonymised;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\Customer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Console\RefreshStatusesCommand;
use App\Modules\Crm\Console\WinBackCommand;
use App\Modules\Crm\Http\CrmPersonExportController;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Livewire\CustomerFieldsForm;
use App\Modules\Crm\Livewire\Dashboard;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Livewire\ForecastPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Livewire\PipelineSettings;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Policies\CrmPolicy;
use App\Modules\Crm\Support\CustomerAccounts;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
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
        // <x-crm::…>: the parts the account card and the person page share.
        Blade::anonymousComponentPath(__DIR__.'/views/components', 'crm');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([Account::class, Person::class, Activity::class, AccountPerson::class, Deal::class, Pipeline::class, PipelineStage::class, LossReason::class, Goal::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }

        Customer::observe(CustomerAccounts::class);
        Event::listen(CustomerAnonymised::class, [EraseCrmData::class, 'customerAnonymised']);

        Livewire::component(CrmSettingsForm::NAME, CrmSettingsForm::class);
        Livewire::component('crm.accounts', AccountsPage::class);
        Livewire::component('crm.account-card', AccountCard::class);
        Livewire::component('crm.customer-fields', CustomerFieldsForm::class);
        Livewire::component('crm.activities', ActivitiesPage::class);
        Livewire::component('crm.people', PeoplePage::class);
        Livewire::component('crm.person-card', PersonCard::class);
        Livewire::component('crm.dashboard', Dashboard::class);
        Livewire::component('crm.deals', DealsBoard::class);
        Livewire::component('crm.deal-card', DealCard::class);
        Livewire::component('crm.forecast', ForecastPage::class);
        Livewire::component('crm.pipeline-settings', PipelineSettings::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshStatusesCommand::class, WinBackCommand::class]);
        }

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.CrmModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // A contact person's register extract also works while the module is off: GDPR rights do not stop with the module.
        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
            ->get('/registerutdrag/kontaktperson/{person}', CrmPersonExportController::class)
            ->whereNumber('person')
            ->name('privacy.export.crmPerson');
    }
}
