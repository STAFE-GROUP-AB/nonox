<?php

declare(strict_types=1);

namespace App\Modules\Portal;

use App\Events\CustomerAnonymised;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\ModuleRegistry;
use App\Modules\Portal\Console\PrunePortalCommand;
use App\Modules\Portal\Http\Middleware\PortalHeaders;
use App\Modules\Portal\Http\Middleware\UsePortalSession;
use App\Modules\Portal\Livewire\CustomerPortalPanel;
use App\Modules\Portal\Livewire\InvoicePortalPanel;
use App\Modules\Portal\Livewire\PortalAdmin;
use App\Modules\Portal\Livewire\PortalQuestionsPage;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalRetention;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Kundportal (ADR-075): the module, the portal's session cookie
 * switch (a global middleware ahead of the web group's StartSession), its
 * pages under /kundportal with their own headers and rate limits, the
 * company's pages under /kunder/portal, the Livewire panels, and the
 * retention command.
 */
class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new PortalModule);
        $this->app->scoped(PortalContext::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'portal');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PrunePortalCommand::class]);
        }

        $this->app->make(Kernel::class)->prependMiddleware(UsePortalSession::class);

        Livewire::component(CustomerPortalPanel::NAME, CustomerPortalPanel::class);
        Livewire::component(InvoicePortalPanel::NAME, InvoicePortalPanel::class);
        Livewire::component(PortalAdmin::NAME, PortalAdmin::class);
        Livewire::component(PortalQuestionsPage::NAME, PortalQuestionsPage::class);

        // A customer anonymised under GDPR loses its portal logins at once.
        Event::listen(CustomerAnonymised::class, [PortalRetention::class, 'customerAnonymised']);

        $byIdentity = fn (Request $request): string => (string) (Auth::guard('portal')->id() ?? $request->ip());
        RateLimiter::for('portal-sign-in', fn (Request $request): Limit => Limit::perMinute(20)->by('portal-sign-in:'.$request->ip()));
        RateLimiter::for('portal-invitation', fn (Request $request): Limit => Limit::perMinute(30)->by('portal-invitation:'.$request->ip()));
        RateLimiter::for('portal-pages', fn (Request $request): Limit => Limit::perMinute(120)->by('portal-pages:'.$byIdentity($request)));
        RateLimiter::for('portal-downloads', fn (Request $request): Limit => Limit::perMinute(30)->by('portal-downloads:'.$byIdentity($request)));
        RateLimiter::for('portal-messages', fn (Request $request): array => [
            Limit::perMinute(10)->by('portal-messages-minute:'.$byIdentity($request)),
            Limit::perDay(100)->by('portal-messages-day:'.$byIdentity($request)),
        ]);

        Route::middleware(['web', PortalHeaders::class])->group(__DIR__.'/routes/portal.php');
        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.PortalModule::KEY])
            ->group(__DIR__.'/routes/company.php');
    }
}
