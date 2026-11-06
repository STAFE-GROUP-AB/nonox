<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Domain\Benefits\CarBenefitCalculator;
use App\Modules\Payroll\Domain\Benefits\CarBenefitValue;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\Steps\Allowances;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\PayCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The registered company cars (Lön → Förmånsbilar) as pay inputs for a
 * month: one BILFORMAN line of a twelfth of the year's value for each car
 * the employee has on any day of the month (IL 61 kap. 9 § andra stycket),
 * and the employee's own monthly payment as FORMAN_EGENBETALNING, which the
 * engine takes from the net pay and from the benefit value (61 kap. 11 §).
 * The year is the month's calendar year, whatever the payment day: the
 * benefit is taxed in the month it is used (Skatteverket, Drivmedelsförmån:
 * "En förmån ska vanligtvis beskattas den månad som den används").
 */
final class CarBenefits
{
    public const string PAY_CODE = 'BILFORMAN';

    public const array RULE_KEYS = ['benefit.car.formula', 'benefit.car.scope', 'prisbasbelopp'];

    /** Set in the BILFORMAN input's explanation; Additions puts it on the payslip line as its rule version. */
    public const string RULE_VERSION = 'rule_version_id';

    public function __construct(private readonly CarBenefitCalculator $calculator) {}

    /**
     * The car's value for a year with the rules verified for it.
     *
     * @throws PayrollBlockedException when a rule is missing or the car is not calculated
     */
    public function value(Benefit $car, int $year, ?RuleSet $rules = null): CarBenefitValue
    {
        $rules ??= RuleSet::for(CarbonImmutable::create($year, 1, 1) ?? throw new PayrollBlockedException('Bad year.'));

        try {
            return $this->calculator->calculate($car->car(), $year, (array) $rules->get('benefit.car.formula'), (array) $rules->get('benefit.car.scope'), (int) ((array) $rules->get('prisbasbelopp'))['ore']);
        } catch (MissingPayrollRuleException $exception) {
            throw new PayrollBlockedException(__('Bilförmånen för :year kan inte räknas: regeln :rule är :state. Öppna Lön → Regler, jämför värdet med källan och markera det som kontrollerat.', [
                'year' => $year,
                'rule' => RulePresenter::name($exception->key),
                'state' => $exception->unverified ? __('inte kontrollerad') : __('inte publicerad för året ännu'),
            ]));
        }
    }

    /**
     * @param  Collection<int, Benefit>  $cars  the employee's cars
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  list<PayInput>  $entered  the inputs already in the run for the employee
     * @param  RuleSet  $rules  the rules for the month's calendar year
     * @return list<PayInput>
     */
    public function payInputs(Collection $cars, CarbonImmutable $monthStart, Collection $payCodes, array $entered, RuleSet $rules): array
    {
        $cars = $cars->filter(fn (Benefit $car): bool => $car->type === Benefit::CAR && $car->coversMonth($monthStart))->values();

        if ($cars->isEmpty()) {
            return [];
        }

        if (array_filter($entered, fn (PayInput $input): bool => $input->code === self::PAY_CODE) !== []) {
            throw new PayrollBlockedException(__('Bilförmånen räknas från Lön → Förmånsbilar. Ta bort raden Bilförmån som lagts in för hand, så att förmånen inte tas med två gånger.'));
        }

        // Two cars in one month (a change of car): which value applies for the month is not settled here.
        // TODO(legal-verify): Skatteverket's rule when the car is changed during a month.
        if ($cars->count() > 1) {
            throw new PayrollBlockedException(__('Två förmånsbilar under samma månad. Räkna förmånsvärdet för månaden för hand och lägg in det med lönearten Bilförmån, och låt bara en bil gälla för månaden under Lön → Förmånsbilar.'));
        }

        $car = $cars->sole();
        $code = $payCodes->first(fn (PayCode $payCode): bool => $payCode->code === self::PAY_CODE) ?? throw new PayrollBlockedException(__('Lönearten :code finns inte. Slå av och på lönemodulen för att lägga till systemets lönearter.', ['code' => self::PAY_CODE]));
        $value = $this->value($car, $monthStart->year, $rules);
        $inputs = [new PayInput($code->code, __('Bilförmån, :car', ['car' => $car->carLabel()]), $code->category, $code->is_taxable, $code->tax_method, $code->is_contribution_basis, $code->is_vacation_basis,
            amountOre: $value->monthlyOre,
            explanation: ['car_benefit' => ['benefit_id' => $car->id, ...$value->explanation], self::RULE_VERSION => $rules->version('benefit.car.formula')->id])];
        $ownPayment = $car->car()->ownPaymentMonthlyOre;

        if ($ownPayment > 0) {
            $own = $payCodes->first(fn (PayCode $payCode): bool => $payCode->code === Allowances::OWN_PAYMENT) ?? throw new PayrollBlockedException(__('Lönearten :code finns inte. Slå av och på lönemodulen för att lägga till systemets lönearter.', ['code' => Allowances::OWN_PAYMENT]));
            $inputs[] = new PayInput($own->code, __('Egen betalning för förmånsbil'), $own->category, $own->is_taxable, $own->tax_method, $own->is_contribution_basis, $own->is_vacation_basis,
                amountOre: $ownPayment,
                explanation: ['car_benefit_id' => $car->id]);
        }

        return $inputs;
    }
}
