<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Jobs;

use App\Models\Company;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recomputes one company's production plan (ADR-071 §13.4), with the
 * company given explicitly (AGENTS.md §6.0). Dispatched a few seconds late
 * to absorb bursts, and unique per company until it starts: a change
 * written while it runs queues a new one, which reads everything committed
 * by then. The trigger of the first dispatch is the one recorded.
 */
class RecomputeProductionPlan implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public const int DELAY_SECONDS = 5;

    public int $tries = 3;

    /** Seconds the uniqueness lock is held at most. */
    public int $uniqueFor = 300;

    public function __construct(public int $companyId, public string $trigger) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(ProductionPlanner $planner, ModuleManager $modules): void
    {
        CurrentCompany::set($this->companyId);

        if (! $modules->isEnabled(ManufacturingModule::KEY, $this->companyId)) {
            return;
        }

        $company = Company::query()->find($this->companyId);

        if (! $company instanceof Company || $company->isArchived()) {
            return;
        }

        $planner->run($company, PlanTrigger::tryFrom($this->trigger) ?? PlanTrigger::Manual);
    }
}
