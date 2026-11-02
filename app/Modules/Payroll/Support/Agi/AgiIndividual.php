<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

/**
 * One payee's individuppgift for a period, in whole kronor. The tax goes in
 * the field for the employee's tax form: preliminary tax, SINK or A-SINK.
 * Pay that is avgiftsfri under the 1 000 kr rule goes in the "ej underlag
 * för socialavgifter" fields (131–134); in the month the year passes the
 * threshold, pay declared that way in earlier months is added to 011 and
 * stated in 010 (Skatteverket, ruta 010, AUD-LON-02, #71). A-SINK tax in
 * 275 goes with verksamhetens art in 112 (Skatteverket, ruta 275: "Om du
 * har redovisat i den här rutan ska du också fylla i ruta 112"; enligt
 * granskning 2026-11-02, juristgranskning återstår, AUD-LON-05, #73).
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
        public int $freeCashKronor = 0,
        public int $freeOtherBenefitsKronor = 0,
        public int $freeCarBenefitKronor = 0,
        public int $freeFuelBenefitKronor = 0,
        public int $earlierFreeKronor = 0,
        public ?string $activityType = null,
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
            'free_cash' => $this->freeCashKronor,
            'free_other_benefits' => $this->freeOtherBenefitsKronor,
            'free_car_benefit' => $this->freeCarBenefitKronor,
            'free_fuel_benefit' => $this->freeFuelBenefitKronor,
            'earlier_free' => $this->earlierFreeKronor,
            'activity_type' => (string) $this->activityType,
        ];
    }
}
