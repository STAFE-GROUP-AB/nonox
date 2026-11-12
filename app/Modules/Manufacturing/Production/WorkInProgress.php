<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Production;

use App\Models\Company;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderIssue;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Work in progress on a day (ADR-071 §8): every production order not yet
 * finished or cancelled on that day, valued at the components it had
 * taken out of stock (at their valuation cost) plus the labour reported on
 * it up to that day, plus the overhead the order took when it started (the
 * company's choice then, so a later change does not revalue past days). Built from the append-only issue and labour ledgers,
 * so it can be run for any past day. Three queries whatever the number of
 * orders.
 *
 * TODO(legal-verify): K2 (BFNAR 2016:10 ch. 12) and K3 (ch. 13) on which
 * costs the work in progress may or must include (direct labour, indirect
 * costs); whether ÅRL 4:9 accepts the operation's standard hourly cost as
 * an approximation of the actual cost; and lägsta värdets princip for work
 * in progress, which is not applied here.
 */
final class WorkInProgress
{
    /**
     * @param  list<array{order_id: int, reference: string, article_id: int, name: string, material_ore: int, labour_ore: int, overhead_ore: int, value_ore: int}>  $lines
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly array $lines,
        public readonly int $valueOre,
    ) {}

    public static function at(Company $company, CarbonImmutable $date): self
    {
        $day = $date->toDateString();

        return CurrentCompany::run($company, function () use ($date, $day): self {
            $material = ProductionOrderIssue::query()->whereDate('issued_on', '<=', $day)
                ->groupBy('production_order_id')->selectRaw('production_order_id, sum(value_ore) as ore')
                ->pluck('ore', 'production_order_id');
            $labour = ProductionLabourEntry::query()->whereDate('worked_on', '<=', $day)
                ->groupBy('production_order_id')->selectRaw('production_order_id, sum(cost_ore) as ore')
                ->pluck('ore', 'production_order_id');
            $ids = array_unique(array_merge($material->keys()->all(), $labour->keys()->all()));

            if ($ids === []) {
                return new self($date, [], 0);
            }

            $orders = ProductionOrder::query()
                ->with('article:id,name')
                ->whereKey($ids)
                ->where(fn ($query) => $query->whereNull('finished_on')->orWhereDate('finished_on', '>', $day))
                ->where(fn ($query) => $query->whereNull('cancelled_on')->orWhereDate('cancelled_on', '>', $day))
                ->orderBy('number')
                ->get(['id', 'number', 'article_id', 'overhead_bp']);
            $lines = [];
            $total = 0;

            foreach ($orders as $order) {
                $orderMaterial = (int) ($material[$order->id] ?? 0);
                $orderLabour = (int) ($labour[$order->id] ?? 0);
                $overhead = ProductionMath::overhead($orderMaterial, $orderLabour, (int) $order->overhead_bp);
                $value = $orderMaterial + $orderLabour + $overhead;

                if ($value === 0) {
                    continue;
                }

                $lines[] = [
                    'order_id' => $order->id,
                    'reference' => $order->reference(),
                    'article_id' => $order->article_id,
                    'name' => (string) $order->article?->name,
                    'material_ore' => $orderMaterial,
                    'labour_ore' => $orderLabour,
                    'overhead_ore' => $overhead,
                    'value_ore' => $value,
                ];
                $total += $value;
            }

            return new self($date, $lines, $total);
        });
    }
}
