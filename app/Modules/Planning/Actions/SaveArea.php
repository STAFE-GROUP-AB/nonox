<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Support\CostCenterInput;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates or changes an area (avdelning): its name, colour, order and,
 * optionally, the staff-ledger location it is at and the cost centre its
 * new shifts are on (ADR-037, gap 7; by code, an inactive one is refused).
 * Shifts already planned keep their cost centre. Audited. Runs in the
 * given company, and another company's area answers 404.
 */
final class SaveArea
{
    use GuardsPlanning;

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?Area $area = null): Area
    {
        $this->guard($user, $company);
        abort_if($area !== null && $area->company_id !== $company->id, 404);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'colour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'location_id' => ['nullable', 'integer', Rule::exists('staff_ledger_locations', 'id')->where('company_id', $company->id)],
            'sort' => ['nullable', 'integer', 'between:0,999'],
            'cost_center' => ['nullable', 'string', 'max:20'],
        ], [
            'colour.regex' => __('Välj en färg.'),
        ], [
            'name' => __('Namn'),
            'colour' => __('Färg'),
            'location_id' => __('Lokal'),
            'cost_center' => __('Kostnadsställe'),
        ])->validate();

        $validated['colour'] = strtolower((string) $validated['colour']);
        $validated['sort'] = (int) ($validated['sort'] ?? 0);

        // Left out of the input, the area keeps its cost centre.
        if (array_key_exists('cost_center', $input)) {
            $validated['cost_center_id'] = CostCenterInput::resolve($company, $validated['cost_center'] ?? null, $area?->cost_center_id, 'cost_center');
        }

        unset($validated['cost_center']);

        return CurrentCompany::run($company, fn (): Area => DB::transaction(function () use ($user, $company, $validated, $area): Area {
            $before = $area?->only(array_keys($validated)) ?? [];
            $area ??= new Area;
            $area->fill($validated)->save();

            Audit::record($before === [] ? 'planning.area_created' : 'planning.area_updated', $area, Audit::changes($before, $area->only(array_keys($validated))), $company, $user);

            return $area;
        }));
    }

    /** Archives an area, or restores it. Its shifts keep it. */
    public function archive(User $user, Company $company, Area $area): Area
    {
        $this->guard($user, $company);
        abort_unless($area->company_id === $company->id, 404);

        return CurrentCompany::run($company, function () use ($user, $company, $area): Area {
            $archived = $area->archived_at === null;
            $area->forceFill(['archived_at' => $archived ? CarbonImmutable::now() : null])->save();
            Audit::record($archived ? 'planning.area_archived' : 'planning.area_restored', $area, [], $company, $user);

            return $area;
        });
    }
}
