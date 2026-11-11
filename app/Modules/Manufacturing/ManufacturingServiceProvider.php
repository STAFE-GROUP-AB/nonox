<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Models\SalesDocument;
use App\Modules\Inventory\Purchasing\PurchaseDemandSources;
use App\Modules\Inventory\Support\StockValuationAdditions;
use App\Modules\Manufacturing\Actions\CopyRowStructures;
use App\Modules\Manufacturing\Livewire\BillOfMaterialsPage;
use App\Modules\Manufacturing\Livewire\BillsOfMaterialsPage;
use App\Modules\Manufacturing\Livewire\ManufacturingSettingsForm;
use App\Modules\Manufacturing\Livewire\OperationsPage;
use App\Modules\Manufacturing\Livewire\Production\CreateProductionOrderPage;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrderPage;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrdersPage;
use App\Modules\Manufacturing\Livewire\RowStructuresPanel;
use App\Modules\Manufacturing\Livewire\Workshop\AnalysisPage;
use App\Modules\Manufacturing\Livewire\Workshop\TerminalAdminPage;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Policies\ManufacturingPolicy;
use App\Modules\Manufacturing\Production\ProductionOrderDemand;
use App\Modules\Manufacturing\Production\ProductionOrderSupply;
use App\Modules\Manufacturing\Production\WorkInProgressValuation;
use App\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers Tillverkning (ADR-071). Its pages carry module:manufacturing
 * and answer 404 while it is off; the costing panel on quotes and orders
 * is shown only while it is on. A quote's row copies follow it into the
 * order made from it. Production orders feed the purchase proposal and the
 * year-end stock valuation.
 */
class ManufacturingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new ManufacturingModule);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'manufacturing');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        foreach ([Operation::class, RowStructure::class, RowStructureLine::class, ProductionOrder::class, WorkshopDevice::class, WorkshopPerson::class, WorkshopTimeEntry::class] as $model) {
            Gate::policy($model, ManufacturingPolicy::class);
        }

        Livewire::component(ManufacturingSettingsForm::NAME, ManufacturingSettingsForm::class);
        Livewire::component('manufacturing.boms', BillsOfMaterialsPage::class);
        Livewire::component('manufacturing.bom', BillOfMaterialsPage::class);
        Livewire::component('manufacturing.operations', OperationsPage::class);
        Livewire::component('manufacturing.row-structures', RowStructuresPanel::class);
        Livewire::component('manufacturing.production-orders', ProductionOrdersPage::class);
        Livewire::component('manufacturing.production-order', ProductionOrderPage::class);
        Livewire::component('manufacturing.production-order-create', CreateProductionOrderPage::class);
        Livewire::component('manufacturing.terminal-admin', TerminalAdminPage::class);
        Livewire::component('manufacturing.analysis', AnalysisPage::class);

        RateLimiter::for('workshop-terminal', fn (Request $request): Limit => Limit::perMinute(120)->by('workshop:'.$request->ip()));
        RateLimiter::for('workshop-pairing', fn (Request $request): Limit => Limit::perMinute(10)->by('workshop-pair:'.$request->ip()));
        RateLimiter::for('workshop-code', fn (Request $request): Limit => Limit::perMinute(30)->by('workshop-code:'.$request->ip()));

        // Production orders reserve components and bring finished goods in the purchase proposal, and their
        // work in progress is valued at year end (ADR-071 §5, §8). Registered whether or not the module is on,
        // so data kept while it is off still counts.
        $sources = $this->app->make(PurchaseDemandSources::class);
        $sources->register(new ProductionOrderDemand);
        $sources->registerSupply(new ProductionOrderSupply);
        $this->app->make(StockValuationAdditions::class)->register(new WorkInProgressValuation);

        // ProgressSalesDocument links the new order to its quote last, after the rows are written (ADR-071 §4).
        SalesDocument::saved(function (SalesDocument $document): void {
            if ($document->wasChanged('from_quote_id') && $document->from_quote_id !== null) {
                app(CopyRowStructures::class)($document->fromQuote()->firstOrFail(), $document);
            }
        });

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.ManufacturingModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        // The workshop terminal needs no sign-in: its tablets carry a device token (ADR-071 §7).
        Route::middleware([SecurityHeaders::class, 'throttle:workshop-terminal'])->group(__DIR__.'/routes/terminal.php');
    }
}
