<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\User;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\StockMovement;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The delivery note (följesedel) of a shipment as a PDF (dompdf, ADR-002):
 * recipient, packages with weight and dimensions, and per package the
 * articles with batch or serial number. It carries no prices. A shipment of
 * another company is not found (the company scope).
 */
final class ShipmentPdfController
{
    public function __invoke(Request $request, int $shipment): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_unless($company !== null && Gate::forUser($user)->allows('viewAny', [StockMovement::class, $company]), 403);

        $found = Shipment::query()->with([
            'packages.lines.article:id,number,name,unit,stock_unit_id',
            'packages.lines.article.stockUnit:id,code',
            'packages.lines.lot:id,code,expires_on,kind',
            'customer:id,name,address_line1,address_line2,postal_code,city,country',
            'pickList:id,number,source_label',
            'salesDocument:id,number',
        ])->findOrFail($shipment);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('inventory::pdf.delivery-note', ['shipment' => $found, 'company' => $company])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"foljesedel-{$found->number}.pdf\"",
        ]);
    }
}
