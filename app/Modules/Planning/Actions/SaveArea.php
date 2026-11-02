<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Area;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates or changes an area (avdelning): its name, colour, order and,
 * optionally, the staff-ledger location it is at. Audited.
 */
final class SaveArea
{
    use GuardsPlanning;

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?Area $area = null): Area
    {
        $this->guard($user, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'colour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'location_id' => ['nullable', 'integer', Rule::exists('staff_ledger_locations', 'id')->where('company_id', $company->id)],
            'sort' => ['nullable', 'integer', 'between:0,999'],
        ], [
            'colour.regex' => __('Välj en färg.'),
        ], [
            'name' => __('Namn'),
            'colour' => __('Färg'),
            'location_id' => __('Lokal'),
        ])->validate();

        $validated['colour'] = strtolower((string) $validated['colour']);
        $validated['sort'] = (int) ($validated['sort'] ?? 0);

        return DB::transaction(function () use ($user, $company, $validated, $area): Area {
            $before = $area?->only(array_keys($validated)) ?? [];
            $area ??= new Area;
            $area->fill($validated)->save();

            Audit::record($before === [] ? 'planning.area_created' : 'planning.area_updated', $area, Audit::changes($before, $area->only(array_keys($validated))), $company, $user);

            return $area;
        });
    }

    /** Archives an area, or restores it. Its shifts keep it. */
    public function archive(User $user, Company $company, Area $area): Area
    {
        $this->guard($user, $company);
        $archived = $area->archived_at === null;
        $area->forceFill(['archived_at' => $archived ? CarbonImmutable::now() : null])->save();
        Audit::record($archived ? 'planning.area_archived' : 'planning.area_restored', $area, [], $company, $user);

        return $area;
    }
}
