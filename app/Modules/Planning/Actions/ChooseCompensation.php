<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Models\Attestation;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chooses, per occasion, whether a calculation period's overtime and mertid
 * is paid or taken as comp time (gap G8). The choice is stored on the
 * period's last day (details.week_choice) and used when that day is sent to
 * payroll; once the period is sent it can only change by opening the day
 * again. Audited.
 */
final class ChooseCompensation
{
    use GuardsPlanning;

    public function __invoke(User $user, Company $company, Attestation $attestation, string $choice): Attestation
    {
        $this->guard($user, $company, 'attest');

        if (! in_array($choice, [WeekBasis::PAY, WeekBasis::COMP_TIME], true)) {
            throw ValidationException::withMessages(['choice' => __('Välj utbetalning eller komptid.')]);
        }

        return DB::transaction(function () use ($user, $company, $attestation, $choice): Attestation {
            /** @var Attestation $attestation */
            $attestation = Attestation::query()->whereKey($attestation->id)->lockForUpdate()->firstOrFail();

            if (isset($attestation->details['week'])) {
                throw ValidationException::withMessages(['choice' => __('Periodens övertid är redan skickad. Öppna dagen igen för att ändra valet.')]);
            }

            $before = $attestation->details['week_choice'] ?? null;
            $attestation->forceFill(['details' => [...($attestation->details ?? []), 'week_choice' => $choice], 'updated_by' => $user->id])->save();

            Audit::record('planning.compensation_chosen', $attestation, [
                'employee_id' => $attestation->employee_id,
                'date' => $attestation->date->toDateString(),
                'before' => $before,
                'after' => $choice,
            ], $company, $user);

            return $attestation;
        });
    }
}
