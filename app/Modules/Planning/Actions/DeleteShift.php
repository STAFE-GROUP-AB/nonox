<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Notifications\ScheduleNotice;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\ScheduleNotifier;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a shift. One that was never published is deleted; one the
 * employee has been shown is cancelled instead, and the employee is told
 * (when the company notifies on changes). Audited with the shift as it was.
 */
final class DeleteShift
{
    use GuardsPlanning;

    public function __construct(private readonly ScheduleNotifier $notifier) {}

    /** @return bool true when the shift was cancelled rather than deleted */
    public function __invoke(User $user, Company $company, Shift $shift): bool
    {
        $this->guard($user, $company);

        if ($shift->status === Shift::CANCELLED) {
            throw ValidationException::withMessages(['shift' => __('Passet är redan inställt.')]);
        }

        return DB::transaction(function () use ($user, $company, $shift): bool {
            if ($shift->published_at === null) {
                Audit::record('planning.shift_deleted', $shift, ['before' => $shift->snapshot()], $company, $user);
                $shift->delete();

                return false;
            }

            $shown = $shift->published_snapshot ?? $shift->snapshot();
            $shift->forceFill(['status' => Shift::CANCELLED, 'cancelled_at' => CarbonImmutable::now(), 'cancelled_by' => $user->id, 'updated_by' => $user->id])->save();
            Audit::record('planning.shift_cancelled', $shift, ['before' => $shown], $company, $user);

            $employeeId = $shown['employee_id'] ?? null;
            $settings = PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $company));

            if (is_int($employeeId) && PlanningSettings::bool($settings, 'notify_on_change')) {
                $published = (new Shift)->forceFill([
                    'id' => $shift->id,
                    'starts_at' => CarbonImmutable::parse((string) $shown['starts_at']),
                    'ends_at' => CarbonImmutable::parse((string) $shown['ends_at']),
                    'area_id' => $shown['area_id'] ?? null,
                ]);

                $this->notifier->send($company, $user, ScheduleNotice::CANCELLED, ScheduleNotifier::day($published->starts_at), [$employeeId => [$published]]);
            }

            return true;
        });
    }
}
