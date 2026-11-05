<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\IncomeTax\BusinessIncomeTaxReturns;
use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The SRU files of a fiscal year's income tax return as a ZIP: INK2 for a
 * limited company, the NE-bilaga for a sole trader, INK4 for a handelsbolag
 * (ADR-053). The fiscal year is resolved within the current company, so
 * another company's year answers 404.
 */
class IncomeTaxSruController
{
    public function __invoke(Request $request, FiscalYear $fiscalYear, IncomeTaxReturns $returns, BusinessIncomeTaxReturns $business): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $form = match (Company::query()->findOrFail($fiscalYear->company_id)->legal_form) {
            LegalForm::Ef => 'NE',
            LegalForm::Hb, LegalForm::Kb => 'INK4',
            default => 'INK2',
        };

        try {
            $zip = $form === 'INK2' ? $returns->sruZip($user, $fiscalYear) : $business->sruZip($user, $fiscalYear);
        } catch (ValidationException $exception) {
            Toast::flash((string) collect($exception->errors())->flatten()->first(), 'error');

            return redirect()->route('accounting.incomeTax', ['ar' => $fiscalYear->id]);
        }

        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="SRU-'.$form.'-'.$fiscalYear->end_date->format('Y').'.zip"',
        ]);
    }
}
