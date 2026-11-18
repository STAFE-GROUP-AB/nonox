<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\ShipmentPackageLine;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a shipment may hold and how much of it is packed (ADR-081 E14). The
 * goods of a shipment are what its pick list took out of stock, per article
 * and batch or serial number, read from the PICK movements of the list (a
 * tracked article without a chosen batch was split in FEFO order by the
 * ledger, so the movements are the truth). Every read is one query, whatever
 * the number of packages.
 */
final class ShipmentContents
{
    public static function key(int $articleId, ?int $lotId): string
    {
        return $articleId.':'.($lotId ?? 0);
    }

    /**
     * @return array<string, array{article_id: int, lot_id: ?int, milli: int}>
     */
    public static function picked(PickList $list): array
    {
        $rows = StockMovement::query()
            ->where('source_type', $list->getMorphClass())
            ->where('source_id', (string) $list->getKey())
            ->where('type', MovementType::Pick->value)
            ->select('article_id', 'lot_id', DB::raw('SUM(-quantity_milli) as milli'))
            ->groupBy('article_id', 'lot_id')
            ->orderBy('article_id')->orderBy('lot_id')
            ->get();

        $picked = [];

        foreach ($rows as $row) {
            $lotId = $row->lot_id === null ? null : (int) $row->lot_id;
            $milli = (int) $row->getAttribute('milli');

            if ($milli > 0) {
                $picked[self::key((int) $row->article_id, $lotId)] = ['article_id' => (int) $row->article_id, 'lot_id' => $lotId, 'milli' => $milli];
            }
        }

        return $picked;
    }

    /**
     * @return array<string, int> thousandths packed so far per article and lot
     */
    public static function packed(Shipment $shipment): array
    {
        $rows = ShipmentPackageLine::query()
            ->join('shipment_packages', 'shipment_packages.id', '=', 'shipment_package_lines.shipment_package_id')
            ->where('shipment_packages.shipment_id', $shipment->getKey())
            ->select('shipment_package_lines.article_id', 'shipment_package_lines.lot_id', DB::raw('SUM(shipment_package_lines.quantity_milli) as milli'))
            ->groupBy('shipment_package_lines.article_id', 'shipment_package_lines.lot_id')
            ->get();

        $packed = [];

        foreach ($rows as $row) {
            $packed[self::key((int) $row->article_id, $row->lot_id === null ? null : (int) $row->lot_id)] = (int) $row->getAttribute('milli');
        }

        return $packed;
    }

    /**
     * What is left to pack, per article and lot.
     *
     * @return array<string, array{article_id: int, lot_id: ?int, milli: int}>
     */
    public static function remaining(Shipment $shipment): array
    {
        $packed = self::packed($shipment);
        $remaining = [];

        foreach (self::picked($shipment->pickList()->firstOrFail()) as $key => $item) {
            $left = $item['milli'] - ($packed[$key] ?? 0);

            if ($left > 0) {
                $remaining[$key] = [...$item, 'milli' => $left];
            }
        }

        return $remaining;
    }

    /**
     * Ordered and shipped per article on an order: the rows of the order are
     * delivered by what shipped shipments carry, per article (an order row
     * has no row of its own on a pick list, which is made per article).
     *
     * @return array<int, array{article_id: int, ordered_milli: int, delivered_milli: int}>
     */
    public static function delivery(SalesDocument $order): array
    {
        $ordered = [];

        foreach (SalesDocumentRow::query()->where('sales_document_id', $order->getKey())->whereNotNull('article_id')->get(['article_id', 'quantity']) as $row) {
            try {
                $ordered[(int) $row->article_id] = ($ordered[(int) $row->article_id] ?? 0) + Quantity::parse((string) $row->quantity);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $shipped = ShipmentPackageLine::query()
            ->join('shipment_packages', 'shipment_packages.id', '=', 'shipment_package_lines.shipment_package_id')
            ->join('shipments', 'shipments.id', '=', 'shipment_packages.shipment_id')
            ->where('shipments.sales_document_id', $order->getKey())
            ->where('shipments.status', Shipment::SHIPPED)
            ->select('shipment_package_lines.article_id', DB::raw('SUM(shipment_package_lines.quantity_milli) as milli'))
            ->groupBy('shipment_package_lines.article_id')
            ->pluck('milli', 'article_id');

        $rows = [];

        foreach ($ordered as $articleId => $milli) {
            $rows[$articleId] = ['article_id' => $articleId, 'ordered_milli' => $milli, 'delivered_milli' => (int) ($shipped[$articleId] ?? 0)];
        }

        return $rows;
    }
}
