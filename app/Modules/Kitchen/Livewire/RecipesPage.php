<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Kitchen\Livewire\Concerns\KitchenPage;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kök → Recept: every dish and sub-recipe with a recipe, the version that
 * applies today, its yield, and (for those who see costs) its cost per
 * portion or unit. Search and a filter in the address. A fixed number of
 * queries whatever the number of recipes.
 */
class RecipesPage extends Component
{
    use KitchenPage;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'visa')]
    public string $show = 'alla';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function render(): View
    {
        $company = $this->company();
        $book = StructureBook::for($company);
        $today = CarbonImmutable::today();
        $seesCosts = $this->can('seeCosts');
        $costs = $seesCosts ? UnitCosts::at($company, $today) : null;
        $articles = Article::query()->whereKey($book->outputs())->orderBy('name')->get(['id', 'number', 'name', 'unit', 'price_ore'])->keyBy('id');
        $search = mb_strtolower(trim($this->search));
        $rows = [];

        foreach ($book->outputs() as $articleId) {
            $article = $articles->get($articleId);
            $latest = $book->versions($articleId)->first();

            if (! $article instanceof Article || ! $latest instanceof BillOfMaterials) {
                continue;
            }

            $current = $book->validOn($articleId, $today);
            $kind = ($current ?? $latest)->isPortions() ? 'ratter' : 'grundrecept';

            if ($this->show !== 'alla' && $this->show !== $kind) {
                continue;
            }

            if ($search !== '' && ! str_contains(mb_strtolower("{$article->name} {$article->number} {$latest->name}"), $search)) {
                continue;
            }

            $cost = $current !== null && $costs !== null ? $book->cost($current, $costs, $today) : null;

            $rows[] = [
                'article' => $article,
                'current' => $current,
                'latest' => $latest,
                'dish' => $kind === 'ratter',
                'cost' => $cost?->complete ? $cost->perUnitOre : null,
                'incomplete' => $cost !== null && ! $cost->complete,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp(mb_strtolower($a['article']->name), mb_strtolower($b['article']->name)));

        return view('kitchen::livewire.recipes', ['rows' => $rows, 'seesCosts' => $seesCosts, 'canManage' => $this->can('manage')]);
    }
}
