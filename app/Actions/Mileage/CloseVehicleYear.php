<?php

declare(strict_types=1);

namespace App\Actions\Mileage;

use App\Domain\Mileage\MileageLog;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleYearReading;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The odometer at the start and end of a calendar year, and closing the
 * year in the log (KJ-R9, F14). Skatteverket wants both readings in a
 * company car's körjournal, so a company car's year cannot be closed
 * without them. Staff with mileage:manage keep the readings; a person keeps
 * those of their own car.
 */
class CloseVehicleYear
{
    /**
     * @param  array<string, mixed>  $input  start_odometer, end_odometer
     */
    public function save(User $user, Company $company, Vehicle $vehicle, int $year, array $input): VehicleYearReading
    {
        $this->authorize($user, $company, $vehicle);

        $validated = Validator::make($input, [
            'start_odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'end_odometer' => ['nullable', 'integer', 'min:0', 'max:9999999', 'gte:start_odometer'],
        ], [
            'end_odometer.gte' => __('Mätarställningen vid årets slut ska vara minst lika hög som vid årets början.'),
        ], ['start_odometer' => __('mätarställning vid årets början'), 'end_odometer' => __('mätarställning vid årets slut')])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $vehicle, $year, $validated): VehicleYearReading {
            $reading = VehicleYearReading::query()->firstOrNew(['vehicle_id' => $vehicle->id, 'year' => $year]);

            if ($reading->closed_at !== null) {
                throw ValidationException::withMessages(['year' => __('Året är stängt i körjournalen.')]);
            }

            $reading->fill([
                'start_odometer_km' => blank($validated['start_odometer'] ?? null) ? null : (int) $validated['start_odometer'],
                'end_odometer_km' => blank($validated['end_odometer'] ?? null) ? null : (int) $validated['end_odometer'],
            ])->save();
            Audit::record('mileage.year_reading_saved', $reading, ['vehicle_id' => $vehicle->id, 'year' => $year], $company, $user);

            return $reading;
        });
    }

    public function close(User $user, Company $company, Vehicle $vehicle, int $year): VehicleYearReading
    {
        $this->authorize($user, $company, $vehicle);

        return CurrentCompany::run($company, function () use ($user, $company, $vehicle, $year): VehicleYearReading {
            $reading = VehicleYearReading::query()->firstOrNew(['vehicle_id' => $vehicle->id, 'year' => $year]);

            if ($reading->closed_at !== null) {
                throw ValidationException::withMessages(['year' => __('Året är redan stängt i körjournalen.')]);
            }

            if ($vehicle->isCompanyCar() && $reading->start_odometer_km === null) {
                throw ValidationException::withMessages(['start_odometer' => __('Fyll i mätarställningen vid årets början.')]);
            }

            if ($vehicle->isCompanyCar() && $reading->end_odometer_km === null) {
                throw ValidationException::withMessages(['end_odometer' => __('Fyll i mätarställningen vid årets slut.')]);
            }

            $reading->forceFill(['closed_at' => now(), 'closed_by' => $user->id])->save();
            Audit::record('mileage.year_closed', $reading, ['vehicle_id' => $vehicle->id, 'year' => $year], $company, $user);

            return $reading;
        });
    }

    private function authorize(User $user, Company $company, Vehicle $vehicle): void
    {
        abort_unless(MileageLog::enabled($company), 404);

        if (! MileageLog::canManage($user, $company) && ! ($vehicle->user_id === $user->id && ! $vehicle->isCompanyCar())) {
            throw new AuthorizationException;
        }
    }
}
