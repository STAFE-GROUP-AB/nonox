<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The control visit report as a PDF, with dompdf as for payslips and
 * invoices (ADR-002): who is checked in now, the shifts of the period and
 * every entry with its corrections.
 */
final class PdfExport
{
    public static function render(ControlVisitReport $report): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('staff-ledger::pdf', ['report' => $report])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }
}
