<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

use App\Models\Company;
use App\Models\Vehicle;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\PayrollModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Links the log's company cars to the car benefits in Lön → Förmånsbilar
 * (ADR-061) by registration number. A company car with a benefit on the
 * trip date is that employee's förmånsbil; one without is a pool car. The
 * company's car benefits are read once, in one query.
 */
final class CompanyCarBenefits
{
    /** @param Collection<int, Benefit> $benefits */
    private function __construct(private readonly Collection $benefits) {}

    public static function for(Company $company): self
    {
        if (! app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company)) {
            return new self(collect());
        }

        return new self(Benefit::query()->where('type', Benefit::CAR)->get());
    }

    public function on(Vehicle $vehicle, CarbonImmutable $date): ?Benefit
    {
        if (! $vehicle->isCompanyCar()) {
            return null;
        }

        $registration = Vehicle::normaliseRegistration($vehicle->registration_number);

        if ($registration === '') {
            return null;
        }

        return $this->benefits->first(fn (Benefit $benefit): bool => Vehicle::normaliseRegistration((string) ($benefit->car_data['registration_number'] ?? '')) === $registration
            && ! $benefit->valid_from->greaterThan($date)
            && ($benefit->valid_to === null || ! $benefit->valid_to->lessThan($date)));
    }

    /** The car's fuel: the benefit's when there is one that day, otherwise the one typed for the car. */
    public function fuel(Vehicle $vehicle, CarbonImmutable $date): ?string
    {
        $benefit = $this->on($vehicle, $date);

        return $benefit === null ? $vehicle->fuel : (string) ($benefit->car_data['fuel'] ?? $vehicle->fuel);
    }

    /** @return Collection<int, Benefit> the benefits for the car on any day of the year */
    public function forYear(Vehicle $vehicle, int $year): Collection
    {
        $registration = Vehicle::normaliseRegistration($vehicle->registration_number);
        $start = CarbonImmutable::create($year, 1, 1) ?? CarbonImmutable::now();
        $end = $start->endOfYear();

        return $this->benefits->filter(fn (Benefit $benefit): bool => $registration !== ''
            && Vehicle::normaliseRegistration((string) ($benefit->car_data['registration_number'] ?? '')) === $registration
            && ! $benefit->valid_from->greaterThan($end)
            && ($benefit->valid_to === null || ! $benefit->valid_to->lessThan($start)))->values();
    }
}
