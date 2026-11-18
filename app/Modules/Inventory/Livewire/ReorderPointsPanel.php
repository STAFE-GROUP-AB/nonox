<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\ReorderPoints;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\ArticleLocationSetting;
use App\Modules\Inventory\Models\StockLocation;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Beställningspunkt per lager (ADR-081 E13) on an article: one reorder point
 * and reorder quantity for each warehouse, replacing the company-wide point
 * in the purchase proposal. Shown in the article form while Lager is on, for
 * those who manage articles.
 */
class ReorderPointsPanel extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.reorder-points';

    #[Locked]
    public int $articleId;

    /** @var array{warehouse_id: string, point: string, quantity: string} */
    public array $form = ['warehouse_id' => '', 'point' => '', 'quantity' => ''];

    public function mount(int $articleId): void
    {
        abort_unless($this->can('manage'), 403);
        $this->articleId = Article::query()->findOrFail($articleId)->id;
    }

    public function save(ReorderPoints $points): void
    {
        abort_unless($this->can('manage'), 403);
        $article = Article::query()->findOrFail($this->articleId);

        if ($this->attempt(fn () => $points->save($this->user(), $this->company(), $article, (int) $this->form['warehouse_id'], $this->form['point'], $this->form['quantity']), __('Beställningspunkten för lagret är sparad.'))) {
            $this->form = ['warehouse_id' => '', 'point' => '', 'quantity' => ''];
        }
    }

    public function edit(int $settingId): void
    {
        abort_unless($this->can('manage'), 403);
        $setting = ArticleLocationSetting::query()->where('article_id', $this->articleId)->findOrFail($settingId);

        $this->form = [
            'warehouse_id' => (string) $setting->warehouse_id,
            'point' => Quantity::decimal($setting->reorder_point_milli),
            'quantity' => $setting->reorder_quantity_milli === null ? '' : Quantity::decimal($setting->reorder_quantity_milli),
        ];
    }

    public function remove(int $settingId, ReorderPoints $points): void
    {
        abort_unless($this->can('manage'), 403);
        $setting = ArticleLocationSetting::query()->where('article_id', $this->articleId)->findOrFail($settingId);

        $this->attempt(fn () => $points->delete($this->user(), $this->company(), $setting), __('Beställningspunkten för lagret är borttagen.'));
    }

    public function render(): View
    {
        return view('inventory::livewire.reorder-points', [
            'article' => Article::query()->with('stockUnit:id,code')->findOrFail($this->articleId, ['id', 'unit', 'stock_unit_id', 'is_stock_item']),
            'settings' => ArticleLocationSetting::query()->where('article_id', $this->articleId)->with('warehouse:id,full_code,name,sort_key')->get()->sortBy(fn (ArticleLocationSetting $setting): string => (string) $setting->warehouse?->sort_key)->values(),
            'warehouses' => StockLocation::query()->where('type', LocationType::Warehouse->value)->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
        ]);
    }
}
