<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Support\LotTrace;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Batcher: the batches and serial numbers of tracked articles with
 * what is in stock and the best-before date, and, for one of them, its trace
 * both ways: the receipt and supplier it came from and the pick lists,
 * orders, invoices and customers it went to, with a recall list of the
 * customers it reached (ADR-081 E12). The page runs a fixed number of
 * queries whatever the number of lots or movements.
 */
class LotsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'visa')]
    public string $status = 'i-lager';

    #[Url(as: 'batch')]
    public ?int $open = null;

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);

        if ($this->open !== null) {
            StockLot::query()->findOrFail($this->open);
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function show(int $id): void
    {
        $this->open = StockLot::query()->findOrFail($id)->id;
    }

    public function close(): void
    {
        $this->reset('open');
    }

    public function render(): View
    {
        $term = trim($this->search);
        $soon = CarbonImmutable::today()->addDays(30)->toDateString();

        $lots = StockLot::query()
            ->with(['article:id,number,name,unit,stock_unit_id', 'article.stockUnit:id,code', 'supplier:id,name'])
            ->withSum('levels as on_hand_milli', 'quantity_milli')
            ->when($this->status === 'i-lager', fn ($query) => $query->whereHas('levels', fn ($levels) => $levels->where('quantity_milli', '>', 0)))
            ->when($this->status === 'utgar', fn ($query) => $query->whereHas('levels', fn ($levels) => $levels->where('quantity_milli', '>', 0))->whereNotNull('expires_on')->where('expires_on', '<=', $soon))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'ilike', '%'.$term.'%')
                ->orWhereHas('article', fn ($article) => $article->where('number', 'ilike', '%'.$term.'%')->orWhere('name', 'ilike', '%'.$term.'%'))))
            ->orderByRaw('expires_on asc nulls last')->orderBy('code')
            ->paginate(25);

        $lot = $this->open === null ? null : StockLot::query()->find($this->open);

        return view('inventory::livewire.lots', [
            'lots' => $lots,
            'trace' => $lot instanceof StockLot ? LotTrace::of($lot) : null,
            'soon' => $soon,
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }
}
