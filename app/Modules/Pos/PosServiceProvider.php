<?php

declare(strict_types=1);

namespace App\Modules\Pos;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\Article;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Modules\Pos\Actions\QueueStockPushes;
use App\Modules\Pos\Console\DailyDraftsCommand;
use App\Modules\Pos\Console\ReconcileSalesCommand;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Http\ZettleWebhookController;
use App\Modules\Pos\Jobs\SyncProductsJob;
use App\Modules\Pos\Livewire\PosPage;
use App\Modules\Pos\Livewire\PosSettingsForm;
use App\Modules\Pos\Models\PosConnection;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Kassakopplingar (ADR-042): the module, its pages under
 * /anslutningar/kassa (404 while the module is off), the webhook from
 * Zettle (a technical path, no session), the listeners that send stock
 * and articles to the tills, and the schedule that fetches sales and
 * proposes the daily vouchers.
 */
class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new PosModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'pos');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileSalesCommand::class, DailyDraftsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('pos:reconcile-sales')->hourlyAt(23)->withoutOverlapping();
            $schedule->command('pos:reconcile-sales', ['--since' => now('Europe/Stockholm')->subDays(7)->toDateString()])->dailyAt('03:40')->withoutOverlapping();
            $schedule->command('pos:daily-drafts')->dailyAt('05:20')->withoutOverlapping();
        });

        Event::listen(StockChanged::class, [QueueStockPushes::class, 'handle']);
        Article::saved(fn (Article $article) => $this->queueProductSync($article));

        Livewire::component(PosPage::NAME, PosPage::class);
        Livewire::component(PosSettingsForm::NAME, PosSettingsForm::class);

        RateLimiter::for('pos-callback', fn (Request $request): Limit => Limit::perMinute(10)->by('pos-callback:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('pos-webhook', fn (Request $request): Limit => Limit::perMinute(600)->by('pos-webhook:'.$request->ip()));

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.PosModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // Called by Zettle, not people: no session, no cookies, no CSRF; the signature instead.
        Route::post('/hooks/kassa/zettle/{token}', ZettleWebhookController::class)
            ->where('token', '[A-Za-z0-9]{48}')
            ->middleware('throttle:pos-webhook')
            ->name('pos.webhook.zettle');
    }

    /** A changed article goes to the tills that take articles, a few seconds later and once per burst of changes. */
    private function queueProductSync(Article $article): void
    {
        $companyId = (int) $article->company_id;

        if (! app(ModuleManager::class)->isEnabled(PosModule::KEY, $companyId)) {
            return;
        }

        $connections = CurrentCompany::run($companyId, fn () => PosConnection::query()->where('status', ConnectionStatus::Active->value)->get());

        foreach ($connections as $connection) {
            if ($connection->pushesProducts() && Cache::add("pos:products-queued:{$connection->id}", true, 15)) {
                SyncProductsJob::dispatch($companyId, $connection->id)->delay(now()->addSeconds(10))->afterCommit();
            }
        }
    }
}
