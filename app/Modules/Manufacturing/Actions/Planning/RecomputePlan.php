<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Planning\ProductionPlanner;

/** "Beräkna nu" (ADR-071 §13.4): a planner computes the plan at once instead of waiting for the queue. */
class RecomputePlan
{
    use GuardsManufacturing;

    public function __construct(private readonly ProductionPlanner $planner) {}

    public function __invoke(User $user, Company $company): ProductionPlanRun
    {
        $this->guard($user, $company, 'plan');

        return $this->planner->run($company, PlanTrigger::Manual);
    }
}
