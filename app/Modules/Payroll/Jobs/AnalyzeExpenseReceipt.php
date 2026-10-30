<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Jobs;

use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Models\Expense;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Lets NONOX AI read the receipt of an outlay sent without details, in the
 * background. Runs once: a failed reading leaves the outlay for the person
 * who approves it to fill in, or to have read again.
 */
class AnalyzeExpenseReceipt implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use RunsForCompany;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $companyId, public int $expenseId) {}

    public function handle(ExpenseClaims $claims): void
    {
        $expense = Expense::query()->find($this->expenseId);

        if ($expense === null) {
            return;
        }

        $claims->readReceipt($expense);
    }
}
