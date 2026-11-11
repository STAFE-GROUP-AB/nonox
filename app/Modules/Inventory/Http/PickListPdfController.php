<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\User;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\StockMovement;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pick list as a PDF to print (dompdf, ADR-002): the lines in walking
 * order with the location code large, and a box to tick per line. A list of
 * another company is not found (the company scope).
 */
final class PickListPdfController
{
    public function __invoke(Request $request, int $pickList): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_unless($company !== null && Gate::forUser($user)->allows('viewAny', [StockMovement::class, $company]), 403);

        $list = PickList::query()->with(['lines.article:id,number,name,unit,gtin,stock_unit_id', 'lines.article.stockUnit:id,code', 'lines.location:id,full_code,name', 'customer:id,name'])->findOrFail($pickList);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('inventory::pdf.pick-list', ['list' => $list, 'company' => $company])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"plocklista-{$list->number}.pdf\"",
        ]);
    }
}
