<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Domain\PlanningMethod;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Planning\ForecastDemand;
use App\Modules\Inventory\Purchasing\OpenCustomerOrderDemand;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads what one planning run needs (ADR-081 gap 5) inside one company, in
 * a fixed number of queries whatever the number of articles and orders:
 *
 * - the active stocked articles with planning method MRP, what is on hand,
 *   and, when Tillverkning is on, the bill of materials valid today of
 *   those that have one (they are made; the others are bought);
 * - open customer orders per row, dated by the delivery date confirmed to
 *   the customer, else the order's delivery date, else its date
 *   (OpenCustomerOrderDemand, the same reading as the purchase proposal);
 * - the forecast as weekly buckets (ForecastDemand, E15);
 * - open purchase order rows (draft, sent, partly received), what is still
 *   to come, dated by the row's expected date, else the order's requested
 *   delivery date, else the order date plus the article's lead time;
 * - open production orders (draft, released, started): their article as a
 *   receipt on the finish the production plan forecasts, else their due
 *   date, else their start plus the lead time; and what their components
 *   still need, on the start the plan forecasts, else their planned start,
 *   else their due date less the lead time, else today.
 *
 * Draft orders count, as in the purchase proposal: a planned order a person
 * has turned into a draft is not proposed again.
 */
final class MrpSnapshot
{
    public function __construct(
        private readonly ForecastDemand $forecast,
        private readonly OpenCustomerOrderDemand $customerOrders,
        private readonly ModuleManager $modules,
    ) {}

    public function read(Company $company, CarbonImmutable $today, int $weeks): MrpInput
    {
        return CurrentCompany::run($company, function () use ($company, $today, $weeks): MrpInput {
            $today = $today->startOfDay();
            $weeks = max(1, min($weeks, ForecastPeriodKind::Week->maxCount()));
            $firstMonday = ForecastPeriodKind::Week->startOf($today);

            $articles = Article::query()
                ->where('is_stock_item', true)
                ->where('is_active', true)
                ->where('planning_method', PlanningMethod::Mrp->value)
                ->orderBy('id')
                ->get(['id', 'safety_stock_milli', 'lead_time_days', 'lot_minimum_milli', 'lot_multiple_milli', 'default_supplier_id']);

            $ids = array_values(array_map(fn (mixed $id): int => (int) $id, $articles->modelKeys()));

            if ($ids === []) {
                return new MrpInput($today, $firstMonday, $weeks, [], [], [], []);
            }

            $boms = $this->modules->isEnabled(ManufacturingModule::KEY, $company) ? $this->boms($ids, $today) : [];
            $onHand = StockLevel::query()->whereIn('article_id', $ids)->groupBy('article_id')->select('article_id', DB::raw('sum(quantity_milli) as milli'))->pluck('milli', 'article_id');
            $leadTimes = [];
            $planned = [];

            foreach ($articles as $article) {
                $leadTimes[$article->id] = (int) $article->lead_time_days;
                $bom = $boms[$article->id] ?? null;
                $planned[$article->id] = new MrpArticle(
                    id: $article->id,
                    kind: $bom === null ? PlannedOrderKind::Purchase : PlannedOrderKind::Production,
                    onHandMilli: (int) ($onHand[$article->id] ?? 0),
                    safetyStockMilli: $article->safety_stock_milli,
                    leadTimeDays: (int) $article->lead_time_days,
                    lotMinimumMilli: $article->lot_minimum_milli,
                    lotMultipleMilli: $article->lot_multiple_milli,
                    supplierId: $article->default_supplier_id === null ? null : (int) $article->default_supplier_id,
                    bom: $bom,
                );
            }

            $demands = [];

            foreach ($this->customerOrders->lines($company) as $line) {
                if (isset($planned[$line['article_id']])) {
                    $demands[] = new MrpDemand($line['article_id'], DemandKind::CustomerOrder, $line['date'], $line['milli'], $line['sales_document_id'], $line['number']);
                }
            }

            $receipts = $this->purchaseReceipts($ids, $leadTimes, $today);
            [$made, $components] = $this->production($ids, $today);
            array_push($receipts, ...$made);
            array_push($demands, ...$components);

            return new MrpInput($today, $firstMonday, $weeks, $planned, $demands, $this->forecast->weekly($ids, $firstMonday, $weeks), $receipts);
        });
    }

    /**
     * The bill of materials valid today per made article: the latest active
     * version from today or earlier, as SaveProductionOrder copies it.
     *
     * @param  list<int>  $ids
     * @return array<int, array{yield_milli: int, rows: list<array{component_article_id: int, quantity_milli: int, loss_bp: int}>}>
     */
    private function boms(array $ids, CarbonImmutable $today): array
    {
        $versions = BillOfMaterials::query()
            ->where('kind', StructureKind::Bom)
            ->whereIn('output_article_id', $ids)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $today->toDateString())
            ->orderBy('output_article_id')->orderByDesc('valid_from')->orderByDesc('version')
            ->with(['rows' => fn ($query) => $query->whereNotNull('component_article_id')])
            ->get();

        $boms = [];

