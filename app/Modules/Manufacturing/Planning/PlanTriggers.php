<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Jobs\RecomputeProductionPlan;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * What starts a new plan (ADR-071 §13.4) and records the deviation that
 * caused it (§13.5). A deviation is written at once with the latest run as
 * its run before; the next run becomes its run after and gives it its
 * impact. Nothing happens for a company without Tillverkning.
 */
final class PlanTriggers
{
    public static function recompute(int $companyId, PlanTrigger $trigger): void
    {
        if (! app(ModuleManager::class)->isEnabled(ManufacturingModule::KEY, $companyId)) {
            return;
        }

        RecomputeProductionPlan::dispatch($companyId, $trigger->value)
            ->delay(now()->addSeconds(RecomputeProductionPlan::DELAY_SECONDS))
            ->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $attributes  employee_id, starts_on, ends_on, details (ids, enums, dates and minutes only)
     */
    public static function deviation(int $companyId, DeviationType $type, ?Model $source, array $attributes, PlanTrigger $trigger): ?ProductionDeviation
    {
        if (! app(ModuleManager::class)->isEnabled(ManufacturingModule::KEY, $companyId)) {
            return null;
        }

        $deviation = CurrentCompany::run($companyId, fn (): ProductionDeviation => ProductionDeviation::create([
            ...$attributes,
            'company_id' => $companyId,
            'type' => $type,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'run_before_id' => ProductionPlanRun::query()->max('id'),
            'created_by' => Auth::id(),
        ]));

        self::recompute($companyId, $trigger);

        return $deviation;
    }
}
