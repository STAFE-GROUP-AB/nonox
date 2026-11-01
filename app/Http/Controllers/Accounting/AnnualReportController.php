<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\AnnualReport\AnnualReportDocument;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The annual report of a fiscal year as a page to preview and as a PDF.
 */
class AnnualReportController
{
    public function show(Request $request, FiscalYear $fiscalYear): Response
    {
        [$company, $report] = $this->resolve($request, $fiscalYear);

        // Shown in a frame on the annual report page, so NONOX's own pages may frame it.
        return response(AnnualReportDocument::html($company, $fiscalYear, $report), 200, [
            'Content-Security-Policy' => "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'",
        ]);
    }

    public function pdf(Request $request, FiscalYear $fiscalYear): Response
    {
        [$company, $report] = $this->resolve($request, $fiscalYear);

        return response(AnnualReportDocument::pdf($company, $fiscalYear, $report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.AnnualReportDocument::filename($company, $fiscalYear).'"',
        ]);
    }

    /** @return array{0: Company, 1: AnnualReport} */
    private function resolve(Request $request, FiscalYear $fiscalYear): array
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        abort_unless($user->hasTeamPermission($company, 'fiscal-years:manage') && AnnualReportDocument::supports($company), 403);

        $report = AnnualReport::query()->where('fiscal_year_id', $fiscalYear->id)->first()
            ?? new AnnualReport(['fiscal_year_id' => $fiscalYear->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]);

        return [$company, $report];
    }
}
