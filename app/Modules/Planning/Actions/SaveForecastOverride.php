<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\ReadsDemandInput;
use App\Modules\Planning\Models\ForecastOverride;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A person's own sales figure for a day, replacing the forecast for an
 * area or the whole company, with a reason (ADR-037, gap 1). One per scope
 * and day: saving again replaces it. The reason is free text and never goes
 * into the audit log.
 */
final class SaveForecastOverride
{
    use GuardsPlanning;
    use ReadsDemandInput;

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input): ForecastOverride
    {
        $this->guard($user, $company);

        $data = [
            'area_id' => $input['area_id'] ?? null,
            'date' => $input['date'] ?? null,
            'amount_ore' => self::ore($input['amount'] ?? null),
            'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : null,
        ];

        $validated = Validator::make($data, [
            'area_id' => $this->areaRule($company),
            'date' => ['required', 'date_format:Y-m-d'],
            'amount_ore' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'amount_ore.required' => __('Skriv försäljningen i kronor, till exempel 45 000.'),
            'reason.required' => __('Skriv varför prognosen ändras, till exempel en konsert i närheten.'),
        ], [
            'date' => __('Datum'),
            'amount_ore' => __('Försäljning'),
            'reason' => __('Anledning'),
        ])->validate();

        $areaId = $validated['area_id'] === null ? null : (int) $validated['area_id'];

        return CurrentCompany::run($company, fn (): ForecastOverride => DB::transaction(function () use ($user, $company, $validated, $areaId): ForecastOverride {
            $override = ForecastOverride::query()
                ->when($areaId === null, fn ($query) => $query->whereNull('area_id'), fn ($query) => $query->where('area_id', $areaId))
                ->whereDate('date', $validated['date'])
                ->lockForUpdate()
                ->first();
            $created = $override === null;
            $override ??= new ForecastOverride(['area_id' => $areaId, 'date' => $validated['date']]);
            $override->fill(['amount_ore' => (int) $validated['amount_ore'], 'reason' => (string) $validated['reason'], 'created_by' => $user->id])->save();

            Audit::record($created ? 'planning.forecast_override_created' : 'planning.forecast_override_updated', $override, ['area_id' => $areaId, 'fields' => ['amount_ore', 'reason']], $company, $user);

            return $override;
        }));
    }

    public function delete(User $user, Company $company, ForecastOverride $override): void
    {
        $this->guard($user, $company);
        abort_unless($override->company_id === $company->id, 404);

        DB::transaction(function () use ($user, $company, $override): void {
            Audit::record('planning.forecast_override_deleted', $override, ['area_id' => $override->area_id], $company, $user);
            $override->delete();
        });
    }
}
