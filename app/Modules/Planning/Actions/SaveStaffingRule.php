<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\ReadsDemandInput;
use App\Modules\Planning\Models\StaffingRule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates, changes or removes a staffing target (ADR-037, gap 1): for an
 * area or the whole company, a weekday or every day, a time window and a
 * valid-from date, the sales one staffed hour should carry and/or a
 * minimum staff. Needs planning:manage. Audited with ids and field names.
 */
final class SaveStaffingRule
{
    use GuardsPlanning;
    use ReadsDemandInput;

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?StaffingRule $rule = null): StaffingRule
    {
        $this->guard($user, $company);
        abort_if($rule !== null && $rule->company_id !== $company->id, 404);

        $data = [
            'area_id' => $input['area_id'] ?? null,
            'weekday' => $input['weekday'] ?? null,
            'start_minute' => self::minute($input['start'] ?? null),
            'end_minute' => self::minute($input['end'] ?? null),
            'sales_per_staff_hour_ore' => self::ore($input['sales_per_staff_hour'] ?? null),
            'min_staff' => filled($input['min_staff'] ?? null) ? $input['min_staff'] : null,
            'valid_from' => $input['valid_from'] ?? null,
        ];

        $validated = Validator::make([...$data, 'sales_typed' => $input['sales_per_staff_hour'] ?? null], [
            'area_id' => $this->areaRule($company),
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'start_minute' => ['required', 'integer', 'between:0,1439'],
            'end_minute' => ['required', 'integer', 'between:1,1440', 'gt:start_minute'],
            'sales_per_staff_hour_ore' => ['nullable', 'integer', 'min:100', 'max:100000000'],
            'min_staff' => ['nullable', 'integer', 'between:1,500'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ], [
            'start_minute.required' => __('Ange när tiden börjar, till exempel 11:00.'),
            'end_minute.required' => __('Ange när tiden slutar, till exempel 14:00 eller 24:00.'),
            'end_minute.gt' => __('Tiden ska sluta efter att den börjar. Dela upp tider över midnatt i två mål.'),
            'sales_per_staff_hour_ore.min' => __('Försäljningen per bemannad timme ska vara minst 1 kr.'),
        ], [
            'area_id' => __('Avdelning'),
            'weekday' => __('Veckodag'),
            'sales_per_staff_hour_ore' => __('Försäljning per bemannad timme'),
            'min_staff' => __('Minsta bemanning'),
            'valid_from' => __('Gäller från'),
        ])->after(function ($validator) use ($data, $input): void {
            if (filled($input['sales_per_staff_hour'] ?? null) && $data['sales_per_staff_hour_ore'] === null) {
                $validator->errors()->add('sales_per_staff_hour_ore', __('Skriv försäljningen per bemannad timme i kronor, till exempel 1 200.'));
            }

            if ($data['sales_per_staff_hour_ore'] === null && ! filled($data['min_staff'])) {
                $validator->errors()->add('sales_per_staff_hour_ore', __('Ange försäljning per bemannad timme, minsta bemanning eller båda.'));
            }
        })->validate();

        unset($validated['sales_typed']);
        $validated['area_id'] = $validated['area_id'] === null ? null : (int) $validated['area_id'];
        $validated['weekday'] = $validated['weekday'] === null ? null : (int) $validated['weekday'];
        $validated['min_staff'] = $validated['min_staff'] === null ? null : (int) $validated['min_staff'];

        return CurrentCompany::run($company, fn (): StaffingRule => DB::transaction(function () use ($user, $company, $validated, $rule): StaffingRule {
            $before = $rule?->only(array_keys($validated)) ?? [];
            $rule ??= new StaffingRule(['created_by' => $user->id]);
            $rule->fill($validated)->save();
            $changes = Audit::changes($before, $rule->only(array_keys($validated)));

            Audit::record($before === [] ? 'planning.staffing_rule_created' : 'planning.staffing_rule_updated', $rule, ['area_id' => $rule->area_id, 'fields' => array_keys($changes['after'])], $company, $user);

            return $rule;
        }));
    }

    public function delete(User $user, Company $company, StaffingRule $rule): void
    {
        $this->guard($user, $company);
        abort_unless($rule->company_id === $company->id, 404);

        DB::transaction(function () use ($user, $company, $rule): void {
            Audit::record('planning.staffing_rule_deleted', $rule, ['area_id' => $rule->area_id], $company, $user);
            $rule->delete();
        });
    }
}
