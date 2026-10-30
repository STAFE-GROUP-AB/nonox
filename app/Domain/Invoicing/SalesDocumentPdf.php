<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Support\Branding\Brand;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A quote (Offert) or an order confirmation (Orderbekräftelse) as a PDF, in
 * the same layout as invoices (ADR-002).
 */
final class SalesDocumentPdf
{
    public static function render(SalesDocument $document): string
    {
        $document->loadMissing(['rows', 'customer']);
        $company = Company::query()->findOrFail($document->company_id);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('invoicing.sales-pdf', [
            'document' => $document,
            'company' => $company,
            'brand' => Brand::for($company),
            'totals' => InvoiceCalculator::totals($document->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function filename(SalesDocument $document): string
    {
        return ($document->isQuote() ? 'Offert' : 'Orderbekraftelse')."-{$document->number}.pdf";
    }
}
