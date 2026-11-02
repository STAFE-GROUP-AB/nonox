<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Support\Money\Money;

/**
 * Step 13: net pay = cash before tax − tax ± items after tax. It is never
 * negative. Tax never makes it negative (WithholdingTaxStep withholds only
 * from the cash); deductions after tax (an advance, a net deduction) can.
 * The part they could not take becomes a claim on the employee: a line of
 * its own that the salary voucher books on the claim account, so the
 * voucher balances, and the claim is carried to the next payslip, which
 * takes it back from the net pay as far as it reaches (enligt granskning
 * 2026-11-02, juristgranskning återstår, AUD-LON-06, #68).
 *
 * TODO(legal-verify): taking an earlier claim back from later pay is set-off
 * against salary (lag (1970:215) om arbetsgivares kvittningsrätt). The claim
 * comes from a deduction the employer entered (an advance, a net deduction);
 * whether every such claim may be set off without the employee's new
 * consent is for the lawyer.
 */
final class NetPay implements Step
{
    public const string CLAIM_CODE = 'FORDRAN_ANSTALLD';

    /** Calendar-year accumulator: claims made (+) and taken back (−); the sum over all years is what is still owed. */
    public const string CLAIM_KEY = 'negative_net_carry_ore';

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $net = $draft->cashBeforeTax() - $draft->taxOre + $draft->netItems();

        if ($context->employeeClaimOre > 0 && $net > 0) {
            $settled = min($net, $context->employeeClaimOre);
            $this->claimLine($draft, -$settled, __('Avdrag för skuld från tidigare lön'), ['claim_before_ore' => $context->employeeClaimOre, 'settled_ore' => $settled]);
            $net -= $settled;

            if ($settled < $context->employeeClaimOre) {
                $draft->warnings[] = __('Den anställda är fortfarande skyldig :amount från tidigare lön. Det dras från nästa lön.', ['amount' => Money::ofOre($context->employeeClaimOre - $settled)->format()]);
            }
        }

        if ($net < 0) {
            $this->claimLine($draft, -$net, __('Skuld till företaget, dras från nästa lön'), ['created_ore' => -$net]);
            $draft->warnings[] = __('Nettolönen blev negativ. :amount förs över som en fordran på den anställda och dras från nästa lön.', ['amount' => Money::ofOre(-$net)->format()]);
            $net = 0;
        }

        $draft->netOre = $net;
    }

    /**
     * A claim line: positive when the claim arises (it lifts the net pay to
     * zero), negative when it is taken back. The accumulator follows it.
     *
     * @param  array<string, mixed>  $explanation
     */
    private function claimLine(PayslipDraft $draft, int $amount, string $description, array $explanation): void
    {
        $draft->add(new PayslipLineResult(self::CLAIM_CODE, $description, $amount, PayslipLineResult::NET, false, false, explanation: ['source' => 'employee_claim', ...$explanation]));
        $draft->accumulatorDeltas[self::CLAIM_KEY] = ($draft->accumulatorDeltas[self::CLAIM_KEY] ?? 0) + $amount;
    }
}
