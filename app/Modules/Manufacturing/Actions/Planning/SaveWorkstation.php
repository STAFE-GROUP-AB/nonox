<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a workstation (ADR-071 §13.2): its name, an optional
 * short code, and the operations it serves. An operation served by a
 * workstation needs one of them in the plan. Archived, never deleted.
 */
class SaveWorkstation
{
    use GuardsManufacturing;

    /**
     * @param  array<string, mixed>  $input  name, code, operation_ids
     */
    public function __invoke(User $user, Company $company, array $input, ?Workstation $workstation = null): Workstation
    {
        $this->guard($user, $company, 'manage');

        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 100);
        $code = mb_substr(trim((string) ($input['code'] ?? '')), 0, 20);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Ge arbetsstationen ett namn, till exempel Lackbox eller Kapsåg.')]);
        }

        return CurrentCompany::run($company, fn (): Workstation => DB::transaction(function () use ($user, $company, $input, $workstation, $name, $code): Workstation {
            $taken = Workstation::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($workstation !== null, fn ($query) => $query->whereKeyNot($workstation?->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['name' => __('Det finns redan en arbetsstation med det namnet.')]);
            }

            $operationIds = Operation::query()->whereIn('id', array_map('intval', (array) ($input['operation_ids'] ?? [])))->pluck('id')->all();
            $isNew = $workstation === null;
            $workstation = $isNew ? new Workstation : Workstation::query()->lockForUpdate()->findOrFail($workstation->id);
            $workstation->fill(['name' => $name, 'code' => $code === '' ? null : $code])->save();
            $workstation->operations()->syncWithPivotValues($operationIds, ['company_id' => $company->id]);

            Audit::record('manufacturing.workstation_saved', $workstation, ['created' => $isNew, 'operation_ids' => $operationIds], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Resources);

            return $workstation;
        }));
    }

    public function archive(User $user, Company $company, Workstation $workstation, bool $archived): Workstation
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, function () use ($user, $company, $workstation, $archived): Workstation {
            $workstation = Workstation::query()->findOrFail($workstation->id);
            $workstation->forceFill(['archived_at' => $archived ? now() : null])->save();

            Audit::record($archived ? 'manufacturing.workstation_archived' : 'manufacturing.workstation_restored', $workstation, [], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Resources);

            return $workstation;
        });
    }
}
