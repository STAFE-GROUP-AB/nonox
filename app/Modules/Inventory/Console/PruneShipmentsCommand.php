<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Models\Shipment;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention of shipments (ADR-081 E14), in every company whether or not Lager
 * is on now. A shipped shipment keeps its recipient copy, carrier and tracking
 * number until the end of the seventh calendar year after the year it was
 * shipped, then those three fields are emptied; packages, quantities, batches
 * and serial numbers stay. A cancelled shipment that never left is deleted
 * 90 days after it was cancelled.
 *
 * TODO(legal-verify): seven years assumes the delivery note is räkenskapsinformation (BFL 7 kap. 2 §).
 */
class PruneShipmentsCommand extends Command
{
    public const int CANCELLED_RETENTION_DAYS = 90;

    public const int SHIPPED_RETENTION_YEARS = 7;

    protected $signature = 'inventory:prune-shipments';

    protected $description = 'Gallrar mottagaruppgifter på gamla försändelser och makulerade försändelser.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $cancelledBefore = $now->subDays(self::CANCELLED_RETENTION_DAYS);
        // Shipped in year Y is kept to the end of Y + 7: everything before 1 January of (this year - 7) is past it.
        $shippedBefore = $now->startOfYear()->subYears(self::SHIPPED_RETENTION_YEARS);

        $this->info("Emptying recipient data on shipments sent before {$shippedBefore->toDateString()}...");

        // Retention runs across companies: only rows past their retention are touched.
        $anonymised = CompanyScope::bypass(fn (): int => Shipment::query()
            ->where('status', Shipment::SHIPPED)
            ->where('shipped_at', '<', $shippedBefore)
            ->where(fn ($query) => $query->whereNotNull('ship_to')->orWhereNotNull('carrier')->orWhereNotNull('tracking_number'))
            ->update(['ship_to' => null, 'carrier' => null, 'tracking_number' => null]));

        $deleted = CompanyScope::bypass(fn (): int => (int) Shipment::query()
            ->where('status', Shipment::CANCELLED)
            ->where('updated_at', '<', $cancelledBefore)
            ->delete());

        $this->comment("Emptied {$anonymised} shipments and deleted {$deleted} cancelled shipments.");

        return self::SUCCESS;
    }
}
