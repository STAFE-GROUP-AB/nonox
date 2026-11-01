<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Run;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Something another module adds to a payslip's calculation context, such as
 * the collective agreement an employment is bound by (ADR-033). The payroll
 * engine holds no agreement logic: a module that is on prepares its data
 * once per run and adds it to each employment's context.
 */
interface ContextExtension
{
    /**
     * Loads what the run needs in a few queries, before the employments are
     * calculated one by one.
     *
     * @param  Collection<int, Employment>  $employments
     */
    public function prepare(PayrollRun $run, Collection $employments): void;

    /**
     * @throws PayrollBlockedException when the employment cannot be calculated, listed on the run's checklist
     */
    public function extend(PayrollContext $context, PayrollRun $run, Employment $employment): PayrollContext;
}
