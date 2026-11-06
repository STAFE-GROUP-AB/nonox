<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\PayrollRun;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Drivmedelsförmån at a car benefit. The pay code DRIVMEDELSFORMAN takes the
 * benefit value, as the manual has always said: the market value of the fuel
 * used for private driving multiplied by 1,2 (IL 61 kap. 10 §). That value is
 * the basis for the tax deduction and goes whole into ruta 018; the basis for
 * employer contributions is the market value without 1,2 (socialavgiftslagen
 * 2 kap. 10 a § punkt 2; Skatteverket, Drivmedelsförmån: "Som underlag för
 * arbetsgivaravgifter använder du däremot marknadsvärdet utan att multiplicera
 * det med 1,2"). The factor is the rule benefit.fuel.factor.
 *
 * The employee's own payment for the fuel (DRIVMEDEL_BETALNING, a net
 * deduction) lowers the taxable value (61 kap. 11 §) and is declared in ruta
 * 098, while 018 keeps the whole value (Skatteverket, agbeskrivning: "ska hela
 * det uppräknade förmånsvärdet redovisas i ruta 018").
 *
 * TODO(legal-verify): the market value is taken back from the typed value
 * (value / 1,2, half up to öre), so it can differ from Skatteverket's own
 * kronor by less than one krona; and the payment is taken from the
 * contribution basis too (61 kap. 11 § through SAL 2 kap. 10 a §), up to the
 * market value — Skatteverket's page only says it computes the contributions
 * on the market value.
 */
final class FuelBenefit
{
    public const string CODE = 'DRIVMEDELSFORMAN';

    public const string OWN_PAYMENT = 'DRIVMEDEL_BETALNING';

    public const string RULE = 'benefit.fuel.factor';

    /** Set in the reduction line's explanation, so the AGI puts it in ruta 098 and not in 018. */
    public const string PAYMENT_SOURCE = 'fuel_own_payment';

    private const int BASIS_POINTS = 10_000;

    /**
     * Fuel is a benefit only at a company car (IL 61 kap. 10 a §); fuel for one's
     * own car is cash pay. Refuses the pay code unless a car is valid in the month.
     *
     * @throws ValidationException
     */
    public static function assertCompanyCar(PayrollRun $run, int $employeeId, string $payCode): void
    {
        if ($payCode !== self::CODE) {
            return;
        }

        $monthStart = $run->periodStart();
        $hasCar = Benefit::query()
            ->where('employee_id', $employeeId)
            ->where('type', Benefit::CAR)
            ->get()
            ->contains(fn (Benefit $car): bool => $car->coversMonth($monthStart));

        if (! $hasCar) {
            throw ValidationException::withMessages(['pay_code_id' => __('Drivmedelsförmån kan bara läggas in när den anställde har en förmånsbil som gäller i månaden. Drivmedel till en egen bil är vanlig lön, inte en förmån. Lägg först in bilen under Lön → Förmånsbilar.')]);
        }
    }

    /**
     * The market value inside a benefit value (öre).
     *
     * @throws PayrollBlockedException when the factor is not verified for the date
     */
    public static function marketValue(int $valueOre, RuleSet $rules): int
    {
        try {
            $factor = (int) ((array) $rules->get(self::RULE))['tax_factor_bp'];
        } catch (MissingPayrollRuleException) {
            throw new PayrollBlockedException(__('Drivmedelsförmånen kan inte räknas: regeln Drivmedelsförmån, uppräkning är inte kontrollerad för utbetalningsdagen. Öppna Lön → Regler, jämför värdet med källan och markera det som kontrollerat.'));
        }

        return BigDecimal::of($valueOre)->multipliedBy(self::BASIS_POINTS)->dividedBy($factor, 0, RoundingMode::HalfUp)->toInt();
    }
}
