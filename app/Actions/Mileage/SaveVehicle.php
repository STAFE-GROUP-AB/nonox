<?php

declare(strict_types=1);

namespace App\Actions\Mileage;

use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\VehicleKind;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds or changes a car in the mileage log. Staff with mileage:manage keep
 * the company's cars and anyone's; a person adds only their own car. A car
 * is archived, never deleted, so the trips in it stay readable.
 */
class SaveVehicle
{
    /**
     * @param  array<string, mixed>  $input  kind, label, registration_number, fuel, is_business_asset
     */
    public function __invoke(User $user, Company $company, array $input, ?Vehicle $vehicle = null): Vehicle
    {
        abort_unless(MileageLog::enabled($company), 404);

        $manager = MileageLog::canManage($user, $company);
        $self = MileageLog::canLogAsEmployee($user, $company) || MileageLog::isSoleTraderOwner($user, $company);
        $kind = $vehicle?->kind->value ?? (string) ($input['kind'] ?? '');

        if (! $manager && ! ($self && $kind === VehicleKind::PrivateCar->value && ($vehicle === null || $vehicle->user_id === $user->id))) {
            throw new AuthorizationException;
        }

        $validated = Validator::make([...$input, 'kind' => $kind], [
            'kind' => ['required', Rule::enum(VehicleKind::class)],
            'label' => ['required', 'string', 'max:120'],
            'registration_number' => [$kind === VehicleKind::CompanyCar->value ? 'required' : 'nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9 -]+$/'],
            'fuel' => [$kind === VehicleKind::CompanyCar->value ? 'required' : 'nullable', Rule::in(CarBenefitCar::FUELS)],
            'is_business_asset' => ['boolean'],
        ], [
            'registration_number.required' => __('Skriv bilens registreringsnummer.'),
            'registration_number.regex' => __('Registreringsnumret får bara innehålla bokstäver, siffror och mellanslag.'),
            'fuel.required' => __('Välj vilket drivmedel bilen har.'),
        ], ['label' => __('namn på bilen')])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $validated, $vehicle, $kind): Vehicle {
            $attributes = [
                'kind' => $kind,
                'label' => trim((string) $validated['label']),
                'registration_number' => filled($validated['registration_number'] ?? null) ? strtoupper(trim((string) $validated['registration_number'])) : null,
                'fuel' => $validated['fuel'] ?? null,
                'is_business_asset' => (bool) ($validated['is_business_asset'] ?? false),
            ];

            if ($vehicle === null) {
                $vehicle = Vehicle::create([...$attributes, 'user_id' => $kind === VehicleKind::PrivateCar->value ? $user->id : null]);
                Audit::record('mileage.vehicle_created', $vehicle, ['kind' => $kind], $company, $user);

                return $vehicle;
            }

            $vehicle->fill($attributes)->save();
            Audit::record('mileage.vehicle_updated', $vehicle, ['kind' => $kind], $company, $user);

            return $vehicle;
        });
    }

    public function archive(User $user, Company $company, Vehicle $vehicle): Vehicle
    {
        abort_unless(MileageLog::enabled($company), 404);

        if (! MileageLog::canManage($user, $company) && $vehicle->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $vehicle): Vehicle {
            $vehicle->forceFill(['archived_at' => now()])->save();
            Audit::record('mileage.vehicle_archived', $vehicle, [], $company, $user);

            return $vehicle;
        });
    }
}
