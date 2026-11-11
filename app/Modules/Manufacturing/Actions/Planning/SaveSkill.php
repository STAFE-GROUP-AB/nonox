<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\ProductionSkill;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;

/** Creates, renames or removes a skill in the workshop (ADR-071 §13.2). Removing it frees the operations that needed it. */
class SaveSkill
{
    use GuardsManufacturing;

    public function __invoke(User $user, Company $company, string $name, ?ProductionSkill $skill = null): ProductionSkill
    {
        $this->guard($user, $company, 'manage');

        $name = mb_substr(trim($name), 0, 100);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Ge kompetensen ett namn, till exempel Lackering.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $name, $skill): ProductionSkill {
            $taken = ProductionSkill::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($skill !== null, fn ($query) => $query->whereKeyNot($skill?->id))->exists();

            if ($taken) {
                throw ValidationException::withMessages(['name' => __('Det finns redan en kompetens med det namnet.')]);
            }

            $skill = $skill === null ? new ProductionSkill : ProductionSkill::query()->findOrFail($skill->id);
            $skill->fill(['name' => $name])->save();

            Audit::record('manufacturing.skill_saved', $skill, [], $company, $user);

            return $skill;
        });
    }

    public function delete(User $user, Company $company, ProductionSkill $skill): void
    {
        $this->guard($user, $company, 'manage');

        CurrentCompany::run($company, function () use ($user, $company, $skill): void {
            $skill = ProductionSkill::query()->findOrFail($skill->id);
            Audit::record('manufacturing.skill_deleted', $skill, [], $company, $user);
            $skill->delete();
            PlanTriggers::recompute($company->id, PlanTrigger::Resources);
        });
    }
}
