<?php

declare(strict_types=1);

namespace App\Modules\Connect;

use App\Events\InvoicePaid;
use App\Events\InvoiceSent;
use App\Events\SupplierInvoiceApproved;
use App\Events\VoucherPosted;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Connect\Console\ConnectAppCommand;
use App\Modules\Connect\Console\DeliverWebhooksCommand;
use App\Modules\Connect\Console\PruneConnectCommand;
use App\Modules\Connect\Console\TestCompanyCommand;
use App\Modules\Connect\Http\Controllers\OAuth\AuthorizeController;
use App\Modules\Connect\Http\Controllers\OAuth\MetadataController;
use App\Modules\Connect\Http\Controllers\OAuth\RevokeController;
use App\Modules\Connect\Http\Controllers\OAuth\TokenController;
use App\Modules\Connect\Http\Middleware\AuditApiWrites;
use App\Modules\Connect\Http\Middleware\AuthenticateConnect;
use App\Modules\Connect\Http\Middleware\ConnectJson;
use App\Modules\Connect\Http\Middleware\ConnectRateLimit;
use App\Modules\Connect\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Connect\Http\Middleware\RequireScope;
use App\Modules\Connect\Livewire\ConnectionsManager;
use App\Modules\Connect\Livewire\ConnectSettingsForm;
use App\Modules\Connect\Livewire\ConsentScreen;
use App\Modules\Connect\Livewire\TestCompanyPanel;
use App\Modules\Connect\Livewire\WebhooksManager;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\OAuth\ConnectAccessTokenRepository;
use App\Modules\Connect\OAuth\ConnectAuthCodeRepository;
use App\Modules\Connect\OAuth\ConnectRefreshTokenRepository;
use App\Modules\Connect\OAuth\GrantContext;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Connect\Webhooks\WebhookDispatcher;
use App\Modules\ModuleRegistry;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * Registers NONOX Connect (ADR-032): the module, Passport configured for
 * authorization code + PKCE and rotating refresh tokens only, the OAuth
 * endpoints, API v1 and the pages under Anslutningar.
 */
class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new ConnectModule);

        $this->app->scoped(GrantContext::class);
        $this->app->scoped(ConnectAccess::class);

        // Every code and token is tied to its grant, and a reused refresh token or code revokes the grant.
        $this->app->bind(AuthCodeRepository::class, ConnectAuthCodeRepository::class);
        $this->app->bind(AccessTokenRepository::class, ConnectAccessTokenRepository::class);
        $this->app->bind(RefreshTokenRepository::class, ConnectRefreshTokenRepository::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'connect');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ConnectAppCommand::class, PruneConnectCommand::class, DeliverWebhooksCommand::class, TestCompanyCommand::class]);
        }

        // Webhooks (ADR-032): the domain events are dispatched after commit.
        Event::listen(VoucherPosted::class, [WebhookDispatcher::class, 'voucherPosted']);
        Event::listen(InvoiceSent::class, [WebhookDispatcher::class, 'invoiceSent']);
        Event::listen(InvoicePaid::class, [WebhookDispatcher::class, 'invoicePaid']);
        Event::listen(SupplierInvoiceApproved::class, [WebhookDispatcher::class, 'supplierInvoiceApproved']);

        $this->configurePassport();

        Livewire::component(ConsentScreen::NAME, ConsentScreen::class);
        Livewire::component(ConnectionsManager::NAME, ConnectionsManager::class);
        Livewire::component(ConnectSettingsForm::NAME, ConnectSettingsForm::class);
        Livewire::component(WebhooksManager::NAME, WebhooksManager::class);
        Livewire::component(TestCompanyPanel::NAME, TestCompanyPanel::class);

        $this->registerRateLimits();
        $this->registerRoutes();
    }

    private function configurePassport(): void
    {
        Passport::ignoreRoutes();
        Passport::useClientModel(ConnectApp::class);
        Passport::$deviceCodeGrantEnabled = false;
        Passport::$implicitGrantEnabled = false;
        Passport::$passwordGrantEnabled = false;
        Passport::$revokeRefreshTokenAfterUse = true;
        Passport::tokensCan(ConnectScopes::labels());

        $minutes = max(1, min(15, (int) config('services.connect.access_token_minutes', 15)));
        Passport::tokensExpireIn(new DateInterval("PT{$minutes}M"));
        Passport::refreshTokensExpireIn(new DateInterval('P'.max(1, (int) config('services.connect.refresh_token_days', 30)).'D'));
    }

    private function registerRateLimits(): void
    {
        // Before authentication, per address: guessing keys or tokens is slow.
        RateLimiter::for('connect-ip', fn (Request $request): Limit => Limit::perMinute((int) config('services.connect.rate_limit_per_ip', 600))->by('connect-ip:'.$request->ip()));
        RateLimiter::for('connect-token', fn (Request $request): Limit => Limit::perMinute((int) config('services.connect.rate_limit_token_endpoint', 30))->by('connect-token:'.$request->ip()));
    }

    private function registerRoutes(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('connect.scope', RequireScope::class);
        $router->aliasMiddleware('connect.idempotent', RequireIdempotencyKey::class);

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.ConnectModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // The consent screen: signed in, but not bound to the current company or the module, since the company is chosen there.
        Route::get('/anslut', AuthorizeController::class)
            ->middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified'])
            ->name('connect.authorize');

        // Called by apps, not people: no session, no cookies, no CSRF.
        Route::post('/oauth/token', TokenController::class)->middleware('throttle:connect-token')->name('connect.token');
        Route::post('/oauth/revoke', RevokeController::class)->middleware('throttle:connect-token')->name('connect.revoke');
        Route::get('/.well-known/oauth-authorization-server', MetadataController::class)->name('connect.metadata');

        Route::prefix('api/v1')
            ->name('connect.api.')
            ->middleware([ConnectJson::class, 'throttle:connect-ip', AuthenticateConnect::class, ConnectRateLimit::class, AuditApiWrites::class, SubstituteBindings::class])
            ->group(__DIR__.'/routes/api.php');
    }
}
