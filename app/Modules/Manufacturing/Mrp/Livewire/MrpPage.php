<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Mrp\Actions\ConvertPlannedOrders;
use App\Modules\Manufacturing\Mrp\Actions\RunMrp;
use App\Modules\Manufacturing\Mrp\DemandKind;
use App\Modules\Manufacturing\Mrp\Models\MrpPlannedOrder;
use App\Modules\Manufacturing\Mrp\Models\MrpRun;
use App\Modules\Manufacturing\Mrp\PlannedOrderKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Behovsplanering (ADR-081 gap 5): runs the MRP engine over 13
 * weeks (at most 52) and shows the latest run's planned orders with what
 * each covers and the advice on it, and the advice on existing orders. A
 * person ticks the planned orders to turn into drafts; nothing is ordered
 * by the page itself. A fixed number of queries whatever the size of the
 * plan: the run, the page of planned orders with their articles and
 * suppliers, the articles named in the advice and the pegging, and the
 * drafts already made.
 */
class MrpPage extends Component
{
    use InventoryPage;
    use WithPagination;

    public const string NAME = 'mrp.page';

    private const int PER_PAGE = 50;

    #[Url(as: 'veckor')]
    public string $weeks = '13';

    #[Url(as: 'typ')]
    public string $kind = '';

    #[Url(as: 'atgarda')]
    public bool $exceptionsOnly = false;

    /** @var list<int|string> planned order ids ticked for conversion */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless($this->can('planView'), 403);

        $this->weeks = (string) $this->clampWeeks($this->weeks);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['kind', 'exceptionsOnly'], true)) {
            $this->resetPage();
        }
    }

    public function updatedWeeks(): void
    {
        $this->weeks = (string) $this->clampWeeks($this->weeks);
    }

    /** Runs the planning over the chosen number of weeks. */
    public function run(RunMrp $run): void
    {
        abort_unless($this->can('planRun'), 403);

        try {
            $result = $run($this->user(), $this->company(), $this->clampWeeks($this->weeks));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->selected = [];
        $this->resetPage();
        $this->toast(__('Behovsplaneringen är klar: :orders planerade order, varav :exceptions att åtgärda, och :messages råd om befintliga order.', [
            'orders' => $result->planned_order_count,
            'exceptions' => $result->exception_count,
            'messages' => $result->message_count,
        ]));
    }

    /** Turns the ticked planned orders into draft purchase and production orders. */
    public function convert(ConvertPlannedOrders $convert): void
    {
        abort_unless($this->can('planRun'), 403);

        try {
            $made = $convert($this->user(), $this->company(), array_map(fn (mixed $id): int => (int) $id, $this->selected));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            $this->toast(__('Du får inte skapa alla de valda orderna. Inköp kräver rätt att hantera inköpsorder och tillverkning rätt att hantera tillverkningsorder.'), 'error');

            return;
        }

        $this->selected = [];
        $this->toast(__('Utkast skapade: :purchase inköpsorder och :production tillverkningsorder. Granska och skicka eller släpp dem som vanligt.', [
            'purchase' => count($made['purchase_orders']),
            'production' => count($made['production_orders']),
        ]));
    }

    public function render(): View
    {
        $run = MrpRun::query()->with('runner:id,name')->latest('id')->first();
        $orders = null;
        $articles = collect();
        $purchaseOrders = collect();
        $productionOrders = collect();

        if ($run instanceof MrpRun) {
            $orders = MrpPlannedOrder::query()
                ->where('mrp_run_id', $run->id)
                ->with(['article:id,number,name,unit,stock_unit_id,lead_time_days', 'article.stockUnit:id,code', 'supplier:id,name'])
                ->when($this->kind === 'inkop', fn ($query) => $query->where('kind', PlannedOrderKind::Purchase->value))
                ->when($this->kind === 'tillverkning', fn ($query) => $query->where('kind', PlannedOrderKind::Production->value))
                ->when($this->exceptionsOnly, fn ($query) => $query->whereNotNull('exception'))
                ->orderBy('release_on')->orderBy('article_id')->orderBy('id')
                ->paginate(self::PER_PAGE);

            $named = [];

            foreach ($run->messages as $message) {
                $named[] = (int) $message['article_id'];
            }

            foreach ($orders->getCollection() as $order) {
                foreach ($order->pegging as $peg) {
                    if ($peg['kind'] === DemandKind::PlannedParent->value && $peg['article_id'] !== null) {
                        $named[] = (int) $peg['article_id'];
                    }
                }
            }

            $articles = $named === [] ? collect() : Article::query()->with('stockUnit:id,code')->whereKey(array_values(array_unique($named)))->get(['id', 'number', 'name', 'unit', 'stock_unit_id'])->keyBy('id');
            $converted = $orders->getCollection()->filter(fn (MrpPlannedOrder $order): bool => $order->isConverted());
            $purchaseIds = $converted->where('converted_type', MrpPlannedOrder::PURCHASE_ORDER)->pluck('converted_id')->all();
            $productionIds = $converted->where('converted_type', MrpPlannedOrder::PRODUCTION_ORDER)->pluck('converted_id')->all();
            $purchaseOrders = $purchaseIds === [] ? collect() : PurchaseOrder::query()->whereKey($purchaseIds)->get(['id', 'number'])->keyBy('id');
            $productionOrders = $productionIds === [] ? collect() : ProductionOrder::query()->whereKey($productionIds)->get(['id', 'number'])->keyBy('id');
        }

        return view('manufacturing::livewire.mrp', [
            'run' => $run,
            'orders' => $orders,
            'articles' => $articles,
            'purchaseOrders' => $purchaseOrders,
            'productionOrders' => $productionOrders,
            'canRun' => $this->can('planRun'),
            'canPurchase' => Gate::forUser($this->user())->allows('manage', [PurchaseOrder::class, $this->company()]),
            'canProduce' => Gate::forUser($this->user())->allows('manage', [Operation::class, $this->company()]),
            'maxWeeks' => ForecastPeriodKind::Week->maxCount(),
        ]);
    }

    private function clampWeeks(string $weeks): int
    {
        $value = (int) $weeks;

        if ($value < 1) {
            return ForecastPeriodKind::Week->defaultCount();
        }

        return min($value, ForecastPeriodKind::Week->maxCount());
    }
}
