<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\K3AnnualReportDocument;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Ixbrl\IxbrlRefused;
use App\Support\Ixbrl\K2AnnualReportIxbrl;
use App\Support\Toast;
use App\Support\WorkQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The annual report of a fiscal year as a page to preview, as a PDF and,
 * for an aktiebolag on K2, as an iXBRL file for digital filing (Beta,
 * ADR-058). A K3 year gets the K3 document (Beta, ADR-059 S1-P10): the
 * preview while nothing refuses it, the PDF once every question is answered.
 */
class AnnualReportController
{
    public function show(Request $request, FiscalYear $fiscalYear): Response
    {
        [$company, $report] = $this->resolve($request, $fiscalYear);

        if (K3AnnualReportDocument::supports($company, $fiscalYear)) {
            $document = K3AnnualReportDocument::for($company, $fiscalYear, $report);

            // A refused K3 report has no document; the annual report page lists the reasons.
            abort_if($document->refusals() !== [], 404);

            return $this->frameable($document->html());
        }

        return $this->frameable(AnnualReportDocument::html($company, $fiscalYear, $report));
    }

    /** Shown in a frame on the annual report page, so NONOX's own pages may frame it. */
    private function frameable(string $html): Response
    {
        return response($html, 200, [
            'Content-Security-Policy' => "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'",
        ]);
    }

    public function pdf(Request $request, FiscalYear $fiscalYear): Response|RedirectResponse
    {
        [$company, $report] = $this->resolve($request, $fiscalYear);

        if (! WorkQuota::attempt($company, 'pdf')) {
            Toast::flash(WorkQuota::message(), 'error');

            return back();
        }

        if (K3AnnualReportDocument::supports($company, $fiscalYear)) {
            $document = K3AnnualReportDocument::for($company, $fiscalYear, $report);

            if (! $document->complete()) {
                Toast::flash(__('Årsredovisningen enligt K3 kan laddas ner som PDF när alla frågor är besvarade och inget hindrar den. Se listan på sidan Årsredovisning.'), 'error');

                return back();
            }

            return response($document->pdf(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.K3AnnualReportDocument::filename($company, $fiscalYear).'"',
            ]);
        }

        return response(AnnualReportDocument::pdf($company, $fiscalYear, $report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.AnnualReportDocument::filename($company, $fiscalYear).'"',
        ]);
    }

    /**
     * The same report as an Inline XBRL file (XHTML). NONOX does not file
     * it; when it cannot be made, the user is told what is missing.
     */
    public function ixbrl(Request $request, FiscalYear $fiscalYear): Response|RedirectResponse
    {
        [$company, $report] = $this->resolve($request, $fiscalYear);
        // K3 iXBRL is not built (ADR-059 F.4); the K3 document is HTML and PDF only.
        abort_unless(AnnualReportDocument::supports($company, $fiscalYear), 403);

        try {
            $xhtml = K2AnnualReportIxbrl::render($company, $fiscalYear, $report);
        } catch (IxbrlRefused $refused) {
            Toast::flash($refused->getMessage(), 'error');

            return back();
        }

        return response($xhtml, 200, [
            'Content-Type' => 'application/xhtml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.K2AnnualReportIxbrl::filename($company, $fiscalYear).'"',
        ]);
    }

    /** @return array{0: Company, 1: AnnualReport} */
    private function resolve(Request $request, FiscalYear $fiscalYear): array
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        abort_unless($user->hasTeamPermission($company, 'fiscal-years:manage'), 403);
        // The K2 document, or for a K3 year the K3 one (ADR-059 S1-P10); anything else is refused.
        abort_unless(AnnualReportDocument::supports($company, $fiscalYear) || K3AnnualReportDocument::supports($company, $fiscalYear), 403);

        $report = AnnualReport::query()->where('fiscal_year_id', $fiscalYear->id)->first()
            ?? new AnnualReport(['fiscal_year_id' => $fiscalYear->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]);

        return [$company, $report];
    }
}
