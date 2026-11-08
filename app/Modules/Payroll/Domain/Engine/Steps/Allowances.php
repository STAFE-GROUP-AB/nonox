<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Benefits\FuelBenefit;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Steps 6 and 7 (PAYROLL_MODULE.md B.9): meal benefits at Skatteverket's
 * values, the employee's own payment reducing the benefit value, and
 * mileage and domestic per diem, tax-free up to the rule and taxable above
 * it. The rates come from the rules; a paid rate above the tax-free limit
 * splits into a tax-free and a taxable line.
 *
 * An allowance always needs its number of mil or days: the tax-free part is
 * the rule times that number (IL 12 kap. 5 §; Skatteverket, ruta 050/051:
 * the excess is pay). The agreed pay can be given as a rate per unit or as
 * the whole amount; an amount without a number blocks the payslip instead
 * of paying nothing (enligt granskning 2026-11-02, juristgranskning
 * återstår, AUD-LON-09, #76).
 *
 * TODO(legal-verify): per diem conditions (overnight stay, more than 50 km,
 * reduced amounts after three months and two years) and meal reductions on
 * per diem are the user's responsibility when entering days.
 */
final class Allowances implements Step
{
    /** Meal benefit pay code => rule with its value per meal or day. */
    public const array MEALS = [
        'KOSTFORMAN_HEL' => 'benefit.meal.free_day',
        'KOSTFORMAN_LUNCH' => 'benefit.meal.lunch_or_dinner',
        'KOSTFORMAN_FRUKOST' => 'benefit.meal.breakfast',
    ];

    /** Tax-free allowance pay code => [rule, pay code for the taxable excess]. */
    public const array TAX_FREE = [
        'MILERSATTNING' => ['mileage.own_car_tax_free_per_mil', 'MIL_SKATTEPLIKTIG'],
        // A company car where the employee paid all the fuel (IL 12 kap. 5 § andra stycket, ADR-072).
        'MIL_FORMANSBIL' => ['mileage.company_car_fossil', 'MIL_SKATTEPLIKTIG'],
        'MIL_FORMANSBIL_EL' => ['mileage.company_car_electric', 'MIL_SKATTEPLIKTIG'],
        'TRAKTAMENTE_HEL' => ['per_diem.domestic.full', 'TRAKT_SKATTEPLIKTIG'],
        'TRAKTAMENTE_HALV' => ['per_diem.domestic.half', 'TRAKT_SKATTEPLIKTIG'],
        'TRAKTAMENTE_NATT' => ['per_diem.domestic.night', 'TRAKT_SKATTEPLIKTIG'],
    ];

    public const string OWN_PAYMENT = 'FORMAN_EGENBETALNING';

    /** @return list<string> the pay codes this step prices, which Additions leaves alone */
    public static function codes(): array
    {
        return [...array_keys(self::MEALS), ...array_keys(self::TAX_FREE)];
    }

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $benefits = 0;

        foreach ($context->inputs as $input) {
            if (isset(self::MEALS[$input->code])) {
                $value = (int) ((array) $context->rules->get(self::MEALS[$input->code]))['ore'];
                $amount = $input->amountOre ?? $this->times($input, $value);
                $benefits += $amount;
                $draft->add(new PayslipLineResult($input->code, $input->description, $amount, PayslipLineResult::TAX_TABLE, true, false, $input->quantity, $value,
                    ruleVersionId: $context->rules->version(self::MEALS[$input->code])->id,
                    explanation: ['source' => 'input', 'category' => 'BENEFIT', 'benefit' => true]));
            }

            if (isset(self::TAX_FREE[$input->code])) {
                $this->allowance($context, $draft, $input);
            }
        }

        $this->fuelOwnPayment($context, $draft);
        $this->ownPayment($context, $draft, $benefits);
    }

    /**
     * The employee's payment for fuel at a car benefit lowers the taxable fuel
     * value and, up to the market value, the contribution basis; the AGI keeps
     * the whole value in 018 and the payment in 098 (FuelBenefit).
     */
    private function fuelOwnPayment(PayrollContext $context, PayslipDraft $draft): void
    {
        $paid = array_sum(array_map(fn (PayInput $input): int => abs($input->total()), array_values(array_filter($context->inputs, fn (PayInput $input): bool => $input->code === FuelBenefit::OWN_PAYMENT))));

        if ($paid === 0) {
            return;
        }

        $fuel = array_values(array_filter($draft->lines, fn (PayslipLineResult $line): bool => $line->code === FuelBenefit::CODE));
        $value = array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, $fuel));
        $marketValue = array_sum(array_map(fn (PayslipLineResult $line): int => $line->contributionBasis ? ($line->contributionOre ?? $line->amountOre) : 0, $fuel));

        if ($value <= 0) {
            throw new PayrollBlockedException(__('Egen betalning för drivmedel vid bilförmån finns utan någon drivmedelsförmån i månaden. Lägg in drivmedelsförmånen eller ta bort betalningen.'));
        }

        $reduction = min($paid, $value);
        $draft->add(new PayslipLineResult(FuelBenefit::CODE, __('Drivmedelsförmånen minskad med egen betalning'), -$reduction, PayslipLineResult::TAX_TABLE, true, false,
            explanation: ['source' => FuelBenefit::PAYMENT_SOURCE, 'category' => 'BENEFIT', 'benefit' => true, 'paid_ore' => $paid, 'contribution_basis_ore' => -min($paid, $marketValue)],
            contributionOre: -min($paid, $marketValue)));
    }

    private function allowance(PayrollContext $context, PayslipDraft $draft, PayInput $input): void
    {
        [$rule, $taxableCode] = self::TAX_FREE[$input->code];
        $limit = (int) ((array) $context->rules->get($rule))['ore'];

        if ($input->quantity === null || BigDecimal::of($input->quantity)->isLessThanOrEqualTo(0)) {
            throw new PayrollBlockedException(__(':item behöver antal mil eller dagar. Det skattefria beloppet räknas per mil eller dag; skriv antalet och vid behov hela ersättningen.', ['item' => $input->description]));
        }

        if ($input->amountOre !== null) {
            $this->allowanceAmount($context, $draft, $input, $rule, $taxableCode, $limit);

            return;
        }

        $rate = $input->rateOre ?? $limit;
        $taxFreeRate = min($rate, $limit);

        $draft->add(new PayslipLineResult($input->code, $input->description, $this->times($input, $taxFreeRate), PayslipLineResult::NET, false, false, $input->quantity, $taxFreeRate,
            ruleVersionId: $context->rules->version($rule)->id,
            explanation: ['source' => 'input', 'category' => 'ALLOWANCE_TAX_FREE', 'tax_free' => true, 'limit_ore' => $limit]));

        if ($rate > $limit) {
            $draft->add(new PayslipLineResult($taxableCode, __(':item, över skattefria beloppet', ['item' => $input->description]), $this->times($input, $rate - $limit), PayslipLineResult::TAX_TABLE, true, false, $input->quantity, $rate - $limit,
                explanation: ['source' => 'input', 'category' => 'ALLOWANCE_TAXABLE', 'limit_ore' => $limit, 'paid_rate_ore' => $rate]));
        }
    }

    /**
     * The whole agreed allowance as an amount with its number of mil or
     * days: tax-free up to the rule times the number, the rest taxable pay.
     */
    private function allowanceAmount(PayrollContext $context, PayslipDraft $draft, PayInput $input, string $rule, string $taxableCode, int $limit): void
    {
        $paid = (int) $input->amountOre;

        if ($paid < 0) {
            throw new PayrollBlockedException(__(':item kan inte vara negativ.', ['item' => $input->description]));
        }

        $taxFree = min($paid, $this->times($input, $limit));

        $draft->add(new PayslipLineResult($input->code, $input->description, $taxFree, PayslipLineResult::NET, false, false, $input->quantity, null,
            ruleVersionId: $context->rules->version($rule)->id,
            explanation: ['source' => 'input', 'category' => 'ALLOWANCE_TAX_FREE', 'tax_free' => true, 'limit_ore' => $limit, 'paid_ore' => $paid]));

        if ($paid > $taxFree) {
            $draft->add(new PayslipLineResult($taxableCode, __(':item, över skattefria beloppet', ['item' => $input->description]), $paid - $taxFree, PayslipLineResult::TAX_TABLE, true, false,
                explanation: ['source' => 'input', 'category' => 'ALLOWANCE_TAXABLE', 'limit_ore' => $limit, 'paid_ore' => $paid]));
        }
    }

    /**
     * The employee's own payment for a benefit is deducted from the net pay
     * (NetItems) and lowers the taxable benefit value by as much, never below
     * nothing.
     */
    private function ownPayment(PayrollContext $context, PayslipDraft $draft, int $benefits): void
    {
        $paid = array_sum(array_map(fn (PayInput $input): int => abs($input->total()), array_values(array_filter($context->inputs, fn (PayInput $input): bool => $input->code === self::OWN_PAYMENT))));
        $otherBenefits = array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter($draft->lines, fn (PayslipLineResult $line): bool => (bool) ($line->explanation['benefit'] ?? false) && ! isset(self::MEALS[$line->code])))));
        $reduction = min($paid, $benefits + $otherBenefits);

        if ($reduction > 0) {
            $draft->add(new PayslipLineResult('OVRIG_FORMAN', __('Förmånsvärdet minskat med egen betalning'), -$reduction, PayslipLineResult::TAX_TABLE, true, false,
                explanation: ['source' => 'own_payment', 'category' => 'BENEFIT', 'benefit' => true]));
        }
    }

    private function times(PayInput $input, int $rate): int
    {
        return BigDecimal::of($input->quantity ?? '0')->multipliedBy($rate)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
