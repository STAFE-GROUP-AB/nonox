<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing;

use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Purchasing\PurchaseDemandSources;
use App\Modules\Inventory\Support\StockValuationAdditions;
use App\Modules\Manufacturing\Actions\CopyRowStructures;
use App\Modules\Manufacturing\Console\PlanProductionCommand;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Livewire\BillOfMaterialsPage;
use App\Modules\Manufacturing\Livewire\BillsOfMaterialsPage;
use App\Modules\Manufacturing\Livewire\ManufacturingSettingsForm;
use App\Modules\Manufacturing\Livewire\OperationsPage;
use App\Modules\Manufacturing\Livewire\Planning\CalendarPage;
use App\Modules\Manufacturing\Livewire\Planning\DeviationsPage;
use App\Modules\Manufacturing\Livewire\Planning\OrdersPage;
use App\Modules\Manufacturing\Livewire\Planning\ResourcesPage;
use App\Modules\Manufacturing\Livewire\Planning\ScenarioPage;
use App\Modules\Manufacturing\Livewire\Planning\SetupPage;
use App\Modules\Manufacturing\Livewire\Planning\TimelinePage;
use App\Modules\Manufacturing\Livewire\Production\CreateProductionOrderPage;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrderPage;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrdersPage;
use App\Modules\Manufacturing\Livewire\RowStructuresPanel;
use App\Modules\Manufacturing\Livewire\Workshop\AnalysisPage;
use App\Modules\Manufacturing\Livewire\Workshop\TerminalAdminPage;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Modules\Manufacturing\Planning\PlanSnapshot;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Modules\Manufacturing\Policies\ManufacturingPolicy;
use App\Modules\Manufacturing\Production\ProductionOrderDemand;
use App\Modules\Manufacturing\Production\ProductionOrderSupply;
use App\Modules\Manufacturing\Production\WorkInProgressValuation;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Planning\Events\PeriodPublished;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
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

        foreach ([Operation::class, RowStructure::class, RowStructureLine::class, ProductionOrder::class, WorkshopDevice::class, WorkshopPerson::class, WorkshopTimeEntry::class, Workstation::class, ProductionDeviation::class] as $model) {
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
        Livewire::component('manufacturing.planning.timeline', TimelinePage::class);
        Livewire::component('manufacturing.planning.resources', ResourcesPage::class);
        Livewire::component('manufacturing.planning.calendar', CalendarPage::class);
        Livewire::component('manufacturing.planning.orders', OrdersPage::class);
        Livewire::component('manufacturing.planning.deviations', DeviationsPage::class);
        Livewire::component('manufacturing.planning.scenario', ScenarioPage::class);
        Livewire::component('manufacturing.planning.setup', SetupPage::class);

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

        $this->planningHooks();

        if ($this->app->runningInConsole()) {
            $this->commands([PlanProductionCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // The nightly plan (ADR-071 §13.4), after midnight so "today" is the new day.
            $schedule->command('manufacturing:plan')->dailyAt('02:35')->withoutOverlapping();
        });

        Route::middleware(['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class, 'module:'.ManufacturingModule::KEY])
            ->group(__DIR__.'/routes/web.php');

        $archive = ['web', 'auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class];
        Route::middleware($archive)->group(__DIR__.'/routes/archive.php');

        // The workshop terminal needs no sign-in: its tablets carry a device token (ADR-071 §7).
        Route::middleware([SecurityHeaders::class, 'throttle:workshop-terminal'])->group(__DIR__.'/routes/terminal.php');
    }

    /**
     * What starts a new plan and records a deviation (ADR-071 §13.4, §13.5).
     * Each hook does nothing for a company without Tillverkning.
     */
    private function planningHooks(): void
    {
        // Absence: only the employee and the dates reach the plan, never the type (§13.7). Only someone the
        // plan schedules, and only an absence that reaches into the horizon, can move a delivery.
        $concerns = function (Absence $absence): bool {
            $company = Company::query()->find($absence->company_id);

            if (! $company instanceof Company || ! app(ModuleManager::class)->isEnabled(ManufacturingModule::KEY, $company)) {
                return false;
            }

            $today = CarbonImmutable::now(Clock::ZONE)->startOfDay();
            $horizon = $today->addWeeks(PlanningSettings::for($company)->horizonWeeks());

            return $absence->date_to->greaterThanOrEqualTo($today)
                && $absence->date_from->lessThanOrEqualTo($horizon)
                && CurrentCompany::run($company, fn (): bool => app(PlanSnapshot::class)->plans($company, $absence->employee_id));
        };

        Absence::saved(function (Absence $absence) use ($concerns): void {
            $approved = $absence->status === 'APPROVED';
            $relevant = $absence->wasChanged(['status', 'date_from', 'date_to', 'extent_bp', 'hours']) || $absence->wasRecentlyCreated;

            if (! $relevant || (! $approved && $absence->getOriginal('status') !== 'APPROVED') || ! $concerns($absence)) {
                return;
            }

            if (! $approved) {
                PlanTriggers::recompute($absence->company_id, PlanTrigger::Absence);

                return;
            }

            PlanTriggers::deviation($absence->company_id, DeviationType::Absence, null, [
                'employee_id' => $absence->employee_id,
                'starts_on' => $absence->date_from->toDateString(),
                'ends_on' => $absence->date_to->toDateString(),
            ], PlanTrigger::Absence);
        });
        Absence::deleted(fn (Absence $absence) => $absence->status === 'APPROVED' && $concerns($absence) ? PlanTriggers::recompute($absence->company_id, PlanTrigger::Absence) : null);

        Event::listen(PeriodPublished::class, fn (PeriodPublished $event) => PlanTriggers::recompute($event->companyId, PlanTrigger::Shift));

        // A supplier's later date on a component an open production order needs is a delay; anything else just replans.
        PurchaseOrderRow::saved(function (PurchaseOrderRow $row): void {
            if (! $row->wasChanged('expected_on') && ! $row->wasChanged('received_milli') && ! $row->wasRecentlyCreated) {
                return;
            }

            $before = $row->getOriginal('expected_on');
            $later = $row->wasChanged('expected_on') && $before !== null && $row->expected_on !== null && $row->expected_on->greaterThan($before);
            $needed = $later && CurrentCompany::run($row->company_id, fn (): bool => ProductionOrder::query()
                ->whereIn('status', array_map(fn (ProductionOrderStatus $status): string => $status->value, ProductionOrderStatus::open()))
                ->whereHas('components', fn ($query) => $query->where('component_article_id', $row->article_id))
                ->exists());

            if (! $needed) {
                PlanTriggers::recompute($row->company_id, PlanTrigger::Purchase);

                return;
            }

            PlanTriggers::deviation($row->company_id, DeviationType::PurchaseDelay, $row, [
                'starts_on' => $row->expected_on->toDateString(),
                'details' => ['purchase_order_id' => $row->purchase_order_id, 'article_id' => $row->article_id, 'before' => CarbonImmutable::parse((string) $before)->toDateString(), 'after' => $row->expected_on->toDateString()],
            ], PlanTrigger::Purchase);
        });
        PurchaseOrder::saved(fn (PurchaseOrder $order) => $order->wasChanged('status') ? PlanTriggers::recompute($order->company_id, PlanTrigger::Purchase) : null);
        StockReceipt::saved(fn (StockReceipt $receipt) => $receipt->wasChanged('status') ? PlanTriggers::recompute($receipt->company_id, PlanTrigger::Receipt) : null);
        ProductionLabourEntry::created(fn (ProductionLabourEntry $entry) => PlanTriggers::recompute($entry->company_id, PlanTrigger::Terminal));

        ProductionOrder::saved(function (ProductionOrder $order): void {
            if ($order->wasChanged('quantity_milli')) {
                PlanTriggers::deviation($order->company_id, DeviationType::QuantityChange, $order, [
                    'details' => ['production_order_id' => $order->id, 'before_milli' => (int) $order->getOriginal('quantity_milli'), 'after_milli' => $order->quantity_milli],
                ], PlanTrigger::ProductionOrder);

                return;
            }

            if ($order->wasRecentlyCreated || $order->wasChanged(['status', 'planned_start_on', 'due_on', 'sales_document_id', 'plan_priority'])) {
                PlanTriggers::recompute($order->company_id, PlanTrigger::ProductionOrder);
            }
        });

        SalesDocument::saved(function (SalesDocument $document): void {
            if ($document->isQuote() || ! $document->wasChanged(['until_date', 'status'])) {
                return;
            }

            if (CurrentCompany::run($document->company_id, fn (): bool => ProductionOrder::query()->where('sales_document_id', $document->id)->exists())) {
                PlanTriggers::recompute($document->company_id, PlanTrigger::SalesOrder);
            }
        });
    }
}
