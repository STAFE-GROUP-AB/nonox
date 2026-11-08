<?php

declare(strict_types=1);

namespace Tests\Feature\Mileage;

use App\Actions\Mileage\LogTrip;
use App\Actions\Mileage\SaveVehicle;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Modules\Payroll\Actions\CompanyCars;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\Employee;

/**
 * Synthetic körjournal data for the ADR-072 tests: a company with the log
 * on, cars and trips typed through the real actions.
 */
final class MileageSupport
{
    public static function enable(Company $company): void
    {
        $company->forceFill(['mileage_log_enabled' => true])->save();
    }

    public static function ownCar(User $user, Company $company, string $label = 'Egen Volvo V70'): Vehicle
    {
        return app(SaveVehicle::class)($user, $company, ['kind' => 'PRIVATE_CAR', 'label' => $label, 'registration_number' => 'ABC123']);
    }

    public static function companyCar(User $manager, Company $company, string $fuel = 'PETROL', string $registration = 'FMB111', string $label = 'Företagets Kia'): Vehicle
    {
        return app(SaveVehicle::class)($manager, $company, ['kind' => 'COMPANY_CAR', 'label' => $label, 'registration_number' => $registration, 'fuel' => $fuel]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function trip(User $user, Company $company, Vehicle $vehicle, string $date, string $km, array $overrides = []): VehicleTrip
    {
        return app(LogTrip::class)($user, $company, [
            'vehicle_id' => $vehicle->id,
            'kind' => 'BUSINESS',
            'trip_date' => $date,
            'distance' => $km,
            'from_place' => 'Kontoret',
            'to_place' => 'Kund i Lund',
            'purpose' => 'Service hos kund',
            ...$overrides,
        ])[0];
    }

    /** A car benefit for the employee on the car (Lön → Förmånsbilar), so the car is their förmånsbil. */
    public static function benefit(User $owner, Company $company, Employee $employee, string $registration = 'FMB111', string $fuel = 'PETROL'): Benefit
    {
        return app(CompanyCars::class)->save($owner, $company, [
            'employee_id' => $employee->id,
            'make_model' => 'Kia Ceed',
            'registration_number' => $registration,
            'model_year' => 2024,
            'first_taxable_on' => '2024-03-01',
            'fuel' => $fuel,
            'new_car_price' => '300 000',
            'extra_equipment' => '0',
            'vehicle_tax' => '1 500',
            'valid_from' => '2026-01-01',
        ]);
    }
}
