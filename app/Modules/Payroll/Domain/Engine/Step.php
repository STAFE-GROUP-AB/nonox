<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * One step of the payroll calculation (PAYROLL_MODULE.md B.9). Pure: it
 * reads the context and changes only the draft.
 */
interface Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void;
}
