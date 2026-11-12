<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Console;

use App\Models\Company;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Models\ProductionScenario;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Throwable;

/**
 * The nightly plan (ADR-071 §13.4) for every company with Tillverkning,
 * each inside its own company. Also removes expired scenarios (§13.6) and,
 * after 24 months, the employee from deviations and from the slots of the
 * plans they keep, keeping the rest for delivery accuracy (§13.7).
 */
class PlanProductionCommand extends Command
{
    protected $signature = 'manufacturing:plan {--company= : Only this company id}';

    protected $description = 'Beräknar produktionsplanen för varje företag med Tillverkning';

    public function handle(ModuleManager $modules, ProductionPlanner $planner): int
    {
        $ids = $this->option('company') !== null ? [(int) $this->option('company')] : $modules->companiesWith(ManufacturingModule::KEY);
        $done = 0;

        foreach ($ids as $id) {
            $company = Company::query()->find($id);

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(ManufacturingModule::KEY, $company)) {
                continue;
            }

            $this->info("Planning company `{$id}`...");

            try {
                $planner->run($company, PlanTrigger::Nightly);
                CurrentCompany::run($company, function (): void {
                    ProductionScenario::query()->where('expires_at', '<', now())->delete();
                    ProductionDeviation::query()
                        ->whereNotNull('employee_id')
                        ->where('created_at', '<', now()->subMonths(ProductionDeviation::RETENTION_MONTHS))
                        ->update(['employee_id' => null]);
                    // Runs a deviation or a lock keeps are not pruned; after the same period they lose the person (§13.7).
                    ProductionPlanSlot::query()
                        ->whereNotNull('employee_id')
                        ->whereIn('run_id', ProductionPlanRun::query()->where('created_at', '<', now()->subMonths(ProductionDeviation::RETENTION_MONTHS))->select('id'))
                        ->update(['employee_id' => null]);
                });
                $done++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Company `{$id}` failed: {$exception->getMessage()}");
            }
        }

        $this->comment("Planned {$done} companies.");

        return self::SUCCESS;
    }
}
