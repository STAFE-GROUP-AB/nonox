<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Branding\Brand;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The invoice as a PDF, in our own layout (ADR-002: dompdf, pure PHP, no
 * browser needed on the server). Remote resources are never fetched.
 */
final class InvoicePdf
{
    public static function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['rows', 'credits']);
        $company = Company::query()->findOrFail($invoice->company_id);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('invoicing.pdf', [
            'invoice' => $invoice,
            'company' => $company,
            'brand' => Brand::for($company),
            'totals' => InvoiceCalculator::totals($invoice->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->values()->all()),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function filename(Invoice $invoice): string
    {
        return ($invoice->isCredit() ? 'Kreditfaktura' : 'Faktura').'-'.($invoice->number ?? 'utkast').'.pdf';
    }
}
