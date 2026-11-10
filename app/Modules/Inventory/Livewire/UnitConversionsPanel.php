<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\UnitConversions;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Omräkningar on an article (review P3.4): the units a recipe row may be
 * written in, such as cl of a 70 cl bottle. Shown in the article form
 * while Lager is on, for those who manage articles.
 */
class UnitConversionsPanel extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.unit-conversions';

    #[Locked]
    public int $articleId;

    /** @var array{unit: string, factor: string} */
    public array $form = ['unit' => '', 'factor' => ''];

    public function mount(int $articleId): void
    {
        abort_unless($this->can('manage'), 403);
        $this->articleId = Article::query()->findOrFail($articleId)->id;
    }

    public function add(UnitConversions $conversions): void
    {
        abort_unless($this->can('manage'), 403);
        $article = Article::query()->findOrFail($this->articleId);

        if ($this->attempt(fn () => $conversions->save($this->user(), $this->company(), $article, $this->form['unit'], $this->form['factor']), __('Omräkningen är sparad.'))) {
            $this->form = ['unit' => '', 'factor' => ''];
        }
    }

    public function remove(int $conversionId, UnitConversions $conversions): void
    {
        abort_unless($this->can('manage'), 403);
        ArticleUnitConversion::query()->where('article_id', $this->articleId)->findOrFail($conversionId);

        $this->attempt(fn () => $conversions->delete($this->user(), $this->company(), $conversionId), __('Omräkningen är borttagen.'));
    }

    public function render(): View
    {
        return view('inventory::livewire.unit-conversions', [
            'article' => Article::query()->with('stockUnit:id,code')->findOrFail($this->articleId, ['id', 'unit', 'stock_unit_id']),
            'conversions' => ArticleUnitConversion::query()->where('article_id', $this->articleId)->orderBy('unit')->get(),
        ]);
    }
}
