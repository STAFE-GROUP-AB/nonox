<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;

/**
 * Step 5: hours, overtime, OB, bonus and other inputs that are paid before
 * tax, each taxed as its pay code says. Hourly pay without a rate takes the
 * employment's hourly rate.
 */
final class Additions implements Step
{
    public const array NET_CATEGORIES = ['NET_DEDUCTION', 'NET_ADDITION', 'EXPENSE'];

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        foreach ($context->inputs as $input) {
            if (in_array($input->category, self::NET_CATEGORIES, true) || in_array($input->code, Allowances::codes(), true)) {
                continue;
            }

            $rate = $input->rateOre ?? ($input->quantity !== null && $input->amountOre === null ? $context->hourlyRateOre : null);

            if ($input->amountOre === null && $rate === null) {
                throw new PayrollBlockedException(__(':item saknar belopp eller timpris.', ['item' => $input->description]));
            }
            $priced = new PayInput($input->code, $input->description, $input->category, $input->taxable, $input->taxMethod, $input->contributionBasis, $input->vacationBasis, $input->quantity, $rate, $input->amountOre);

            $draft->add(new PayslipLineResult(
                $input->code,
                $input->description,
                $priced->total(),
                $input->taxable ? ($input->taxMethod === 'ONE_OFF' ? PayslipLineResult::TAX_ONE_OFF : PayslipLineResult::TAX_TABLE) : PayslipLineResult::TAX_NONE,
                $input->contributionBasis,
                $input->vacationBasis,
                $input->quantity,
                $rate,
                explanation: ['source' => 'input', 'category' => $input->category, 'benefit' => $input->category === 'BENEFIT'],
            ));
        }
    }
}
