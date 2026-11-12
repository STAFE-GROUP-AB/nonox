<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Production;

use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Production\ProductionMath;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One production order (ADR-071 §8): the product and quantity, the
 * components with what the order needs, what has been issued, and whether
 * the stock covers it (on hand, reserved by other orders, coming on
 * purchase orders, short), the operations with planned and reported
 * minutes, and the costs: the calculation from the copy against the actual
 * so far. The status actions each say what they do before they do it. A
 * fixed number of queries whatever the number of lines.
 */
class ProductionOrderPage extends Component
{
    use ManufacturingPage;

    public const string COMPONENT = 'component';

    public const string OPERATION = 'operation';

    #[Locked]
    public int $orderId;

    public bool $editing = false;

    /** @var array<string, string> */
    public array $form = [];

    public bool $editingStructure = false;

    /** @var array<string, mixed> */
    public array $structure = [];

    public bool $starting = false;

    public bool $issueOnStart = true;

    public bool $finishing = false;

    /** @var array<string, mixed> */
    public array $finish = [];

    public bool $cancelling = false;

    public string $cancelReason = '';

    public function mount(int $orderId): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->orderId = ProductionOrder::query()->findOrFail($orderId)->id;
    }

    public function edit(): void
    {
        abort_unless($this->can('manage'), 403);
        $order = $this->order();
        $this->form = [
            'quantity' => $order->entered_quantity ?? Quantity::decimal($order->quantity_milli),
            'unit' => $order->entered_quantity === null ? '' : (string) $order->unit?->code,
            'location_id' => (string) $order->location_id,
            'planned_start_on' => (string) $order->planned_start_on?->toDateString(),
            'due_on' => (string) $order->due_on?->toDateString(),
            'notes' => (string) $order->notes,
        ];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function saveDetails(SaveProductionOrder $save): void
    {
        $order = $this->order();
        $input = [
            'location_id' => $this->form['location_id'] ?? '',
            'planned_start_on' => $this->form['planned_start_on'] ?? '',
            'due_on' => $this->form['due_on'] ?? '',
            'notes' => $this->form['notes'] ?? '',
        ];

        if ($order->status === ProductionOrderStatus::Draft) {
            $input += ['quantity' => $this->form['quantity'] ?? '', 'unit' => $this->form['unit'] ?? ''];
        }

        if ($this->attempt(fn () => $save->update($this->user(), $this->company(), $order, $input), __('Tillverkningsordern är sparad.'))) {
            $this->editing = false;

            foreach ($save->warnings as $warning) {
                $this->toast($warning, 'warning');
            }
        }
    }

    public function editStructure(): void
    {
        abort_unless($this->can('manage'), 403);
        $order = $this->order();
        $rows = [];

        foreach ($order->components as $component) {
            $rows[$component->position * 2] = [
                ...self::blankRow(self::COMPONENT),
                'article_id' => (string) $component->component_article_id,
                'quantity' => $component->entered_quantity ?? Quantity::decimal($component->quantity_milli),
                'unit' => (string) ($component->entered_unit ?? ''),
                'loss' => self::percentInput($component->loss_bp),
                'note' => (string) $component->note,
            ];
        }

        foreach ($order->operations as $operation) {
            $rows[$operation->position * 2 + 1] = [...self::blankRow(self::OPERATION), 'operation_id' => (string) $operation->operation_id, 'minutes' => (string) $operation->minutes, 'note' => (string) $operation->note];
        }

        ksort($rows);
        $this->structure = ['name' => $order->name, 'yield' => Quantity::decimal($order->yield_milli), 'rows' => array_values($rows)];
        $this->editingStructure = true;
        $this->resetErrorBag();
    }

    public function addRow(string $type): void
    {
        $this->structure['rows'][] = self::blankRow($type === self::OPERATION ? self::OPERATION : self::COMPONENT);
    }

    public function removeRow(int $index): void
    {
        unset($this->structure['rows'][$index]);
        $this->structure['rows'] = array_values($this->structure['rows']);
    }

    public function saveStructure(SaveProductionOrder $save): void
    {
        $rows = array_map(fn (array $row): array => ($row['type'] ?? self::COMPONENT) === self::OPERATION
            ? ['operation_id' => $row['operation_id'] ?? '', 'minutes' => $row['minutes'] ?? '', 'note' => $row['note'] ?? '']
            : ['article_id' => $row['article_id'] ?? '', 'quantity' => $row['quantity'] ?? '', 'unit' => $row['unit'] ?? '', 'loss' => $row['loss'] ?? '', 'note' => $row['note'] ?? ''], (array) ($this->structure['rows'] ?? []));

        if ($this->attempt(fn () => $save->saveStructure($this->user(), $this->company(), $this->order(), [...$this->structure, 'lines' => $rows]), __('Orderns stycklista är sparad. Stycklistan för produkten är oförändrad.'), 'structure.')) {
            $this->editingStructure = false;

            foreach ($save->warnings as $warning) {
                $this->toast($warning, 'warning');
            }
        }
    }

    public function release(ProductionOrderLifecycle $life): void
    {
        $this->attempt(fn () => $life->release($this->user(), $this->company(), $this->order()), __('Ordern är frisläppt. Komponenterna är reserverade i inköpsförslaget.'), '');
    }

    public function backToDraft(ProductionOrderLifecycle $life): void
    {
        $this->attempt(fn () => $life->backToDraft($this->user(), $this->company(), $this->order()), __('Ordern är ett utkast igen och reserverar ingenting.'), '');
    }

    public function start(ProductionOrderLifecycle $life): void
    {
        $issue = $this->issueOnStart;

        if ($this->attempt(fn () => $life->start($this->user(), $this->company(), $this->order(), null, $issue), $issue ? __('Ordern är startad och komponenterna är uttagna ur lagret.') : __('Ordern är startad.'), '')) {
            $this->starting = false;
        }
    }

    public function issue(ProductionOrderLifecycle $life): void
    {
        $this->attempt(fn () => $life->issue($this->user(), $this->company(), $this->order()), __('Komponenterna är uttagna ur lagret till ordern.'), '');
    }

    public function openFinish(): void
    {
        abort_unless($this->can('manage'), 403);
        $order = $this->order();
        $this->finish = [
            'date' => CarbonImmutable::today()->toDateString(),
            'produced' => Quantity::decimal($order->quantity_milli),
            'minutes' => $order->operations->mapWithKeys(fn (ProductionOrderOperation $operation): array => [(string) $operation->id => ''])->all(),
        ];
        $this->finishing = true;
        $this->resetErrorBag();
    }

    public function finishOrder(ProductionOrderLifecycle $life): void
    {
        $order = $this->order();

        if ($this->attempt(fn () => $life->finish($this->user(), $this->company(), $order, $this->finish), __(':number är färdig. Produkterna är mottagna i lagret till tillverkningskostnaden.', ['number' => $order->reference()]), 'finish.')) {
            $this->finishing = false;
        }
    }

    public function cancelOrder(ProductionOrderLifecycle $life): void
    {
        if ($this->attempt(fn () => $life->cancel($this->user(), $this->company(), $this->order(), $this->cancelReason), __('Ordern är makulerad. Det som var uttaget är tillbaka i lagret.'), '')) {
            $this->cancelling = false;
            $this->cancelReason = '';
        }
    }

    public function render(): View
    {
        $order = $this->order();
        $componentIds = $order->components->pluck('component_article_id')->unique()->values()->all();
        $onHand = StockLevel::query()->whereIn('article_id', $componentIds)->groupBy('article_id')->select('article_id', DB::raw('sum(quantity_milli) as milli'))->pluck('milli', 'article_id');
        $reservedByOthers = ProductionOrderComponent::query()
            ->join('production_orders', 'production_orders.id', '=', 'production_order_components.production_order_id')
            ->whereIn('production_orders.status', [ProductionOrderStatus::Released->value, ProductionOrderStatus::Started->value])
            ->where('production_orders.id', '!=', $order->id)
            ->whereIn('production_order_components.component_article_id', $componentIds)
            ->whereColumn('production_order_components.issued_milli', '<', 'production_order_components.required_milli')
            ->groupBy('production_order_components.component_article_id')
            ->select('production_order_components.component_article_id', DB::raw('sum(production_order_components.required_milli - production_order_components.issued_milli) as milli'))
            ->pluck('milli', 'component_article_id');
        $coming = PurchaseOrderRow::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_rows.purchase_order_id')
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereIn('purchase_order_rows.article_id', $componentIds)
            ->whereColumn('purchase_order_rows.received_milli', '<', 'purchase_order_rows.quantity_milli')
            ->groupBy('purchase_order_rows.article_id')
            ->get(['purchase_order_rows.article_id', DB::raw('sum(purchase_order_rows.quantity_milli - purchase_order_rows.received_milli) as milli'), DB::raw('min(purchase_order_rows.expected_on) as first_expected')])
            ->keyBy('article_id');
        $availability = [];

        foreach ($order->components as $component) {
            $article = $component->component;
            $needed = $component->remainingMilli();
            $free = (int) ($onHand[$component->component_article_id] ?? 0) - (int) ($reservedByOthers[$component->component_article_id] ?? 0);
            $incoming = (int) ($coming->get($component->component_article_id)?->getAttribute('milli') ?? 0);
            $stocked = $article?->is_stock_item === true;
            $availability[$component->id] = [
                'stocked' => $stocked,
                'on_hand' => (int) ($onHand[$component->component_article_id] ?? 0),
                'reserved' => (int) ($reservedByOthers[$component->component_article_id] ?? 0),
                'coming' => $incoming,
                'coming_from' => $coming->get($component->component_article_id)?->getAttribute('first_expected'),
                'short' => $stocked ? max(0, $needed - max(0, $free) - $incoming) : 0,
                'covered' => ! $stocked || $needed <= max(0, $free),
            ];
        }

        $reported = ProductionLabourEntry::query()->where('production_order_id', $order->id)
            ->groupBy('production_order_operation_id')
            ->select('production_order_operation_id', DB::raw('sum(minutes) as minutes'), DB::raw('sum(cost_ore) as cost'))
            ->get()->keyBy('production_order_operation_id');
        $book = ManufacturingBook::for($this->company());
        $costing = $book->cost($order->structureLines(), $order->yield_milli);
        // A started order keeps the overhead it took when it started; a draft or released one shows today's choice.
        $overheadBp = $order->overhead_bp ?? (ManufacturingSettings::overheadInStockValue($this->company()) ? ManufacturingSettings::overheadBp($this->company()) : 0);
        $actualMaterial = (int) $order->components->sum('issued_value_ore');
        $actualLabour = (int) $reported->sum('cost');
        $data = [
            'order' => $order,
            'availability' => $availability,
            'reported' => $reported,
            'costing' => $costing,
            'estimate' => [
                'material' => Quantity::value($order->quantity_milli, $costing->materialPerUnitOre),
                'labour' => Quantity::value($order->quantity_milli, $costing->labourPerUnitOre),
                'overhead' => ProductionMath::overhead(Quantity::value($order->quantity_milli, $costing->materialPerUnitOre), Quantity::value($order->quantity_milli, $costing->labourPerUnitOre), $overheadBp),
                'complete' => $costing->complete,
            ],
            'actual' => [
                'material' => $actualMaterial,
                'labour' => $actualLabour,
                'overhead' => ProductionMath::overhead($actualMaterial, $actualLabour, $overheadBp),
            ],
            'overheadBp' => $overheadBp,
            'canManage' => $this->can('manage'),
            'allCovered' => collect($availability)->every(fn (array $item): bool => $item['covered']),
            'stockUnit' => $order->article?->stockUnitCode() ?? '',
        ];

        if ($this->editing || $this->editingStructure) {
            $data['locations'] = StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']);
            $data['orderUnits'] = $order->article === null ? [] : ArticleUnits::choices(ArticleUnits::stockCode($order->article), ArticleUnits::fractions(ArticleUnitConversion::query()->where('article_id', $order->article_id)->get()));
        }

        if ($this->editingStructure) {
            $articles = Article::query()->with('stockUnit:id,code')->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name', 'unit', 'stock_unit_id']);
            $conversions = ArticleUnitConversion::query()->get(['article_id', 'unit', 'numerator', 'denominator', 'factor_milli'])->groupBy('article_id');
            $data['articles'] = $articles;
            $data['units'] = $articles->mapWithKeys(fn (Article $item): array => [$item->id => StructureUnits::choices(ArticleUnits::stockCode($item), ArticleUnits::fractions($conversions->get($item->id, collect())))])->all();
            $data['activeOperations'] = Operation::query()->active()->orderBy('position')->orderBy('name')->get(['id', 'name']);
        }

        return view('manufacturing::livewire.production.show', $data);
    }

    private function order(): ProductionOrder
    {
        return ProductionOrder::query()
            ->with([
                'article:id,number,name,unit,stock_unit_id,is_stock_item',
                'article.stockUnit:id,code',
                'unit:id,code',
                'location:id,full_code,name',
                'salesDocument:id,kind,number,customer_id',
                'salesDocument.customer:id,name',
                'source:id,version,valid_from',
                'components.component:id,number,name,unit,stock_unit_id,is_stock_item',
                'components.component.stockUnit:id,code',
                'operations.operation:id,name,hourly_cost_ore',
            ])
            ->findOrFail($this->orderId);
    }

    /** @return array<string, string> */
    private static function blankRow(string $type): array
    {
        return ['type' => $type, 'article_id' => '', 'quantity' => '', 'unit' => '', 'loss' => '', 'operation_id' => '', 'minutes' => '', 'note' => ''];
    }
}
