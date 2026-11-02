<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Step 12 (PAYROLL_MODULE.md B.9): the occupational pension premium of the
 * employment's pension plan, one rate on pay up to 7.5 income base amounts a
 * year and another above, and the special payroll tax on the premium. Both
 * are the employer's costs: on the payslip for information, never in gross
 * or net pay, and booked 7410/2740 and 7530/2730.
 *
 * TODO(legal-verify): the plan rates are the company's (collective agreement
 * or own policy); which pay is pension basis follows the pay codes' cash pay.
 *
 * A plan from a collective agreement (NONOX Avtal, ADR-033 A10: SAF-LO,
 * ITP1) can set its own threshold, a ceiling above which no premium is
 * paid, and the ages between which it is paid (age on the last day of the
 * period: from the month the person reaches from_age, up to the month
 * before to_age_exclusive).
 */
final class Pension implements Step
{
    /** Income base amounts a year at which the higher rate starts (ITP1 and SAF-LO alike). */
    public const string THRESHOLD_IBB = '7.5';

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $plan = $context->pension;

        if ($plan === null) {
            return;
        }

        $basis = array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter(
            $draft->lines,
            fn (PayslipLineResult $line): bool => $line->contributionBasis && ! ($line->explanation['benefit'] ?? false) && ($line->explanation['category'] ?? null) !== 'ALLOWANCE_TAXABLE',
        ))));

        if ($basis <= 0) {
            return;
        }

        $age = (int) $context->birthDate->diffInYears($context->periodEnd->endOfMonth());

        if ((isset($plan['from_age']) && $age < $plan['from_age']) || (isset($plan['to_age_exclusive']) && $age >= $plan['to_age_exclusive'])) {
            return;
        }

        $ibb = (int) ((array) $context->rules->get('inkomstbasbelopp'))['ore'];
        $threshold = BigDecimal::of($ibb)->multipliedBy($plan['threshold_ibb'] ?? self::THRESHOLD_IBB)->dividedBy(Units::MONTHS_PER_YEAR, 0, RoundingMode::HalfUp)->toInt();
        $ceiling = isset($plan['ceiling_ibb']) ? BigDecimal::of($ibb)->multipliedBy($plan['ceiling_ibb'])->dividedBy(Units::MONTHS_PER_YEAR, 0, RoundingMode::HalfUp)->toInt() : null;
        $below = min($basis, $threshold);
        $above = max(0, ($ceiling === null ? $basis : min($basis, $ceiling)) - $threshold);
        $premium = BigDecimal::of($below)->multipliedBy($plan['below_bp'])->plus(BigDecimal::of($above)->multipliedBy($plan['above_bp']))
            ->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();

        if ($premium <= 0) {
            return;
        }

        $taxBp = (int) ((array) $context->rules->get('special_payroll_tax.pension'))['bp'];

        $draft->add(new PayslipLineResult('TJANSTEPENSION', __('Tjänstepension, :plan', ['plan' => $plan['name']]), $premium, PayslipLineResult::EMPLOYER, false, false,
            ruleVersionId: $plan['rule_version_id'] ?? null,
            explanation: array_filter(['basis_ore' => $basis, 'threshold_ore' => $threshold, 'ceiling_ore' => $ceiling, 'below_bp' => $plan['below_bp'], 'above_bp' => $plan['above_bp'], 'age' => isset($plan['from_age']) || isset($plan['to_age_exclusive']) ? $age : null, 'agreement' => $plan['agreement'] ?? null], fn (mixed $value): bool => $value !== null)));
        $draft->add(new PayslipLineResult('SARSKILD_LONESKATT', __('Särskild löneskatt på pension'), BigDecimal::of($premium)->multipliedBy($taxBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::EMPLOYER, false, false,
            ruleVersionId: $context->rules->version('special_payroll_tax.pension')->id,
            explanation: ['premium_ore' => $premium, 'bp' => $taxBp]));
    }
}
