<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\LocationTree;
use App\Modules\ModuleManager;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Jobs\PushStockJob;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Stock back to the till (ADR-042): every movement of a linked article
 * becomes one change (delta) to send, never an absolute balance, so a sale
 * at the till in the meantime is never overwritten. Movements that came
 * from the same till's sales are skipped: the till has already taken them
 * off its own balance, and sending them back would loop. With a stock
 * location chosen on the connection, only movements in it (or under it)
 * count; without one, every movement but internal transfers.
 */
class QueueStockPushes
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(StockChanged $event): void
    {
        if (! $this->modules->isEnabled(PosModule::KEY, $event->companyId)) {
            return;
        }

        CurrentCompany::run($event->companyId, function () use ($event): void {
            $connections = PosConnection::query()->where('status', ConnectionStatus::Active->value)->get()
                ->filter(fn (PosConnection $connection): bool => $connection->pushesStock());

            if ($connections->isEmpty()) {
                return;
            }

            $movements = StockMovement::query()->whereKey($event->movementIds)->get();
            $links = PosProductLink::query()->whereIn('pos_connection_id', $connections->modelKeys())->whereIn('article_id', $event->articleIds)->get()->groupBy('pos_connection_id');
            $saleConnections = PosSale::query()
                ->whereKey($movements->where('source_type', (new PosSale)->getMorphClass())->pluck('source_id')->map(fn (?string $id): int => (int) $id)->all())
                ->pluck('pos_connection_id', 'id');
            $locations = StockLocation::query()->whereKey($connections->pluck('location_id')->filter()->unique()->values()->all())->get()->keyBy('id');

            foreach ($connections as $connection) {
                $linked = $links->get($connection->id, collect())->pluck('article_id')->all();
                $location = $connection->location_id === null ? null : $locations->get($connection->location_id);
                $scope = $location instanceof StockLocation ? LocationTree::subtreeIds($location) : null;
                $queued = 0;

                foreach ($movements as $movement) {
                    if (! in_array($movement->article_id, $linked, true)) {
                        continue;
                    }

                    if ($movement->type === MovementType::PosSale && $movement->source_type === (new PosSale)->getMorphClass() && $saleConnections->get((int) $movement->source_id) === $connection->id) {
                        continue;
                    }

                    if ($scope === null ? $movement->type === MovementType::Transfer : ! in_array($movement->location_id, $scope, true)) {
                        continue;
                    }

                    $queued += DB::table('pos_stock_pushes')->insertOrIgnore([
                        'company_id' => $connection->company_id,
                        'pos_connection_id' => $connection->id,
                        'stock_movement_id' => $movement->id,
                        'article_id' => $movement->article_id,
                        'delta_milli' => $movement->quantity_milli,
                        'status' => 'PENDING',
                        'attempts' => 0,
                        'external_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, "nonox:pos:{$connection->id}:movement:{$movement->id}")->toString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($queued > 0) {
                    PushStockJob::dispatch($connection->company_id, $connection->id);
                }
            }
        });
    }
}
