<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Tax\WithholdingTax;

/**
 * Step 9: preliminary tax (PAYROLL_MODULE.md B.7). SINK is a flat rate; a
 * jämkning percentage replaces the table; otherwise the table and column.
 *
 * TODO(legal-verify): one-off payments need the expected annual income as
 * Skatteverket defines it, A-SINK and the mandatory withholding when tax
 * information is missing. Until verified these payslips are blocked.
 */
final class WithholdingTaxStep implements Step
{
    public function __construct(private readonly WithholdingTax $tax) {}

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $regular = $draft->taxableRegular();
        $oneOff = $draft->taxableOneOff();

        if ($context->taxResidence === 'NON_RESIDENT_SINK') {
            $result = $this->tax->sink($regular + $oneOff, $context->rules);
            $draft->taxOre = $result->taxOre;
            $draft->taxExplanation = $result->explanation;

            return;
        }

        if ($context->taxResidence !== 'SE') {
            throw new PayrollBlockedException(__('Skatteavdrag för A-SINK stöds inte än.'));
        }

        if ($oneOff !== 0) {
            throw new PayrollBlockedException(__('Skatt på engångsbelopp stöds inte än. Betala ut beloppet som vanlig lön eller vänta på nästa version.'));
        }

        if ($context->taxAdjustmentPercentBp === null && ($context->taxTable === null || $context->taxColumn === null)) {
            throw new PayrollBlockedException(__('Skattetabell och kolumn saknas för den anställda.'));
        }

        $result = $context->taxAdjustmentPercentBp !== null
            ? $this->tax->adjustedPercent($regular, $context->taxAdjustmentPercentBp, $context->rules)
            : $this->tax->fromTable($context->paymentDate->year, (int) $context->taxTable, (int) $context->taxColumn, $regular, $context->rules);

        $draft->taxOre = $result->taxOre;
        $draft->taxExplanation = $result->explanation;
    }
}
