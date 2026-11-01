<?php

declare(strict_types=1);

namespace App\Modules\Inbox;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Inbox\Console\FetchInboxCommand;
use App\Modules\Inbox\Console\InboxSummaryCommand;
use App\Modules\Inbox\Console\PruneInboxCommand;
use App\Modules\Inbox\Http\MailgunWebhookController;
use App\Modules\Inbox\Livewire\InboxSettingsForm;
use App\Modules\Inbox\Livewire\InboxWorkspace;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Policies\InboxMessagePolicy;
use App\Modules\Inbox\Support\ClamdScanner;
use App\Modules\Inbox\Support\Imap\MailboxClient;
use App\Modules\Inbox\Support\Imap\WebklexMailboxClient;
use App\Modules\Inbox\Support\NoVirusScanner;
use App\Modules\Inbox\Support\VirusScanner;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers NONOX Inbox. Its pages carry module:inbox and answer 404 for
 * companies that have not turned it on. The Mailgun webhook has no session
 * or CSRF: it is authenticated by Mailgun's signature, and it checks the
 * module per company itself.
 */
class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new InboxModule);

        $this->app->bind(MailboxClient::class, WebklexMailboxClient::class);
        $this->app->bind(VirusScanner::class, function (): VirusScanner {
            $socket = (string) config('services.inbox.clamd_socket');

            return $socket === '' ? new NoVirusScanner : new ClamdScanner($socket);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'inbox');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([FetchInboxCommand::class, InboxSummaryCommand::class, PruneInboxCommand::class]);
        }

        Gate::policy(InboxMessage::class, InboxMessagePolicy::class);

        Livewire::component(InboxSettingsForm::NAME, InboxSettingsForm::class);
        Livewire::component('inbox.workspace', InboxWorkspace::class);

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.InboxModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // The raw message must reach the inbox byte for byte: no trimming of the webhook's fields.
        $webhook = fn (Request $request): bool => $request->is('webhooks/inkorg/*');
        TrimStrings::skipWhen($webhook);
        ConvertEmptyStringsToNull::skipWhen($webhook);

        Route::post('/webhooks/inkorg/mailgun/mime', MailgunWebhookController::class)
            ->middleware('throttle:600,1')
            ->name('inbox.webhooks.mailgun');
    }
}
