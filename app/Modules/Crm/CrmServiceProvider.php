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
use App\Modules\Crm\Console\AgreementsCommand;
use App\Modules\Crm\Console\CalendarSyncCommand;
use App\Modules\Crm\Console\PruneMailCommand;
use App\Modules\Crm\Console\RefreshStatusesCommand;
use App\Modules\Crm\Console\RegistryWatchCommand;
use App\Modules\Crm\Console\SendMailingsCommand;
use App\Modules\Crm\Console\WinBackCommand;
use App\Modules\Crm\Domain\SigningToken;
use App\Modules\Crm\Http\CrmPersonExportController;
use App\Modules\Crm\Http\MailgunEventsController;
use App\Modules\Crm\Http\MailingLinkController;
use App\Modules\Crm\Http\PublicFormController;
use App\Modules\Crm\Http\SigningPageController;
use App\Modules\Crm\Http\WithoutAuditRequest;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\AgreementCard;
use App\Modules\Crm\Livewire\AgreementsPage;
use App\Modules\Crm\Livewire\CalendarPage;
use App\Modules\Crm\Livewire\CallListPage;
use App\Modules\Crm\Livewire\CallListsPage;
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
use App\Modules\Crm\Livewire\ProspectingPage;
use App\Modules\Crm\Livewire\QuoteFromTemplate;
use App\Modules\Crm\Livewire\QuoteTemplatesPage;
use App\Modules\Crm\Livewire\SegmentsPage;
use App\Modules\Crm\Livewire\SigningPanel;
use App\Modules\Crm\Livewire\TemplatesPage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\CallList;
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
use App\Modules\Crm\Support\CrmMailer;
use App\Modules\Crm\Support\CustomerAccounts;
use App\Modules\Crm\Support\SigningFiles;
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

    /** The mailer `crm` for CRM mail's own sending domain (ADR-048), when it is configured. */
    private function registerMailer(): void
    {
        $smtp = CrmMailer::smtpConfig();

        if ($smtp !== null) {
            config(['mail.mailers.crm' => $smtp]);
        }
    }

    public function boot(): void
    {
        $this->registerMailer();
        $this->loadViewsFrom(__DIR__.'/views', 'crm');
        // <x-crm::…>: the parts the account card and the person page share.
        Blade::anonymousComponentPath(__DIR__.'/views/components', 'crm');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([Account::class, Person::class, Activity::class, AccountPerson::class, Deal::class, Pipeline::class, PipelineStage::class, LossReason::class, Goal::class, CallList::class, Agreement::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }

        // Stage 5 (ADR-048): templates, segments, mailings and forms are crm:settings or crm:mailings (CrmMailPolicy).
        foreach ([Email::class, EmailTemplate::class, Segment::class, Mailing::class, Form::class] as $model) {
            Gate::policy($model, CrmMailPolicy::class);
        }

        Customer::observe(CustomerAccounts::class);
        // An account erased under GDPR takes its agreements and their signing links with it (foreign keys); their PDFs go too (ADR-046).
        Account::deleting(fn (Account $account) => SigningFiles::forAccount($account));
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
        Livewire::component('crm.agreements', AgreementsPage::class);
        Livewire::component('crm.agreement-card', AgreementCard::class);
        Livewire::component('crm.quote-templates', QuoteTemplatesPage::class);
        Livewire::component(QuoteFromTemplate::NAME, QuoteFromTemplate::class);
        Livewire::component(SigningPanel::NAME, SigningPanel::class);
        Livewire::component('crm.emails', EmailsPage::class);
        Livewire::component('crm.templates', TemplatesPage::class);
        Livewire::component('crm.segments', SegmentsPage::class);
        Livewire::component('crm.mailings', MailingsPage::class);
        Livewire::component('crm.mailing-editor', MailingEditor::class);
        Livewire::component('crm.forms', FormsPage::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshStatusesCommand::class, WinBackCommand::class, RegistryWatchCommand::class, CalendarSyncCommand::class, AgreementsCommand::class, SendMailingsCommand::class, PruneMailCommand::class]);
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

        $this->signingRoutes();
        $this->publicRoutes();
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

        Route::middleware(['web', 'throttle:crm-signing', WithoutAuditRequest::class])->group(function (): void {
            Route::get('/signera/{token}', [SigningPageController::class, 'show'])->where('token', '[^/]{1,100}')->name('crm.signing.show');
            Route::get('/signera/{token}/dokument', [SigningPageController::class, 'document'])->where('token', SigningToken::PATTERN)->name('crm.signing.document');
            Route::post('/signera/{token}', [SigningPageController::class, 'sign'])->where('token', SigningToken::PATTERN)->middleware('throttle:crm-signing-submit')->name('crm.signing.sign');
            Route::post('/signera/{token}/avboj', [SigningPageController::class, 'decline'])->where('token', SigningToken::PATTERN)->middleware('throttle:crm-signing-submit')->name('crm.signing.decline');
        });
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
        // A click link names only the link (second review round): the same address for every recipient.

        Route::get('/utskick/lank/{link}/{sig}', [MailingLinkController::class, 'click'])->whereNumber('link')->where('sig', '[0-9a-f]{32}')->middleware('throttle:600,1')->name('crm.mailing.click');
        // Mailgun posts delivery events in bursts after a large mailing.
        Route::post('/webhooks/utskick/mailgun', MailgunEventsController::class)->middleware('throttle:6000,1')->name('crm.webhooks.mailgun');
    }
}
