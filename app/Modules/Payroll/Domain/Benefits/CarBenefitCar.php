<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

use Carbon\CarbonImmutable;

/**
 * What the car benefit is calculated from (IL 61 kap. 5–9, 11 §§): the
 * model's nybilspris and the extra equipment in the benefit, the model year,
 * the day the car first became taxable under vägtrafikskattelagen, the fuel,
 * the yearly vehicle tax, whether the employee drives at least 3 000 mil in
 * the service in the year, and what the employee pays for it each month
 * (nettolöneavdrag). Amounts in öre.
 */
final readonly class CarBenefitCar
{
    public const string PETROL = 'PETROL';

    public const string DIESEL = 'DIESEL';

    public const string ELECTRIC = 'ELECTRIC';

    public const string HYDROGEN = 'HYDROGEN';

    public const string PLUG_IN_HYBRID = 'PLUG_IN_HYBRID';

    public const string GAS = 'GAS';

    public const string ELECTRIC_HYBRID = 'ELECTRIC_HYBRID';

    public const string ETHANOL = 'ETHANOL';

    public const array FUELS = [self::PETROL, self::DIESEL, self::ELECTRIC, self::HYDROGEN, self::PLUG_IN_HYBRID, self::GAS, self::ELECTRIC_HYBRID, self::ETHANOL];

    /** The fuels 61 kap. 8 a § lowers the nybilspris for, by the rule's key in benefit.car.formula reductions_ore. */
    public const array REDUCED = [self::ELECTRIC => 'electric', self::HYDROGEN => 'hydrogen', self::PLUG_IN_HYBRID => 'plug_in_hybrid', self::GAS => 'gas'];

    public function __construct(
        public int $newCarPriceOre,
        public int $extraEquipmentOre,
        public int $modelYear,
        public CarbonImmutable $firstTaxableOn,
        public string $fuel,
        public int $vehicleTaxOre,
        public bool $longBusinessMileage = false,
        public int $ownPaymentMonthlyOre = 0,
    ) {}

    public static function fuelLabel(string $fuel): string
    {
        return match ($fuel) {
            self::PETROL => __('Bensin'),
            self::DIESEL => __('Diesel'),
            self::ELECTRIC => __('El (elbil)'),
            self::HYDROGEN => __('Vätgas (bränslecell)'),
            self::PLUG_IN_HYBRID => __('Laddhybrid'),
            self::GAS => __('Gas (inte gasol eller vätgas)'),
            self::ELECTRIC_HYBRID => __('Elhybrid (laddas inte utifrån)'),
            self::ETHANOL => __('Etanol'),
            default => $fuel,
        };
    }
}
