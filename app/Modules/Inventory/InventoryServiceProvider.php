<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Modules\Inventory\Console\VerifyStockCommand;
use App\Modules\Inventory\Http\ScannerController;
use App\Modules\Inventory\Livewire\ArticlesPage;
use App\Modules\Inventory\Livewire\CountsPage;
use App\Modules\Inventory\Livewire\InventorySettingsForm;
use App\Modules\Inventory\Livewire\LabelPrintPanel;
use App\Modules\Inventory\Livewire\LabelStudioPage;
use App\Modules\Inventory\Livewire\LocationsPage;
use App\Modules\Inventory\Livewire\OverviewPage;
use App\Modules\Inventory\Livewire\PickListsPage;
use App\Modules\Inventory\Livewire\ReceiptsPage;
use App\Modules\Inventory\Livewire\ReportsPage;
use App\Modules\Inventory\Livewire\UnitConversionsPanel;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\ScanOperation;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Policies\InventoryPolicy;
use App\Modules\Inventory\Scanner\ScannerExtensions;
use App\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Lager (ADR-039). Its pages carry module:inventory and answer 404
 * while it is off.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new InventoryModule);
        $this->app->singleton(ScannerExtensions::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'inventory');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([StockMovement::class, StockLocation::class, StockLevel::class, StockReceipt::class, PickList::class, StockCount::class, StockValuation::class, ArticleImage::class, LabelTemplate::class, ScanOperation::class, BillOfMaterials::class, BomRow::class, ArticleUnitConversion::class] as $model) {
            Gate::policy($model, InventoryPolicy::class);
        }

        Livewire::component(InventorySettingsForm::NAME, InventorySettingsForm::class);
        Livewire::component('inventory.overview', OverviewPage::class);
        Livewire::component('inventory.articles', ArticlesPage::class);
        Livewire::component('inventory.locations', LocationsPage::class);
        Livewire::component('inventory.receipts', ReceiptsPage::class);
        Livewire::component('inventory.pick-lists', PickListsPage::class);
        Livewire::component('inventory.counts', CountsPage::class);
        Livewire::component('inventory.reports', ReportsPage::class);
        Livewire::component(LabelStudioPage::NAME, LabelStudioPage::class);
        Livewire::component(LabelPrintPanel::NAME, LabelPrintPanel::class);
        Livewire::component(UnitConversionsPanel::NAME, UnitConversionsPanel::class);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyStockCommand::class]);
        }

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.InventoryModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // The scanning app sends its queue in batches of at most 100 operations.
        RateLimiter::for('inventory-scanner', fn (Request $request): Limit => Limit::perMinute(120)->by('scanner:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The scanner's service worker holds no data and must load even when the session has ended (ADR-040).
        Route::middleware('web')->get(ScannerController::SCOPE.'/serviceworker', [ScannerController::class, 'worker'])->name('inventory.scanner.worker');
    }
}
