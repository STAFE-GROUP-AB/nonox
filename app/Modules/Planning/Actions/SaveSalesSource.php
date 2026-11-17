<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\ReadsDemandInput;
use App\Modules\Planning\Domain\Demand\Forecaster;
use App\Modules\Planning\Models\SalesSource;
use App\Modules\Planning\PlanningModule;
use App\Modules\Pos\Models\PosConnection;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Which till gives the shape of the day for which scope (ADR-037 gap 1,
 * ADR-042), and how many weeks the forecast averages. A till is used for an
 * area, for the whole company, or not at all.
 */
final class SaveSalesSource
{
    use GuardsPlanning;
    use ReadsDemandInput;

    public const string OFF = 'off';

    public const string COMPANY = 'company';

    /** @param  string  $target  OFF, COMPANY or an area id */
    public function __invoke(User $user, Company $company, int $connectionId, string $target): ?SalesSource
    {
        $this->guard($user, $company);

        // findOrFail in the company: another company's till answers 404.
        $connection = CurrentCompany::run($company, fn (): PosConnection => PosConnection::query()->findOrFail($connectionId));
        $areaId = in_array($target, [self::OFF, self::COMPANY], true) ? null : $target;

        Validator::make(['area_id' => $areaId, 'target' => $target], [
            'area_id' => $this->areaRule($company),
            'target' => ['required', 'string'],
        ], [], ['area_id' => __('Avdelning')])->validate();

        return CurrentCompany::run($company, fn (): ?SalesSource => DB::transaction(function () use ($user, $company, $connection, $target, $areaId): ?SalesSource {
            $source = SalesSource::query()->where('pos_connection_id', $connection->id)->lockForUpdate()->first();

            if ($target === self::OFF) {
                if ($source !== null) {
                    Audit::record('planning.sales_source_removed', $source, ['pos_connection_id' => $connection->id], $company, $user);
                    $source->delete();
                }

                return null;
            }

            $source ??= new SalesSource(['pos_connection_id' => $connection->id, 'created_by' => $user->id]);
            $source->fill(['area_id' => $areaId === null ? null : (int) $areaId])->save();
            Audit::record('planning.sales_source_saved', $source, ['pos_connection_id' => $connection->id, 'area_id' => $source->area_id], $company, $user);

            return $source;
        }));
    }

    /** The number of weeks the forecast averages; the module settings audit the change. */
    public function weeks(User $user, Company $company, mixed $weeks): int
    {
        $this->guard($user, $company);

        $validated = Validator::make(['forecast_weeks' => $weeks], [
            'forecast_weeks' => ['required', 'integer', 'between:'.Forecaster::MIN_WEEKS.','.Forecaster::MAX_WEEKS],
        ], [], ['forecast_weeks' => __('Antal veckor')])->validate();

        app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $company, $user, ['forecast_weeks' => (int) $validated['forecast_weeks']]);

        return (int) $validated['forecast_weeks'];
    }
}
