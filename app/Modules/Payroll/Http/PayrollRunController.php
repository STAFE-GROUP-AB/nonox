<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * A payroll run's page. Route model binding goes through the company scope,
 * so another company's run answers 404.
 */
class PayrollRunController
{
    public function __invoke(PayrollRun $run): View
    {
        Gate::authorize('view', $run);

        return view('payroll::run', ['run' => $run]);
    }
}
