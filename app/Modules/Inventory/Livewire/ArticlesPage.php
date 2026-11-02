<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Artiklar: the stocked articles with their total and their stock
 * per location, filtered by search (number, name, EAN, SKU, scanner code),
 * warehouse and status, and a small form to move, scrap or return goods.
 * Three queries for the page, whatever the number of articles.
 */
class ArticlesPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'lager')]
    public string $warehouse = '';

    #[Url(as: 'visa')]
    public string $status = 'lagerforda';

    /** The article the movement form is open for. */
    public ?int $moving = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'warehouse', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function move(int $articleId, string $kind = 'transfer'): void
    {
        abort_unless($this->can('count'), 403);
        $article = Article::query()->findOrFail($articleId);
        $from = StockLevel::query()->where('article_id', $article->id)->where('quantity_milli', '>', 0)->value('location_id') ?? $article->default_location_id;

        $this->moving = $article->id;
        $this->form = ['kind' => $kind, 'location_id' => $from, 'to_location_id' => null, 'quantity' => '', 'date' => CarbonImmutable::today()->toDateString(), 'note' => ''];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('moving', 'form');
        $this->resetErrorBag();
    }

    public function saveMovement(RecordMovement $record): void
    {
        $ok = $this->attempt(fn () => $record($this->user(), $this->company(), [...$this->form, 'article_id' => $this->moving]), match ($this->form['kind'] ?? '') {
            'transfer' => __('Varorna är flyttade.'),
            'scrap' => __('Kassationen är registrerad.'),
            default => __('Returen är registrerad.'),
        });

        if ($ok) {
            $this->cancel();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $warehouse = $this->warehouse === '' ? null : StockLocation::query()->find((int) $this->warehouse);
        $total = '(select coalesce(sum(quantity_milli), 0) from stock_levels where stock_levels.article_id = articles.id)';

        $articles = Article::query()
            ->withSum('stockLevels as stock_milli', 'quantity_milli')
            ->with(['stockLevels' => fn ($query) => $query->where('quantity_milli', '<>', 0)
                ->when($warehouse !== null, fn ($inner) => $inner->whereHas('location', fn (Builder $location) => $location->where('full_code', $warehouse?->full_code)->orWhere('full_code', 'like', $warehouse?->full_code.'-%')))
                ->with('location:id,full_code,name,sort_key')])
            ->when($this->status !== 'alla', fn ($query) => $query->where('is_stock_item', true))
            ->when($this->status === 'lagt', fn ($query) => $query->whereNotNull('reorder_point_milli')->whereRaw("{$total} <= reorder_point_milli"))
            ->when($this->status === 'negativt', fn ($query) => $query->whereRaw("{$total} < 0"))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('number', 'ilike', '%'.$term.'%')
                ->orWhere('name', 'ilike', '%'.$term.'%')
                ->orWhere('sku', 'ilike', '%'.$term.'%')
                ->orWhere('gtin', $term)
                ->orWhere('scanner_code', $term)))
            ->orderBy('number')
            ->paginate(50);

        return view('inventory::livewire.articles', [
            'articles' => $articles,
            'warehouses' => StockLocation::query()->where('type', 'WAREHOUSE')->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'canCount' => $this->can('count'),
            'canManage' => $this->can('manage'),
            'seesPrices' => $this->seesPrices(),
        ]);
    }
}
