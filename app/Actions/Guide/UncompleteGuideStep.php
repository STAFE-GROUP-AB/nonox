<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Models\Company;
use App\Models\CompanyGuideStep;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Opens a done step of the start plan again (ADR-051). Needs
 * company:update; a step that is not done stays as it was.
 */
class UncompleteGuideStep
{
    public function __invoke(User $actor, Company $company, string $stepKey): void
    {
        CompleteGuideStep::authorize($actor, $company, $stepKey);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($actor, $company, $stepKey): void {
            $step = CompanyGuideStep::query()->where('step_key', $stepKey)->lockForUpdate()->first();

            if (! $step instanceof CompanyGuideStep) {
                return;
            }

            $step->delete();
            Audit::record('guide.step_reopened', null, ['step_key' => $stepKey], $company, $actor);
        }));
    }
}
