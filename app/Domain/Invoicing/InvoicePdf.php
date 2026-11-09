<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Branding\Brand;
use Dompdf\Dompdf;
use Dompdf\Options;
use LogicException;

/**
 * The invoice as a PDF, in our own layout (ADR-002: dompdf, pure PHP, no
 * browser needed on the server). Remote resources are never fetched.
 *
 * An invoice in another currency shows its rows and totals in that currency
 * and, below them, the VAT and the total in kronor at the booking rate, with
 * the rate and where it came from.
 *
 * TODO(legal-verify): Council Directive 2006/112/EC art. 230 lets the
 * invoice be in any currency provided the VAT payable is expressed in the
 * national currency, converted as in art. 91. Confirm the matching rule in
 * Mervärdesskattelag (2023:200) 17 kap. and whether the kronor total is
 * also required.
 */
final class InvoicePdf
{
    public static function render(Invoice $invoice): string
    {
        // ADR-076: we do not own the original of an invoice read in from another program, and make no look-alike.
        if ($invoice->isImported()) {
            throw new LogicException('An imported invoice has no NONOX PDF.');
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($invoice));
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** The HTML the PDF is drawn from. */
    public static function html(Invoice $invoice): string
    {
        $invoice->loadMissing(['rows', 'credits']);
        $company = Company::query()->findOrFail($invoice->company_id);
        $rows = fn (bool $foreign): array => $invoice->rows
            ->map(fn ($row): array => ['net' => $foreign ? (int) $row->foreign_net_minor : $row->net_ore->ore, 'vat_rate' => $row->vat_rate])
            ->values()->all();

        return view('invoicing.pdf', [
            'invoice' => $invoice,
            'company' => $company,
            'brand' => Brand::for($company),
            'totals' => $invoice->isForeign() ? InvoiceCalculator::totals($rows(true), roundToKronor: false) : InvoiceCalculator::totals($rows(false)),
            'kronor' => $invoice->isForeign() ? InvoiceCalculator::totals($rows(false), roundToKronor: false) : null,
        ])->render();
    }

    public static function filename(Invoice $invoice): string
    {
        return ($invoice->isCredit() ? 'Kreditfaktura' : 'Faktura').'-'.($invoice->number ?? 'utkast').'.pdf';
    }
}
