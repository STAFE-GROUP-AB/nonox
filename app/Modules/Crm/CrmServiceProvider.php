<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Events\CustomerAnonymised;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\Customer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Console\AgreementsCommand;
use App\Modules\Crm\Console\RefreshStatusesCommand;
use App\Modules\Crm\Console\WinBackCommand;
use App\Modules\Crm\Domain\SigningToken;
use App\Modules\Crm\Http\CrmPersonExportController;
use App\Modules\Crm\Http\SigningPageController;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\AgreementCard;
use App\Modules\Crm\Livewire\AgreementsPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Livewire\CustomerFieldsForm;
use App\Modules\Crm\Livewire\Dashboard;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Livewire\ForecastPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Livewire\PipelineSettings;
use App\Modules\Crm\Livewire\QuoteFromTemplate;
use App\Modules\Crm\Livewire\QuoteTemplatesPage;
use App\Modules\Crm\Livewire\SigningPanel;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Policies\CrmPolicy;
use App\Modules\Crm\Support\CustomerAccounts;
use App\Modules\Crm\Support\SigningFiles;
use App\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        foreach ([Account::class, Person::class, Activity::class, AccountPerson::class, Deal::class, Pipeline::class, PipelineStage::class, LossReason::class, Goal::class, Agreement::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }

        Customer::observe(CustomerAccounts::class);
        // An account erased under GDPR takes its agreements and their signing links with it (foreign keys); their PDFs go too (ADR-046).
        Account::deleting(fn (Account $account) => SigningFiles::forAccount($account));
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
        Livewire::component('crm.agreements', AgreementsPage::class);
        Livewire::component('crm.agreement-card', AgreementCard::class);
        Livewire::component('crm.quote-templates', QuoteTemplatesPage::class);
        Livewire::component(QuoteFromTemplate::NAME, QuoteFromTemplate::class);
        Livewire::component(SigningPanel::NAME, SigningPanel::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshStatusesCommand::class, WinBackCommand::class, AgreementsCommand::class]);
        }

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.CrmModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // A contact person's register extract also works while the module is off: GDPR rights do not stop with the module.
        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
            ->get('/registerutdrag/kontaktperson/{person}', CrmPersonExportController::class)
            ->whereNumber('person')
            ->name('privacy.export.crmPerson');

        $this->signingRoutes();
    }

    /**
     * The public signing page (ADR-046): no login, the token is the only
     * key. Not behind module:crm (there is no signed-in company); the page
     * itself refuses a link whose company has CRM off. Rate-limited per IP
     * address, and harder for the form posts.
     */
    private function signingRoutes(): void
    {
        RateLimiter::for('crm-signing', fn (Request $request): Limit => Limit::perMinute(60)->by('crm-signing:'.$request->ip()));
        RateLimiter::for('crm-signing-submit', fn (Request $request): Limit => Limit::perMinute(10)->by('crm-signing-submit:'.$request->ip()));

        Route::middleware(['web', 'throttle:crm-signing'])->group(function (): void {
            Route::get('/signera/{token}', [SigningPageController::class, 'show'])->where('token', '[^/]{1,100}')->name('crm.signing.show');
            Route::get('/signera/{token}/dokument', [SigningPageController::class, 'document'])->where('token', SigningToken::PATTERN)->name('crm.signing.document');
            Route::post('/signera/{token}', [SigningPageController::class, 'sign'])->where('token', SigningToken::PATTERN)->middleware('throttle:crm-signing-submit')->name('crm.signing.sign');
            Route::post('/signera/{token}/avboj', [SigningPageController::class, 'decline'])->where('token', SigningToken::PATTERN)->middleware('throttle:crm-signing-submit')->name('crm.signing.decline');
        });
    }
}