        foreach ($versions as $version) {
            if (isset($boms[$version->output_article_id]) || $version->yield_milli <= 0) {
                continue;
            }

            $boms[$version->output_article_id] = [
                'yield_milli' => $version->yield_milli,
                'rows' => array_values($version->rows->map(fn (BomRow $row): array => [
                    'component_article_id' => (int) $row->component_article_id,
                    'quantity_milli' => $row->quantity_milli,
                    'loss_bp' => $row->loss_bp,
                ])->all()),
            ];
        }

        return $boms;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, int>  $leadTimes
     * @return list<MrpReceipt>
     */
    private function purchaseReceipts(array $ids, array $leadTimes, CarbonImmutable $today): array
    {
        $rows = PurchaseOrderRow::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_rows.purchase_order_id')
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereIn('purchase_order_rows.article_id', $ids)
            ->whereColumn('purchase_order_rows.received_milli', '<', 'purchase_order_rows.quantity_milli')
            ->orderBy('purchase_order_rows.id')
            ->get([
                'purchase_order_rows.article_id', 'purchase_order_rows.quantity_milli', 'purchase_order_rows.received_milli', 'purchase_order_rows.expected_on',
                'purchase_orders.id as order_id', 'purchase_orders.number as order_number', 'purchase_orders.requested_delivery_on as requested_on', 'purchase_orders.order_date as ordered_on',
            ]);

        $receipts = [];

        foreach ($rows as $row) {
            $articleId = (int) $row->article_id;
            $date = $row->expected_on?->toDateString()
                ?? self::day($row->getAttribute('requested_on'))
                ?? (self::day($row->getAttribute('ordered_on')) === null ? $today : CarbonImmutable::parse((string) self::day($row->getAttribute('ordered_on'))))->addDays($leadTimes[$articleId] ?? 0)->toDateString();

            $receipts[] = new MrpReceipt($articleId, SupplySource::PurchaseOrder, (int) $row->getAttribute('order_id'), (int) $row->getAttribute('order_number'), $date, $row->quantity_milli - $row->received_milli);
        }

        return $receipts;
    }

    /**
     * Open production orders: their output as receipts of planned articles,
     * and their components' remaining need as demand of planned articles.
     *
     * @param  list<int>  $ids
     * @return array{0: list<MrpReceipt>, 1: list<MrpDemand>}
     */
    private function production(array $ids, CarbonImmutable $today): array
    {
        $open = array_map(fn (ProductionOrderStatus $status): string => $status->value, ProductionOrderStatus::open());
        $orders = ProductionOrder::query()
            ->join('articles', 'articles.id', '=', 'production_orders.article_id')
            ->whereIn('production_orders.status', $open)
            ->where(fn ($query) => $query
                ->whereIn('production_orders.article_id', $ids)
                ->orWhereIn('production_orders.id', ProductionOrderComponent::query()->whereIn('component_article_id', $ids)->select('production_order_id')))
            ->orderBy('production_orders.id')
            ->get(['production_orders.id', 'production_orders.number', 'production_orders.article_id', 'production_orders.quantity_milli', 'production_orders.planned_start_on', 'production_orders.due_on', 'articles.lead_time_days as output_lead_days']);

        if ($orders->isEmpty()) {
            return [[], []];
        }

        $latestRun = ProductionPlanRun::query()->latest('id')->value('id');
        $forecasts = $latestRun === null ? collect() : ProductionPlanForecast::query()
            ->where('run_id', $latestRun)
            ->whereIn('production_order_id', $orders->modelKeys())
            ->get(['production_order_id', 'start_at', 'finish_at'])
            ->keyBy('production_order_id');

        $planned = array_flip($ids);
        $receipts = [];
        $starts = [];

        foreach ($orders as $order) {
            $lead = (int) $order->getAttribute('output_lead_days');
            $forecast = $forecasts->get($order->id);
            $start = $forecast?->start_at?->toDateString()
                ?? $order->planned_start_on?->toDateString()
                ?? $order->due_on?->subDays($lead)->toDateString()
                ?? $today->toDateString();
            $starts[$order->id] = $start;

            if (! isset($planned[$order->article_id])) {
                continue;
            }

            $finish = $forecast?->finish_at?->toDateString()
                ?? $order->due_on?->toDateString()
                ?? CarbonImmutable::parse($start)->addDays($lead)->toDateString();

            $receipts[] = new MrpReceipt($order->article_id, SupplySource::ProductionOrder, $order->id, $order->number, $finish, $order->quantity_milli);
        }

        $components = ProductionOrderComponent::query()
            ->whereIn('production_order_id', $orders->modelKeys())
            ->whereIn('component_article_id', $ids)
            ->whereColumn('issued_milli', '<', 'required_milli')
            ->orderBy('id')
            ->get(['production_order_id', 'component_article_id', 'required_milli', 'issued_milli']);

        $numbers = $orders->pluck('number', 'id');
        $demands = [];

        foreach ($components as $component) {
            $demands[] = new MrpDemand(
                $component->component_article_id,
                DemandKind::ProductionOrder,
                $starts[$component->production_order_id] ?? $today->toDateString(),
                $component->remainingMilli(),
                $component->production_order_id,
                (int) $numbers[$component->production_order_id],
            );
        }

        return [$receipts, $demands];
    }

    private static function day(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
