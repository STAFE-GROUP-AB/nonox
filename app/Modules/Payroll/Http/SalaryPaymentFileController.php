<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Models\User;
use App\Modules\Payroll\Actions\SalaryPaymentFile;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Downloads the salary payment file of an approved run, or goes back to the
 * run with what is missing.
 */
class SalaryPaymentFileController
{
    public function __invoke(Request $request, PayrollRun $run, SalaryPaymentFile $file): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $file($user, $run);
        } catch (ValidationException $exception) {
            return redirect()->route('payroll.runs.show', $run)->withErrors(['payment_file' => collect($exception->errors())->flatten()->first()]);
        }

        return response($result['xml'], 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
