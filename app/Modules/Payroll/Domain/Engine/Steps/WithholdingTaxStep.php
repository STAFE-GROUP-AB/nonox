<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Tax\WithholdingTax;
use App\Modules\Payroll\Domain\Units;
use App\Support\Money\Money;

/**
 * Step 9: preliminary tax (PAYROLL_MODULE.md B.7). SINK and A-SINK are flat
 * rates on all taxable pay. Otherwise regular pay is taxed by the table and
 * column, or by a jämkning percentage, which then applies to one-off
 * payments too. One-off payments (bonus, retroactive pay) are taxed by the
 * engångsbelopp table on the expected annual income: regular pay for a full
 * year plus this and earlier one-off payments of the year (Skatteverket,
 * "Engångsbelopp"); without regular pay, by the flat rate for that case.
 *
 * Tax is withheld only from the cash paid (SFL 10 kap. 3 §): a benefit is
 * part of the basis (SFL 11 kap. 4 §), but when the table tax is larger than
 * the cash before tax, only the cash is withheld. The rest is not a claim on
 * the employee; it is the employee's own tax, paid with the final tax, and
 * the payslip says so (enligt granskning 2026-11-02, juristgranskning
 * återstår, AUD-LON-06, #68).
 *
 * TODO(legal-verify): the mandatory withholding when tax information is
 * missing; here such payslips are blocked.
 * TODO(legal-verify): whether tax-free cash (traktamente, milersättning) may
 * be used to withhold tax on other pay; here it counts as cash (AUD-LON-06).
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
            $this->withholdFromCash($draft);

            return;
        }

        if ($context->taxAdjustmentPercentBp !== null) {
            $result = $this->tax->adjustedPercent($regular + $oneOff, $context->taxAdjustmentPercentBp, $context->rules);
            $draft->taxOre = $result->taxOre;
            $draft->taxExplanation = $result->explanation;
            $this->withholdFromCash($draft);

            return;
        }

        if ($context->taxTable === null || $context->taxColumn === null) {
            throw new PayrollBlockedException(__('Skattetabell och kolumn saknas för den anställda.'));
        }

        $regularTax = $this->tax->fromTable($context->paymentDate->year, $context->taxTable, $context->taxColumn, $regular, $context->rules);
        $draft->taxOre = $regularTax->taxOre;
        $draft->taxExplanation = $regularTax->explanation;

        if ($oneOff === 0) {
            $this->withholdFromCash($draft);

            return;
        }

        $oneOffTax = $regular <= 0
            ? $this->tax->flat($oneOff, 'tax.one_off_without_regular_salary', 'one_off_without_regular_salary', $context->rules)
            : $this->tax->oneOff($oneOff, $regular * Units::MONTHS_PER_YEAR + $oneOff + ($context->accumulators['one_off_ytd'] ?? 0), $context->taxColumn, $context->rules);

        $draft->taxOre += $oneOffTax->taxOre;
        $draft->taxExplanation = ['regular' => $regularTax->explanation, 'one_off' => [...$oneOffTax->explanation, 'amount_ore' => $oneOff, 'tax_ore' => $oneOffTax->taxOre]];
        $this->withholdFromCash($draft);
    }

    /**
     * Keeps the withheld tax within the cash paid before tax. What could not
     * be withheld is stated on the payslip and in the explanation; it is not
     * declared as withheld and not carried as a claim.
     */
    private function withholdFromCash(PayslipDraft $draft): void
    {
        $cash = max($draft->cashBeforeTax(), 0);

        if ($draft->taxOre <= $cash) {
            return;
        }

        $calculated = $draft->taxOre;
        $draft->taxOre = $cash;
        $draft->taxExplanation = [...$draft->taxExplanation, 'calculated_tax_ore' => $calculated, 'cash_ore' => $cash, 'not_withheld_ore' => $calculated - $cash];
        $draft->warnings[] = __('Skatten blev :calculated men kontantlönen är :cash. Bara :cash dras i skatt. Resten, :rest, dras inte och är ingen skuld till företaget: den anställda betalar den själv med slutskatten.', [
            'calculated' => Money::ofOre($calculated)->format(),
            'cash' => Money::ofOre($cash)->format(),
            'rest' => Money::ofOre($calculated - $cash)->format(),
        ]);
    }
}
