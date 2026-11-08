<?php

declare(strict_types=1);

namespace App\Modules\Boardroom;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Boardroom\Console\VerifyShareRegisterCommand;
use App\Modules\Boardroom\Livewire\ArticlesPage;
use App\Modules\Boardroom\Livewire\EventsPage;
use App\Modules\Boardroom\Livewire\MeetingPage;
use App\Modules\Boardroom\Livewire\MeetingsPage;
use App\Modules\Boardroom\Livewire\RegisterPage;
use App\Modules\Boardroom\Livewire\ShareholdersPage;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Aktiebok och styrelserum (ADR-070). The pages that change
 * anything carry module:boardroom and answer 404 while it is off; reading the
 * register, the meetings and the printed documents stays possible, because
 * the register is kept as long as the company exists (ABL 5 kap. 3 §).
 */
class BoardroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new BoardroomModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'boardroom');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyShareRegisterCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Nightly, after ledger:verify and personalliggare:verify.
            $schedule->command('share-register:verify')->dailyAt('03:45')->withoutOverlapping();
        });

        Livewire::component('boardroom.register', RegisterPage::class);
        Livewire::component('boardroom.events', EventsPage::class);
        Livewire::component('boardroom.shareholders', ShareholdersPage::class);
        Livewire::component('boardroom.articles', ArticlesPage::class);
        Livewire::component('boardroom.meetings', MeetingsPage::class);
        Livewire::component('boardroom.meeting', MeetingPage::class);

        $middleware = ['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class];
        Route::middleware([...$middleware, 'module:'.BoardroomModule::KEY])->group(__DIR__.'/routes/web.php');
        Route::middleware($middleware)->group(__DIR__.'/routes/archive.php');
    }
}
