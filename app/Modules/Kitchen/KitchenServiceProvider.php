<?php

declare(strict_types=1);

namespace App\Modules\Kitchen;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Inventory\Scanner\ScannerExtensions;
use App\Modules\Kitchen\Console\ConsumeSalesCommand;
use App\Modules\Kitchen\Livewire\KitchenSettingsForm;
use App\Modules\Kitchen\Livewire\MenuPage;
use App\Modules\Kitchen\Livewire\RecipePage;
use App\Modules\Kitchen\Livewire\RecipesPage;
use App\Modules\Kitchen\Livewire\StaffMealsPage;
use App\Modules\Kitchen\Livewire\VariancePage;
use App\Modules\Kitchen\Livewire\WastePage;
use App\Modules\Kitchen\Models\ConsumptionRun;
use App\Modules\Kitchen\Models\ConsumptionWait;
use App\Modules\Kitchen\Models\MealPayrollProposal;
use App\Modules\Kitchen\Models\SaleConsumption;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Models\WasteMeasurement;
use App\Modules\Kitchen\Policies\KitchenPolicy;
use App\Modules\Kitchen\Scanner\KitchenScannerExtension;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Kök (ADR-073). Its pages carry module:kitchen and answer 404
 * while it is off; the scanner gets the Svinn tile through Lager's scanner
 * extensions.
 */
class KitchenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new KitchenModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'kitchen');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([WasteEntry::class, WasteMeasurement::class, StaffMeal::class, MealPayrollProposal::class, ConsumptionRun::class, SaleConsumption::class, ConsumptionWait::class] as $model) {
            Gate::policy($model, KitchenPolicy::class);
        }

        Livewire::component(KitchenSettingsForm::NAME, KitchenSettingsForm::class);
        Livewire::component('kitchen.recipes', RecipesPage::class);
        Livewire::component('kitchen.recipe', RecipePage::class);
        Livewire::component('kitchen.menu', MenuPage::class);
        Livewire::component('kitchen.waste', WastePage::class);
        Livewire::component('kitchen.meals', StaffMealsPage::class);
        Livewire::component('kitchen.variance', VariancePage::class);

        $this->app->make(ScannerExtensions::class)->register(new KitchenScannerExtension);

        if ($this->app->runningInConsole()) {
            $this->commands([ConsumeSalesCommand::class]);
        }

        // After the till's nightly reconciliation at 03:40 (ADR-042, ADR-073 §5).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('kitchen:consume')->dailyAt('04:10')->withoutOverlapping();
        });

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.KitchenModule::KEY])
            ->group(__DIR__.'/routes/web.php');
    }
}
