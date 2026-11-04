<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Events\CustomerAnonymised;
use App\Events\UserAccountDeleting;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Customer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Calendar\ActivityCalendarObserver;
use App\Modules\Crm\Calendar\CalendarSequence;
use App\Modules\Crm\Calendar\ForgetUserCalendars;
use App\Modules\Crm\Console\CalendarSyncCommand;
use App\Modules\Crm\Console\RefreshStatusesCommand;
use App\Modules\Crm\Console\RegistryWatchCommand;
use App\Modules\Crm\Console\WinBackCommand;
use App\Modules\Crm\Http\CrmPersonExportController;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\CalendarPage;
use App\Modules\Crm\Livewire\CallListPage;
use App\Modules\Crm\Livewire\CallListsPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Livewire\CustomerFieldsForm;
use App\Modules\Crm\Livewire\Dashboard;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Livewire\ForecastPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Livewire\PipelineSettings;
use App\Modules\Crm\Livewire\ProspectingPage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Policies\CrmPolicy;
use App\Modules\Crm\Support\CustomerAccounts;
use App\Modules\ModuleRegistry;
use App\Support\CreditData\CreditData;
use App\Support\CreditData\NullCreditData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Events\TeamMemberRemoved;
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
        // Credit information (ADR-047): none until a contract exists and a driver is configured.
        $this->app->bind(CreditData::class, fn (): CreditData => NullCreditData::resolve());
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'crm');
        // <x-crm::…>: the parts the account card and the person page share.
        Blade::anonymousComponentPath(__DIR__.'/views/components', 'crm');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([Account::class, Person::class, Activity::class, AccountPerson::class, Deal::class, Pipeline::class, PipelineStage::class, LossReason::class, Goal::class, CallList::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }

        Customer::observe(CustomerAccounts::class);
        Event::listen(CustomerAnonymised::class, [EraseCrmData::class, 'customerAnonymised']);
        // Stage 6 (ADR-049): calendars follow the activities, and end with the user's place in the company.
        Activity::observe(ActivityCalendarObserver::class);
        Account::updated(fn (Account $account) => app(CalendarSequence::class)->accountUpdated($account));
        Person::updated(fn (Person $person) => app(CalendarSequence::class)->personUpdated($person));
        Deal::updated(fn (Deal $deal) => app(CalendarSequence::class)->dealUpdated($deal));
        AccountPerson::created(fn (AccountPerson $link) => app(CalendarSequence::class)->linkCreated($link));
        Event::listen(TeamMemberRemoved::class, [ForgetUserCalendars::class, 'memberRemoved']);
        Event::listen(UserAccountDeleting::class, [ForgetUserCalendars::class, 'accountDeleting']);

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
        Livewire::component('crm.prospecting', ProspectingPage::class);
        Livewire::component('crm.call-lists', CallListsPage::class);
        Livewire::component('crm.call-list', CallListPage::class);
        Livewire::component('crm.calendar', CalendarPage::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshStatusesCommand::class, WinBackCommand::class, RegistryWatchCommand::class, CalendarSyncCommand::class]);
        }

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.CrmModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.CrmModule::KEY])
            ->group(__DIR__.'/routes/calendar.php');

        // The subscription link: no session, no login, throttled per address (ADR-049).
        // Per address and link, so one office's calendars do not share a limit; unknown links have a stricter one of their own (CalendarFeedController).
        RateLimiter::for('crm-calendar', fn (Request $request): Limit => Limit::perMinute(30)->by('crm-calendar:'.$request->ip().'|'.hash('sha256', (string) $request->route('token'))));
        Route::middleware([SecurityHeaders::class, 'throttle:crm-calendar'])->group(__DIR__.'/routes/calendar-feed.php');

        // A contact person's register extract also works while the module is off: GDPR rights do not stop with the module.
        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
            ->get('/registerutdrag/kontaktperson/{person}', CrmPersonExportController::class)
            ->whereNumber('person')
            ->name('privacy.export.crmPerson');
    }
}
