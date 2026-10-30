<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Tax\WithholdingTax;
use App\Modules\Payroll\Domain\Units;

/**
 * Step 9: preliminary tax (PAYROLL_MODULE.md B.7). SINK and A-SINK are flat
 * rates on all taxable pay. Otherwise regular pay is taxed by the table and
 * column, or by a jämkning percentage, which then applies to one-off
 * payments too. One-off payments (bonus, retroactive pay) are taxed by the
 * engångsbelopp table on the expected annual income: regular pay for a full
 * year plus this and earlier one-off payments of the year (Skatteverket,
 * "Engångsbelopp"); without regular pay, by the flat rate for that case.
 *
 * TODO(legal-verify): the mandatory withholding when tax information is
 * missing; here such payslips are blocked.
 */
final class WithholdingTaxStep implements Step
{
    public function __construct(private readonly WithholdingTax $tax) {}

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $regular = $draft->taxableRegular();
        $oneOff = $draft->taxableOneOff();

        $flat = match ($context->taxResidence) {
            'NON_RESIDENT_SINK' => $this->tax->flat($regular + $oneOff, 'sink.rate', 'sink', $context->rules),
            'NON_RESIDENT_A_SINK' => $this->tax->flat($regular + $oneOff, 'a_sink.rate', 'a_sink', $context->rules),
            default => null,
        };

        if ($flat !== null) {
            $draft->taxOre = $flat->taxOre;
            $draft->taxExplanation = $flat->explanation;

            return;
        }

        if ($context->taxAdjustmentPercentBp !== null) {
            $result = $this->tax->adjustedPercent($regular + $oneOff, $context->taxAdjustmentPercentBp, $context->rules);
            $draft->taxOre = $result->taxOre;
            $draft->taxExplanation = $result->explanation;

            return;
        }

        if ($context->taxTable === null || $context->taxColumn === null) {
            throw new PayrollBlockedException(__('Skattetabell och kolumn saknas för den anställda.'));
        }

        $regularTax = $this->tax->fromTable($context->paymentDate->year, $context->taxTable, $context->taxColumn, $regular, $context->rules);
        $draft->taxOre = $regularTax->taxOre;
        $draft->taxExplanation = $regularTax->explanation;

        if ($oneOff === 0) {
            return;
        }

        $oneOffTax = $regular <= 0
            ? $this->tax->flat($oneOff, 'tax.one_off_without_regular_salary', 'one_off_without_regular_salary', $context->rules)
            : $this->tax->oneOff($oneOff, $regular * Units::MONTHS_PER_YEAR + $oneOff + ($context->accumulators['one_off_ytd'] ?? 0), $context->taxColumn, $context->rules);

        $draft->taxOre += $oneOffTax->taxOre;
        $draft->taxExplanation = ['regular' => $regularTax->explanation, 'one_off' => [...$oneOffTax->explanation, 'amount_ore' => $oneOff, 'tax_ore' => $oneOffTax->taxOre]];
    }
}
