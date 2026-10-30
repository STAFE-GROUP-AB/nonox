<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Support\Money\Money;

/**
 * Step 13: net pay = cash before tax − tax ± items after tax. It is never
 * negative: a shortfall is carried forward as a claim on the employee, with
 * a warning.
 */
final class NetPay implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $net = $draft->cashBeforeTax() - $draft->taxOre + $draft->netItems();

        if ($net < 0) {
            $draft->warnings[] = __('Nettolönen blev negativ. :amount förs över som en fordran på den anställda.', ['amount' => Money::ofOre(-$net)->format()]);
            $draft->accumulatorDeltas['negative_net_carry_ore'] = ($draft->accumulatorDeltas['negative_net_carry_ore'] ?? 0) - $net;
            $net = 0;
        }

        $draft->netOre = $net;
    }
}
