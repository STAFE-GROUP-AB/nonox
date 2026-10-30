<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayInput;
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

        $this->ownPayment($context, $draft, $benefits);
    }

    private function allowance(PayrollContext $context, PayslipDraft $draft, PayInput $input): void
    {
        [$rule, $taxableCode] = self::TAX_FREE[$input->code];
        $limit = (int) ((array) $context->rules->get($rule))['ore'];
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
