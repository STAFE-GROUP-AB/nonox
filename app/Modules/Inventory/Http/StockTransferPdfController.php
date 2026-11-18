<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The transfer document (ADR-081 E13) as a PDF to print and follow the goods:
 * from, to, the lines with batch or serial number, and boxes to tick on
 * arrival. No prices. An order of another company is not found.
 */
final class StockTransferPdfController
{
    public function __invoke(Request $request, int $stockTransfer): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_unless($company !== null && Gate::forUser($user)->allows('viewAny', [StockMovement::class, $company]), 403);

        $transfer = StockTransfer::query()->with(['from:id,full_code,name,address_line1,postal_code,city', 'to:id,full_code,name,address_line1,postal_code,city', 'lines.article:id,number,name,unit,stock_unit_id', 'lines.article.stockUnit:id,code', 'lines.lot:id,code,expires_on,kind'])->findOrFail($stockTransfer);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('inventory::pdf.stock-transfer', ['transfer' => $transfer, 'company' => $company])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"flyttorder-{$transfer->number}.pdf\"",
        ]);
    }
}
