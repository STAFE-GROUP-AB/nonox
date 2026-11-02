<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The agreement insurances on a payslip (ADR-033 A10): one line per
 * premium (AGS, TFA, TGL, omställning, TRR...) with a rate above zero, on
 * the same cash pay as the pension premium, capped where the rule says so,
 * and the agreement's own fee (the hängavtal administration fee). Like the
 * pension they are the employer's cost: on the payslip for information,
 * never in gross or net pay, booked on the pay code AVTALSFORSAKRING
 * (7570/2740). Also adds the warnings AgreementPayTerms collected.
 *
 * TODO(legal-verify): the premium basis of each insurance (the rules say
 * "lönesumma"/"premieunderlag"), whether TGL is capped per month, and the
 * ages each premium is paid for.
 */
final class AgreementCostsStep implements Step
{
    public const string PAY_CODE = 'AVTALSFORSAKRING';

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $costs = $context->extension(AgreementCosts::KEY);

        if (! $costs instanceof AgreementCosts) {
            return;
        }

        foreach ($costs->warnings as $warning) {
            $draft->warnings[] = $warning;
        }

        $basis = self::basis($draft);

        if ($basis <= 0) {
            return;
        }

        $age = (int) $context->birthDate->diffInYears($context->periodEnd->endOfMonth());

        foreach ($costs->components as $component) {
            if (($component['from_age'] !== null && $age < $component['from_age']) || ($component['to_age_exclusive'] !== null && $age >= $component['to_age_exclusive'])) {
                continue;
            }

            $premiumBasis = $component['cap_ore'] === null ? $basis : min($basis, $component['cap_ore']);
            $amount = BigDecimal::of($premiumBasis)->multipliedBy($component['bp'])->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();

            if ($amount <= 0) {
                continue;
            }

            $draft->add(new PayslipLineResult(self::PAY_CODE, __('Avtalsförsäkring :name', ['name' => $component['name']]), $amount, PayslipLineResult::EMPLOYER, false, false,
                ruleVersionId: $costs->ruleVersionId,
                explanation: array_filter(['insurance' => $component['key'], 'basis_ore' => $premiumBasis, 'cap_ore' => $component['cap_ore'], 'bp' => $component['bp'], 'agreement' => $costs->explanation], fn (mixed $value): bool => $value !== null)));
        }

        if ($costs->adminFee !== null) {
            $amount = BigDecimal::of($basis)->multipliedBy($costs->adminFee['bp'])->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();

            if ($amount > 0) {
                $draft->add(new PayslipLineResult(self::PAY_CODE, __('Administrationsavgift enligt hängavtal'), $amount, PayslipLineResult::EMPLOYER, false, false,
                    explanation: ['insurance' => 'ADMIN_FEE', 'basis_ore' => $basis, 'bp' => $costs->adminFee['bp'], 'agreement' => [...$costs->explanation, 'source' => $costs->adminFee['source']]]));
            }
        }
    }

    /**
     * The cash pay premiums are taken on: pay that is contribution basis,
     * without benefits and taxable allowances (as the pension basis).
     */
    public static function basis(PayslipDraft $draft): int
    {
        return array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter(
            $draft->lines,
            fn (PayslipLineResult $line): bool => $line->contributionBasis && ! ($line->explanation['benefit'] ?? false) && ($line->explanation['category'] ?? null) !== 'ALLOWANCE_TAXABLE',
        ))));
    }
}
