<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders;

use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SigningHooks;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\ModuleRegistry;
use App\Modules\WorkOrders\Console\ExpireChangeOrdersCommand;
use App\Modules\WorkOrders\Livewire\FieldWorkOrderPage;
use App\Modules\WorkOrders\Livewire\MyWorkOrdersPage;
use App\Modules\WorkOrders\Livewire\OngoingWorkPage;
use App\Modules\WorkOrders\Livewire\SettingsForm;
use App\Modules\WorkOrders\Livewire\WorkOrderEditor;
use App\Modules\WorkOrders\Livewire\WorkOrderPage;
use App\Modules\WorkOrders\Livewire\WorkOrdersPage;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Policies\WorkOrderPolicy;
use App\Modules\WorkOrders\Support\WorkOrdersSigningHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Arbetsorder (ADR-074). Its pages carry module:work_orders and
 * answer 404 while it is off.
 */
class WorkOrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new WorkOrdersModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'work-orders');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireChangeOrdersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('work-orders:expire-change-orders')->everyFifteenMinutes()->withoutOverlapping();
        });

        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);

        // ÄTA are signed at a link through the shared signing domain (ADR-074 §4, ADR-075 §7).
        $this->app->make(SignableTypes::class)->register(ChangeOrder::SIGNING_TYPE, SignableTypes::GENERIC_COLUMN, function (string $key, bool $lock): ?ChangeOrder {
            $query = ChangeOrder::query()->whereKey((int) $key);

            return ($lock ? $query->lockForUpdate() : $query)->first();
        });
        $this->app->make(SigningHooks::class)->add($this->app->make(WorkOrdersSigningHook::class));

        Livewire::component(SettingsForm::NAME, SettingsForm::class);
        Livewire::component('work-orders.index', WorkOrdersPage::class);
        Livewire::component('work-orders.show', WorkOrderPage::class);
        Livewire::component('work-orders.editor', WorkOrderEditor::class);
        Livewire::component('work-orders.mine', MyWorkOrdersPage::class);
        Livewire::component('work-orders.field', FieldWorkOrderPage::class);
        Livewire::component('work-orders.ongoing', OngoingWorkPage::class);

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.WorkOrdersModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
            ->group(__DIR__.'/routes/archive.php');
    }
}
