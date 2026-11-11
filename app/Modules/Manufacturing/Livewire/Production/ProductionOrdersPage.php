<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Production;

use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\ProductionOrder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tillverkning → Tillverkningsorder (ADR-071 §8): every production order
 * with its product, quantity, status, dates and the customer order it
 * serves. The open ones by default; status and search in the address. A
 * fixed number of queries whatever the number of orders.
 */
class ProductionOrdersPage extends Component
{
    use ManufacturingPage;
    use WithPagination;

    /** The filter in the address (Swedish, AGENTS.md §11.4): the open orders, one status, or all. */
    public const array FILTERS = [
        'oppna' => null,
        'utkast' => ProductionOrderStatus::Draft,
        'frislappta' => ProductionOrderStatus::Released,
        'startade' => ProductionOrderStatus::Started,
        'fardiga' => ProductionOrderStatus::Finished,
        'makulerade' => ProductionOrderStatus::Cancelled,
        'alla' => null,
    ];

    public const string OPEN = 'oppna';

    #[Url(as: 'status')]
    public string $status = self::OPEN;

    #[Url(as: 'sok')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $search = trim($this->search);
        $filter = array_key_exists($this->status, self::FILTERS) ? $this->status : self::OPEN;
        $status = self::FILTERS[$filter];

        $orders = ProductionOrder::query()
            ->with(['article:id,number,name,unit,stock_unit_id', 'article.stockUnit:id,code', 'salesDocument:id,kind,number,customer_id', 'salesDocument.customer:id,name'])
            ->when($status instanceof ProductionOrderStatus, fn ($query) => $query->where('status', $status?->value))
            ->when($filter === self::OPEN, fn ($query) => $query->whereIn('status', array_map(fn (ProductionOrderStatus $open): string => $open->value, ProductionOrderStatus::open())))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $number = preg_replace('/^to-?/i', '', $search);

                $query->where(fn ($inner) => $inner
                    ->whereHas('article', fn ($article) => $article->where('name', 'ilike', $like)->orWhere('number', 'ilike', $like))
                    ->when(ctype_digit((string) $number), fn ($byNumber) => $byNumber->orWhere('number', (int) $number)));
            })
            ->orderByRaw('planned_start_on is null, planned_start_on')
            ->orderByDesc('number')
            ->paginate(25);

        return view('manufacturing::livewire.production.orders', [
            'orders' => $orders,
            'filters' => [
                'oppna' => __('Öppna'),
                ...array_map(fn (ProductionOrderStatus $status): string => $status->label(), array_filter(self::FILTERS)),
                'alla' => __('Alla'),
            ],
            'canManage' => $this->can('manage'),
        ]);
    }
}
