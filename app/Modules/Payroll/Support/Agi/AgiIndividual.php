<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

/**
 * One payee's individuppgift for a period, in whole kronor. The tax goes in
 * the field for the employee's tax form: preliminary tax, SINK or A-SINK.
 */
final readonly class AgiIndividual
{
    public const string TAX_PRELIMINARY = 'PRELIMINARY';

    public const string TAX_SINK = 'SINK';

    public const string TAX_A_SINK = 'A_SINK';

    public function __construct(
        public int $employeeId,
        public int $specificationNumber,
        public int $cashKronor,
        public int $otherBenefitsKronor,
        public int $carBenefitKronor,
        public int $fuelBenefitKronor,
        public int $taxKronor,
        public string $taxForm,
        public bool $mileage,
        public bool $perDiem,
    ) {}

    /** @return array<string, int|bool|string> */
    public function toArray(): array
    {
        return [
            'specification' => $this->specificationNumber,
            'cash' => $this->cashKronor,
            'other_benefits' => $this->otherBenefitsKronor,
            'car_benefit' => $this->carBenefitKronor,
            'fuel_benefit' => $this->fuelBenefitKronor,
            'tax' => $this->taxKronor,
            'tax_form' => $this->taxForm,
            'mileage' => $this->mileage,
            'per_diem' => $this->perDiem,
        ];
    }
}
