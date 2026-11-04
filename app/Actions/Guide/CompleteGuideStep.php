<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Models\Company;
use App\Models\CompanyGuideStep;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Guide\Guide;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a step of the start plan as done for the company (ADR-051). Needs
 * company:update; a step that is already done stays as it was.
 */
class CompleteGuideStep
{
    public function __invoke(User $actor, Company $company, string $stepKey): CompanyGuideStep
    {
        self::authorize($actor, $company, $stepKey);

        return CurrentCompany::run($company, fn (): CompanyGuideStep => DB::transaction(function () use ($actor, $company, $stepKey): CompanyGuideStep {
            $existing = CompanyGuideStep::query()->where('step_key', $stepKey)->lockForUpdate()->first();

            if ($existing instanceof CompanyGuideStep) {
                return $existing;
            }

            $step = CompanyGuideStep::query()->create(['step_key' => $stepKey, 'completed_at' => now(), 'completed_by' => $actor->id]);
            Audit::record('guide.step_completed', $step, ['step_key' => $stepKey], $company, $actor);

            return $step;
        }));
    }

    /**
     * Only members who may change the company tick its steps, and only
     * steps of the start plan that apply to it.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function authorize(User $actor, Company $company, string $stepKey): void
    {
        if (! $actor->hasTeamPermission($company, 'company:update')) {
            throw new AuthorizationException;
        }

        $applies = array_filter(Guide::forCompany($company), fn ($step): bool => $step->key === $stepKey);

        if ($applies === []) {
            throw ValidationException::withMessages(['step' => __('Steget finns inte i företagets startplan.')]);
        }
    }
}
