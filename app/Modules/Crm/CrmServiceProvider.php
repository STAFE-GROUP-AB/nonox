<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Events\CustomerAnonymised;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\Customer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Console\PruneMailCommand;
use App\Modules\Crm\Console\RefreshStatusesCommand;
use App\Modules\Crm\Console\SendMailingsCommand;
use App\Modules\Crm\Console\WinBackCommand;
use App\Modules\Crm\Http\CrmPersonExportController;
use App\Modules\Crm\Http\MailgunEventsController;
use App\Modules\Crm\Http\MailingLinkController;
use App\Modules\Crm\Http\PublicFormController;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Livewire\CustomerFieldsForm;
use App\Modules\Crm\Livewire\Dashboard;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Livewire\EmailsPage;
use App\Modules\Crm\Livewire\ForecastPage;
use App\Modules\Crm\Livewire\FormsPage;
use App\Modules\Crm\Livewire\MailingEditor;
use App\Modules\Crm\Livewire\MailingsPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Livewire\PipelineSettings;
use App\Modules\Crm\Livewire\SegmentsPage;
use App\Modules\Crm\Livewire\TemplatesPage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Form;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Models\Segment;
use App\Modules\Crm\Policies\CrmMailPolicy;
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

        // Stage 5 (ADR-048): templates, segments, mailings and forms are crm:settings or crm:mailings (CrmMailPolicy).
        foreach ([Email::class, EmailTemplate::class, Segment::class, Mailing::class, Form::class] as $model) {
            Gate::policy($model, CrmMailPolicy::class);
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
        Livewire::component('crm.emails', EmailsPage::class);
        Livewire::component('crm.templates', TemplatesPage::class);
        Livewire::component('crm.segments', SegmentsPage::class);
        Livewire::component('crm.mailings', MailingsPage::class);
        Livewire::component('crm.mailing-editor', MailingEditor::class);
        Livewire::component('crm.forms', FormsPage::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshStatusesCommand::class, WinBackCommand::class, SendMailingsCommand::class, PruneMailCommand::class]);
        }

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.CrmModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // A contact person's register extract also works while the module is off: GDPR rights do not stop with the module.
        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
            ->get('/registerutdrag/kontaktperson/{person}', CrmPersonExportController::class)
            ->whereNumber('person')
            ->name('privacy.export.crmPerson');

        $this->publicRoutes();
    }

    /**
     * Stage 5 (ADR-048): pages that people outside the company reach, outside
     * the web middleware so that no session or cookie is set and no CSRF
     * token is asked for (a mail client's one-click unsubscribe and a form
     * on the company's own site cannot carry one). They answer 404 when the
     * module is off, through their own lookups.
     */
    private function publicRoutes(): void
    {
        Route::middleware('throttle:60,1')->group(function (): void {
            Route::get('/formular/{token}', [PublicFormController::class, 'show'])->name('crm.forms.show');
            Route::post('/formular/{token}', [PublicFormController::class, 'submit'])->name('crm.forms.submit');
            Route::get('/utskick/avregistrera/{token}', [MailingLinkController::class, 'unsubscribePage'])->name('crm.mailing.unsubscribe');
        });

        // Mailbox providers send one-click unsubscribes (RFC 8058) from a few shared addresses: a high limit, so none is refused.
        Route::post('/utskick/avregistrera/{token}', [MailingLinkController::class, 'unsubscribe'])->middleware('throttle:1200,1')->name('crm.mailing.unsubscribe.confirm');

        Route::get('/utskick/lank/{token}/{link}', [MailingLinkController::class, 'click'])->whereNumber('link')->middleware('throttle:240,1')->name('crm.mailing.click');
        Route::post('/webhooks/utskick/mailgun', MailgunEventsController::class)->middleware('throttle:600,1')->name('crm.webhooks.mailgun');
    }
}
