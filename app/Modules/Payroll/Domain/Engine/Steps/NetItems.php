<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;

/**
 * Step 10: items after tax: outlay repayments and net additions add,
 * advances, benefit co-payments and other net deductions subtract.
 *
 * TODO(legal-verify): garnishment (utmätning) and its protected amount are
 * not supported.
 */
final class NetItems implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        foreach ($context->inputs as $input) {
            if (! in_array($input->category, Additions::NET_CATEGORIES, true)) {
                continue;
            }

            $amount = abs($input->total());

            $draft->add(new PayslipLineResult(
                $input->code,
                $input->description,
                $input->category === 'NET_DEDUCTION' ? -$amount : $amount,
                PayslipLineResult::NET,
                false,
                false,
                $input->quantity,
                $input->rateOre,
                explanation: ['source' => 'input', 'category' => $input->category],
            ));
        }
    }
}
