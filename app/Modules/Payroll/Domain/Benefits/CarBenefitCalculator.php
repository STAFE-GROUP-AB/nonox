<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The value of a car benefit for a calendar year (inkomstskattelagen
 * 1999:1229, 61 kap. 5–9 §§), pure: every rate comes from the payroll rules
 * benefit.car.formula, benefit.car.scope and prisbasbelopp for the year.
 *
 * - 5 §: 0,29 prisbasbelopp + nybilspris × (70 % of statslåneräntan + 0,01)
 *   + 13 % of nybilspris + the car's fordonsskatt; statslåneräntan at the end
 *   of November the year before, at least 0,5 %.
 * - 7 §: a model six years or older counts at least four prisbasbelopp.
 * - 8 §: extra equipment is added to the nybilspris (not an alkolås).
 * - 8 a §: the nybilspris is lowered for electric, hydrogen, plug-in hybrid
 *   and gas cars, by at most 50 % of the nybilspris.
 * - 9 §: 75 % for at least 3 000 mil in the service in the calendar year; a
 *   twelfth less for each whole calendar month without the benefit, which is
 *   the same as one twelfth for each month with it (monthlyOre).
 *
 * Only cars that first became taxable under vägtrafikskattelagen on or after
 * benefit.car.scope formula_from_taxable_on (1 July 2021, övergångs-
 * bestämmelser SFS 2021:479) are calculated, and an environmental car only
 * from reductions_from_taxable_on (after 30 June 2022, SFS 2022:479): older
 * cars follow older wording the consolidated law no longer shows, so they
 * are refused rather than guessed.
 *
 * Rounding: each part is cut to whole kronor, as in Skatteverket's example
 * in Belopp och procent 2026 (2,785 % of 322 000 = 8 967 kr); the year's
 * value and the month's (a twelfth) are cut to whole kronor too.
 * TODO(legal-verify): the rounding of the parts and of the monthly value;
 * whether the 50 % cap in 8 a § is taken on the price with extra equipment
 * and after 7 §; how the six years in 7 § are counted (here: income year
 * minus model year).
 */
final class CarBenefitCalculator
{
    private const int ORE_PER_KRONA = 100;

    private const int BASIS_POINTS = 10_000;

    /**
     * @param  array<string, mixed>  $formula  benefit.car.formula for the year
     * @param  array<string, mixed>  $scope  benefit.car.scope for the year
     *
     * @throws PayrollBlockedException when the car follows rules not built
     */
    public function calculate(CarBenefitCar $car, int $year, array $formula, array $scope, int $prisbasbeloppOre): CarBenefitValue
    {
        $formulaFrom = CarbonImmutable::parse((string) $scope['formula_from_taxable_on']);
        $reductionsFrom = CarbonImmutable::parse((string) $scope['reductions_from_taxable_on']);
        $reductionKey = CarBenefitCar::REDUCED[$car->fuel] ?? null;

        if ($car->firstTaxableOn->lessThan($formulaFrom)) {
            throw new PayrollBlockedException(__('Bilen blev skattepliktig före :date. För den gäller äldre regler som NONOX inte räknar. Räkna förmånsvärdet med Skatteverkets bilförmånsberäkning och lägg in det med lönearten Bilförmån.', ['date' => $formulaFrom->translatedFormat('j F Y')]));
        }

        if ($reductionKey !== null && $car->firstTaxableOn->lessThan($reductionsFrom)) {
            throw new PayrollBlockedException(__('En miljöbil som blev skattepliktig före :date sätts ned efter äldre regler som NONOX inte räknar. Räkna förmånsvärdet med Skatteverkets bilförmånsberäkning och lägg in det med lönearten Bilförmån.', ['date' => $reductionsFrom->translatedFormat('j F Y')]));
        }

        $interest = (array) $formula['interest'];
        $price = $car->newCarPriceOre + $car->extraEquipmentOre;
        $oldModel = $year - $car->modelYear >= (int) $scope['old_model_age_years'];
        $oldModelFloor = (int) $scope['old_model_min_prisbasbelopp'] * $prisbasbeloppOre;

        if ($oldModel) {
            $price = max($price, $oldModelFloor);
        }

        $reduction = 0;

        if ($reductionKey !== null) {
            $cap = $this->share($price, (int) $formula['reduction_max_bp']);
            $reduction = min((int) ((array) $formula['reductions_ore'])[$reductionKey], $cap);
        }

        $reducedPrice = $price - $reduction;
        $stateRateBp = max((int) $interest['state_rate_bp'], (int) $interest['min_bp']);
        // 70 % of statslåneräntan + 0,01, in hundredths of a basis point so 70 % of 2,55 % (1,785 %) stays exact.
        $interestRate = BigDecimal::of($stateRateBp)->multipliedBy((int) $interest['state_rate_share_bp'])->dividedBy(self::BASIS_POINTS, 6, RoundingMode::Down)->plus((int) $interest['plus_bp']);

        $parts = [
            'prisbasbelopp_ore' => $this->share($prisbasbeloppOre, (int) $formula['prisbasbelopp_share_bp']),
            'interest_ore' => $this->kronor(BigDecimal::of($reducedPrice)->multipliedBy($interestRate)->dividedBy(self::BASIS_POINTS, 6, RoundingMode::Down)),
            'price_ore' => $this->share($reducedPrice, (int) $formula['price_share_bp']),
            'vehicle_tax_ore' => $this->kronor(BigDecimal::of($car->vehicleTaxOre)),
        ];
        $full = array_sum($parts);
        $yearly = $car->longBusinessMileage ? $this->share($full, (int) $formula['long_service_share_bp']) : $full;
        $monthly = $this->kronor(BigDecimal::of($yearly)->dividedBy(12, 6, RoundingMode::Down));

        return new CarBenefitValue(
            yearlyOre: $yearly,
            monthlyOre: $monthly,
            explanation: [
                'year' => $year,
                'new_car_price_ore' => $car->newCarPriceOre,
                'extra_equipment_ore' => $car->extraEquipmentOre,
                'old_model' => $oldModel,
                'old_model_floor_ore' => $oldModel ? $oldModelFloor : null,
                'price_ore' => $price,
                'reduction_ore' => $reduction,
                'reduced_price_ore' => $reducedPrice,
                'state_rate_bp' => $stateRateBp,
                'interest_rate' => (string) $interestRate->dividedBy(100, 6, RoundingMode::Down)->strippedOfTrailingZeros(),
                'parts' => $parts,
                'full_ore' => $full,
                'long_business_mileage' => $car->longBusinessMileage,
                'yearly_ore' => $yearly,
                'monthly_ore' => $monthly,
            ],
        );
    }

    /** A share in basis points of an amount in öre, cut to whole kronor. */
    private function share(int $ore, int $basisPoints): int
    {
        return $this->kronor(BigDecimal::of($ore)->multipliedBy($basisPoints)->dividedBy(self::BASIS_POINTS, 6, RoundingMode::Down));
    }

    private function kronor(BigDecimal $ore): int
    {
        return $ore->dividedBy(self::ORE_PER_KRONA, 0, RoundingMode::Down)->multipliedBy(self::ORE_PER_KRONA)->toInt();
    }
}
