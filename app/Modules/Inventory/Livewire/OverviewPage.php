<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Support\StockValuationReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lager → Översikt: the stock value today (for those who see prices), how
 * many articles are below their reorder point, open receipts and pick
 * lists, and the latest movements.
 */
class OverviewPage extends Component
{
    use InventoryPage;

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function render(): View
    {
        $total = '(select coalesce(sum(quantity_milli), 0) from stock_levels where stock_levels.article_id = articles.id)';
        $low = Article::query()->where('is_stock_item', true)->whereNotNull('reorder_point_milli')->whereRaw("{$total} <= reorder_point_milli")
            ->withSum('stockLevels as stock_milli', 'quantity_milli')->orderBy('number');

        return view('inventory::livewire.overview', [
            'value' => $this->seesPrices() ? StockValuationReport::at($this->company(), CarbonImmutable::today()) : null,
            'stocked' => Article::query()->where('is_stock_item', true)->count(),
            'lowCount' => (clone $low)->count(),
            'low' => $low->limit(10)->get(),
            'openReceipts' => StockReceipt::query()->whereIn('status', [StockReceipt::OPEN, StockReceipt::PARTIAL])->count(),
            'openPickLists' => PickList::query()->where('status', PickList::OPEN)->count(),
            'movements' => StockMovement::query()->with(['article:id,number,name,unit', 'location:id,full_code', 'creator:id,name'])->latest('id')->limit(12)->get(),
        ]);
    }
}
