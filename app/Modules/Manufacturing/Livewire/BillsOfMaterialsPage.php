<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Stycklistor (ADR-071 §3, §6): every article with a bill
 * of materials, the version that applies today, its components and
 * operations, and its cost per unit split into material and labour.
 * Search in the address. A fixed number of queries whatever the number of
 * bills of materials.
 */
class BillsOfMaterialsPage extends Component
{
    use ManufacturingPage;

    #[Url(as: 'sok')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function render(): View
    {
        $book = ManufacturingBook::for($this->company());
        $articles = Article::query()->with('stockUnit:id,code')->whereKey($book->outputs())->get(['id', 'number', 'name', 'unit', 'price_ore', 'stock_unit_id'])->keyBy('id');
        $search = mb_strtolower(trim($this->search));
        $rows = [];

        foreach ($book->outputs() as $articleId) {
            $article = $articles->get($articleId);
            $latest = $book->versions($articleId)->first();

            if (! $article instanceof Article || ! $latest instanceof BillOfMaterials) {
                continue;
            }

            if ($search !== '' && ! str_contains(mb_strtolower("{$article->name} {$article->number} {$latest->name}"), $search)) {
                continue;
            }

            $current = $book->validOn($articleId);
            $structure = $current ?? $latest;
            $costing = $current === null ? null : $book->cost($current->rows, $current->yield_milli);

            $rows[] = [
                'article' => $article,
                'current' => $current,
                'latest' => $latest,
                'components' => $structure->rows->whereNotNull('component_article_id')->count(),
                'operations' => $structure->rows->whereNotNull('operation_id')->count(),
                'costing' => $costing,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp(mb_strtolower($a['article']->name), mb_strtolower($b['article']->name)));

        return view('manufacturing::livewire.boms', ['rows' => $rows, 'canManage' => $this->can('manage')]);
    }
}
