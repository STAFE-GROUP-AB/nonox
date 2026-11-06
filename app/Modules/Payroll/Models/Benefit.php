<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A taxable benefit (car, fuel, meal, housing, other) valid between two dates.
 * A car (type CAR) keeps what its value is calculated from in car_data
 * (registration_number, make_model, model_year, first_taxable_on, fuel,
 * new_car_price_ore, extra_equipment_ore, vehicle_tax_ore,
 * long_business_mileage, own_payment_monthly_ore); Lön → Förmånsbilar.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $type
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property ?array<string, mixed> $car_data
 */
class Benefit extends Model
{
    use BelongsToCompany;

    public const string CAR = 'CAR';

    protected $table = 'benefits';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'value_rule',
        'valid_from',
        'valid_to',
        'car_data',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_rule' => 'array',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'car_data' => 'array',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Whether the employee has the benefit on any day of the month (IL 61 kap. 9 § andra stycket: only whole months without it count). */
    public function coversMonth(CarbonImmutable $monthStart): bool
    {
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        return ! $this->valid_from->greaterThan($monthEnd)
            && ($this->valid_to === null || ! $this->valid_to->lessThan($monthStart));
    }

    public function car(): CarBenefitCar
    {
        $data = $this->car_data ?? [];

        return new CarBenefitCar(
            newCarPriceOre: (int) ($data['new_car_price_ore'] ?? 0),
            extraEquipmentOre: (int) ($data['extra_equipment_ore'] ?? 0),
            modelYear: (int) ($data['model_year'] ?? 0),
            firstTaxableOn: CarbonImmutable::parse((string) ($data['first_taxable_on'] ?? '1970-01-01')),
            fuel: (string) ($data['fuel'] ?? ''),
            vehicleTaxOre: (int) ($data['vehicle_tax_ore'] ?? 0),
            longBusinessMileage: (bool) ($data['long_business_mileage'] ?? false),
            ownPaymentMonthlyOre: (int) ($data['own_payment_monthly_ore'] ?? 0),
        );
    }

    /** "Volvo XC40 (ABC123)" */
    public function carLabel(): string
    {
        $data = $this->car_data ?? [];
        $name = trim((string) ($data['make_model'] ?? ''));
        $registration = trim((string) ($data['registration_number'] ?? ''));

        return $registration === '' ? $name : "{$name} ({$registration})";
    }
}
