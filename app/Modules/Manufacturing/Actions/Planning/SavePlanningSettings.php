<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Modules\ModuleManager;
use Illuminate\Validation\ValidationException;

/**
 * The planning calendar's settings (ADR-071 §13): the company's working
 * hours, the horizon, the delivery buffer, the frozen window and the default
 * lead time (manufacturing:manage). The module manager audits the change.
 */
class SavePlanningSettings
{
    use GuardsManufacturing;

    /**
     * @param  array<string, mixed>  $input  working_days, day_start, day_end, break_start, break_minutes, horizon_weeks, buffer_days, frozen_days, lead_time_days
     */
    public function __invoke(User $user, Company $company, array $input): void
    {
        $this->guard($user, $company, 'manage');

        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($input['working_days'] ?? [])), fn (int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        $start = Clock::minuteOfDay(trim((string) ($input['day_start'] ?? '')));
        $end = Clock::minuteOfDay(trim((string) ($input['day_end'] ?? '')));
        $breakText = trim((string) ($input['break_start'] ?? ''));
        $break = $breakText === '' ? null : Clock::minuteOfDay($breakText);

        if ($days === []) {
            throw ValidationException::withMessages(['working_days' => __('Välj minst en arbetsdag.')]);
        }

        if ($start === null || $end === null || $end <= $start || ($breakText !== '' && $break === null)) {
            throw ValidationException::withMessages(['day_start' => __('Skriv tiderna som 07:00 och 16:00, med sluttiden efter starttiden.')]);
        }

        $number = function (string $key, int $min, int $max, string $message) use ($input): int {
            $value = trim((string) ($input[$key] ?? ''));

            if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
                throw ValidationException::withMessages([$key => $message]);
            }

            return (int) $value;
        };

        $settings = [
            'plan_working_days' => $days,
            'plan_day_start' => Clock::timeOfDay($start),
            'plan_day_end' => Clock::timeOfDay($end),
            'plan_break_start' => $break === null ? null : Clock::timeOfDay($break),
            'plan_break_minutes' => $break === null ? 0 : $number('break_minutes', 0, 240, __('Rasten ska vara mellan 0 och 240 minuter.')),
            'plan_horizon_weeks' => $number('horizon_weeks', 1, PlanningSettings::MAX_HORIZON_WEEKS, __('Horisonten ska vara mellan 1 och 52 veckor.')),
            'plan_delivery_buffer_days' => $number('buffer_days', 0, 60, __('Bufferten ska vara mellan 0 och 60 arbetsdagar.')),
            'plan_frozen_days' => $number('frozen_days', 0, 30, __('Det frysta fönstret ska vara mellan 0 och 30 arbetsdagar.')),
            'plan_lead_time_days' => $number('lead_time_days', 0, 365, __('Ledtiden ska vara mellan 0 och 365 dagar.')),
        ];

        app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $company, $user, $settings);
        PlanTriggers::recompute($company->id, PlanTrigger::Resources);
    }
}
