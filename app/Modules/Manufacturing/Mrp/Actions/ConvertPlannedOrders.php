<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Actions\Purchasing\CreatePurchaseOrdersFromProposal;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Mrp\Models\MrpPlannedOrder;
use App\Modules\Manufacturing\Mrp\Models\MrpRun;
use App\Modules\Manufacturing\Mrp\PlannedOrderKind;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * "Skapa utkast" (ADR-081 gap 5): the chosen planned orders of the latest
 * run become drafts through the existing paths. Purchases become one draft
 * purchase order per supplier (CreatePurchaseOrdersFromProposal, source
 * MRP), each row expected on the date it is needed; production becomes one
 * draft production order each (SaveProductionOrder), with the release date
 * as planned start and the need date as due date. A planned order whose
 * release date had passed is expected the lead time from today instead.
 *
 * Nothing is sent or released. Needs mrp:run, and the existing actions
 * check purchase-orders:manage and manufacturing:manage themselves. All or
 * nothing: one refused line stops the whole conversion.
 */
class ConvertPlannedOrders
{
    use GuardsInventory;

    public function __construct(
        private readonly CreatePurchaseOrdersFromProposal $purchases,
        private readonly SaveProductionOrder $production,
    ) {}

    /**
     * @param  list<int>  $ids  planned order ids of the latest run
     * @return array{purchase_orders: list<PurchaseOrder>, production_orders: list<ProductionOrder>}
     */
    public function __invoke(User $user, Company $company, array $ids): array
    {
        $this->guard($user, $company, 'planRun');

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ValidationException::withMessages(['selected' => __('Välj minst en planerad order.')]);
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $ids): array {
            $planned = MrpPlannedOrder::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($planned->count() !== count($ids)) {
                throw (new ModelNotFoundException)->setModel(MrpPlannedOrder::class, array_values(array_diff($ids, $planned->modelKeys())));
            }

            $latest = MrpRun::query()->latest('id')->value('id');

            foreach ($planned as $order) {
                if ($order->mrp_run_id !== $latest) {
                    throw ValidationException::withMessages(['selected' => __('Planen har ersatts av en nyare körning. Välj bland de planerade ordrarna i den senaste.')]);
                }

                if ($order->isConverted()) {
                    throw ValidationException::withMessages(['selected' => __('En av de valda ordrarna är redan ett utkast.')]);
                }
            }

            $articles = Article::query()->with('stockUnit:id,code')->whereKey($planned->pluck('article_id')->unique()->all())->get(['id', 'number', 'name', 'unit', 'stock_unit_id', 'lead_time_days', 'default_supplier_id'])->keyBy('id');
            $today = CarbonImmutable::today();
            $purchase = $planned->filter(fn (MrpPlannedOrder $order): bool => $order->kind === PlannedOrderKind::Purchase)->values();
            $purchaseOrders = [];
            $productionOrders = [];

            if ($purchase->isNotEmpty()) {
                $purchaseOrders = ($this->purchases)($user, $company, array_values($purchase->map(fn (MrpPlannedOrder $order): array => [
                    'article_id' => $order->article_id,
                    'quantity' => Quantity::decimal($order->quantity_milli),
                    'unit_id' => $articles->get($order->article_id)?->stock_unit_id,
                    'supplier_id' => $order->supplier_id,
                    'expected_on' => self::arrives($order, $articles->get($order->article_id), $today),
                ])->all()), PurchaseOrderSource::Mrp);

                $bySupplier = collect($purchaseOrders)->keyBy('supplier_id');
                $madePurchase = [];

                foreach ($purchase as $order) {
                    // The same supplier choice as CreatePurchaseOrdersFromProposal: the planned one, else the article's default.
                    $supplierId = $order->supplier_id ?? $articles->get($order->article_id)?->default_supplier_id;
                    $madePurchase[$order->id] = ($bySupplier->get($supplierId) ?? throw new LogicException('A purchase order for the supplier was not created.'))->id;
                }

                $this->mark(MrpPlannedOrder::PURCHASE_ORDER, $madePurchase, $user, $planned);
            }

            $madeProduction = [];

            foreach ($planned as $order) {
                if ($order->kind !== PlannedOrderKind::Production) {
                    continue;
                }

                $article = $articles->get($order->article_id);
                $made = $this->production->create($user, $company, [
                    'article_id' => $order->article_id,
                    'quantity' => Quantity::decimal($order->quantity_milli),
                    'unit' => $article?->stockUnitCode() ?? '',
                    'planned_start_on' => $order->release_on->toDateString(),
                    'due_on' => self::arrives($order, $article, $today),
                ]);
                $productionOrders[] = $made;
                $madeProduction[$order->id] = $made->id;
            }

            $this->mark(MrpPlannedOrder::PRODUCTION_ORDER, $madeProduction, $user, $planned);

            Audit::record('mrp.planned_orders_converted', $planned->first(), [
                'mrp_run_id' => $latest,
                'planned_order_ids' => $planned->modelKeys(),
                'purchase_order_ids' => array_map(fn (PurchaseOrder $order): int => $order->id, $purchaseOrders),
                'production_order_ids' => array_map(fn (ProductionOrder $order): int => $order->id, $productionOrders),
            ], $company, $user);

            return ['purchase_orders' => $purchaseOrders, 'production_orders' => $productionOrders];
        }));
    }

    /** When the order can be in: the date it is needed, or the lead time from its release when that is later. */
    private static function arrives(MrpPlannedOrder $order, ?Article $article, CarbonImmutable $today): string
    {
        $earliest = $order->release_on->addDays((int) $article?->lead_time_days);
        $due = $order->due_on->lessThan($today) ? $today : $order->due_on;

        return ($earliest->greaterThan($due) ? $earliest : $due)->toDateString();
    }

    /**
     * Records the drafts on the planned orders of one kind in one statement,
     * however many there are: an upsert on the id of rows that exist and are
     * locked in this transaction, so it only ever updates (each row keeps its
     * own company_id, read inside the company). Parameterised throughout.
     *
     * @param  array<int, int>  $made  planned order id => draft id
     * @param  Collection<int, MrpPlannedOrder>  $planned
     */
    private function mark(string $type, array $made, User $user, Collection $planned): void
    {
        if ($made === []) {
            return;
        }

        $now = now();

        MrpPlannedOrder::query()->upsert(array_values($planned->filter(fn (MrpPlannedOrder $order): bool => isset($made[$order->id]))->map(fn (MrpPlannedOrder $order): array => [
            'id' => $order->id,
            'company_id' => $order->company_id,
            'mrp_run_id' => $order->mrp_run_id,
            'article_id' => $order->article_id,
            'kind' => $order->kind->value,
            'quantity_milli' => $order->quantity_milli,
            'release_on' => $order->release_on->toDateString(),
            'due_on' => $order->due_on->toDateString(),
            'converted_type' => $type,
            'converted_id' => $made[$order->id],
            'converted_at' => $now,
            'converted_by' => $user->id,
        ])->all()), ['id'], ['converted_type', 'converted_id', 'converted_at', 'converted_by', 'updated_at']);
    }
}
