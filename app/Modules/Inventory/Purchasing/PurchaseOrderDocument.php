<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Models\Company;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Support\Branding\Brand;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The supplier's copy of a purchase order (ADR-071 §2, §12.5): a snapshot
 * of the order as it is (kept per sent version) and the PDF made from it in
 * the company's design (ADR-030). The supplier sees the row's own unit and
 * their own article number, never the stock unit.
 */
final class PurchaseOrderDocument
{
    /**
     * The order as the supplier will see it. Runs inside the company.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(PurchaseOrder $order): array
    {
        $order->loadMissing(['rows.unit:id,code', 'rows.article:id,number', 'supplier']);

        return [
            'number' => $order->number,
            'version' => $order->version,
            'order_date' => $order->order_date->toDateString(),
            'requested_delivery_on' => $order->requested_delivery_on?->toDateString(),
            'our_reference' => $order->our_reference,
            'delivery_address' => $order->delivery_address,
            'notes' => $order->notes,
            'supplier' => $order->supplier?->snapshot() ?? [],
            'rows' => $order->rows->map(fn (PurchaseOrderRow $row): array => [
                'article_number' => $row->article?->number,
                'supplier_article_number' => $row->supplier_article_number,
                'description' => $row->description,
                'quantity' => $row->entered_quantity,
                'unit' => $row->unit?->code,
                'price_ore' => $row->price_ore,
                'value_ore' => $row->valueOre(),
                'expected_on' => $row->expected_on?->toDateString(),
            ])->values()->all(),
            'total_ore' => $order->totalOre(),
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function render(Company $company, array $snapshot): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('inventory::pdf.purchase-order', [
            'order' => $snapshot,
            'company' => $company,
            'brand' => Brand::for($company),
            'quantity' => fn (string $decimal): string => Quantity::format(Quantity::parse($decimal)),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function filename(array $snapshot): string
    {
        $version = (int) ($snapshot['version'] ?? 1);

        return 'Inkopsorder-'.$snapshot['number'].($version > 1 ? "-version-{$version}" : '').'.pdf';
    }
}
