<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The SRU files of a fiscal year's INK2 as a ZIP, for Skatteverket's file
 * transfer service.
 */
class IncomeTaxSruController
{
    public function __invoke(Request $request, FiscalYear $fiscalYear, IncomeTaxReturns $returns): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $zip = $returns->sruZip($user, $fiscalYear);
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return redirect()->route('accounting.incomeTax', ['ar' => $fiscalYear->id]);
        }

        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="SRU-INK2-'.$fiscalYear->end_date->format('Y').'.zip"',
        ]);
    }
}
