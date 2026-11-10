<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Kitchen\Actions\SaveRecipe;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Livewire\Concerns\KitchenPage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kök → Recept → one recipe (ADR-073 §3, §4): the version that applies
 * today with its ingredients (net quantity, loss, gross quantity), method
 * and allergen tags, and for those who see costs the cost per row, batch
 * and portion, the latest purchase cost beside the valuation cost, and the
 * margin against the net menu price. "Ändra" saves a new version from a
 * date; earlier sales keep their version. Kökspersonal sees the recipe
 * without any price.
 */
class RecipePage extends Component
{
    use KitchenPage;

    /** A row differing more than this from the latest purchase cost is marked (ADR-073 §4). */
    public const int PRICE_GAP_BP = 500;

    #[Locked]
    public ?int $articleId = null;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $recept = null): void
    {
        abort_unless($this->can('viewAny'), 403);

        if ($recept === null) {
            abort_unless($this->can('manage'), 403);
            $this->startNew();

            return;
        }

        $article = Article::query()->findOrFail($recept);
        abort_unless(BillOfMaterials::query()->where('output_article_id', $article->id)->exists(), 404);
        $this->articleId = $article->id;
    }

    public function edit(): void
    {
        abort_unless($this->can('manage') && $this->articleId !== null, 403);
        $book = StructureBook::for($this->company());
        $base = $book->validOn($this->articleId, CarbonImmutable::today()) ?? $book->versions($this->articleId)->first();
        abort_unless($base instanceof BillOfMaterials, 404);

        $this->form = [
            'output_article_id' => (string) $this->articleId,
            'name' => $base->name,
            'valid_from' => CarbonImmutable::today()->toDateString(),
            'yield' => Quantity::decimal($base->yield_milli),
            'yield_unit' => $base->yield_unit,
            'method' => (string) $base->method,
            'allergens' => implode(', ', $base->allergens ?? []),
            'rows' => $base->rows->map(fn (BomRow $row): array => [
                'article_id' => (string) $row->component_article_id,
                'quantity' => $row->entered_quantity ?? Quantity::decimal($row->quantity_milli),
                'unit' => (string) ($row->entered_unit ?? ''),
                'loss' => $row->loss_bp === 0 ? '' : rtrim(rtrim(number_format($row->loss_bp / 100, 2, '.', ''), '0'), '.'),
                'note' => (string) $row->note,
            ])->values()->all(),
        ];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        $this->form['rows'][] = ['article_id' => '', 'quantity' => '', 'unit' => '', 'loss' => '', 'note' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->form['rows'][$index]);
        $this->form['rows'] = array_values($this->form['rows']);
    }

    public function cancel(): void
    {
        if ($this->articleId === null) {
            $this->redirectRoute('kitchen.recipes.index');

            return;
        }

        $this->editing = false;
        $this->resetErrorBag();
    }

    public function save(SaveRecipe $save): void
    {
        abort_unless($this->can('manage'), 403);
        $output = Article::query()->find((int) ($this->form['output_article_id'] ?? 0));

        if (! $output instanceof Article) {
            $this->addError('form.output_article_id', __('Välj vilken rätt eller artikel receptet gör.'));
            $this->toast(__('Välj vilken rätt eller artikel receptet gör.'), 'error');

            return;
        }

        $recipe = null;
        $ok = $this->attempt(function () use ($save, $output, &$recipe): void {
            $recipe = $save($this->user(), $this->company(), $output, $this->form);
        }, '');

        if (! $ok || ! $recipe instanceof BillOfMaterials) {
            return;
        }

        $this->toast(__('Receptet är sparat som version :version, som gäller från :date.', ['version' => $recipe->version, 'date' => $recipe->valid_from->toDateString()]));

        foreach ($save->warnings() as $warning) {
            $this->toast($warning, 'warning');
        }

        $this->editing = false;

        if ($this->articleId === null) {
            $this->redirectRoute('kitchen.recipes.show', ['recept' => $output->id]);

            return;
        }
    }

    public function setActive(bool $active, SaveRecipe $save): void
    {
        abort_unless($this->can('manage') && $this->articleId !== null, 403);
        $article = Article::query()->findOrFail($this->articleId);

        $this->attempt(fn () => $save->setActive($this->user(), $this->company(), $article, $active), $active ? __('Receptet används igen.') : __('Receptet är inaktiverat.'));
    }

    public function render(): View
    {
        $company = $this->company();
        $book = StructureBook::for($company);
        $today = CarbonImmutable::today();
        $seesCosts = $this->can('seeCosts');
        $article = $this->articleId === null ? null : Article::query()->with('stockUnit:id,code')->find($this->articleId);
        $current = $this->articleId === null ? null : $book->validOn($this->articleId, $today);
        $current?->rows->load('component:id,name,unit,stock_unit_id', 'component.stockUnit:id,code');
        $cost = $current !== null && $seesCosts ? $book->cost($current, UnitCosts::at($company, $today), $today) : null;
        $data = [
            'article' => $article,
            'current' => $current,
            'versions' => $this->articleId === null ? collect() : $book->versions($this->articleId),
            'cost' => $cost,
            'seesCosts' => $seesCosts,
            'canManage' => $this->can('manage'),
            'margin' => null,
            'target' => KitchenSettings::for($company)['target_food_cost_bp'],
            'gapBp' => self::PRICE_GAP_BP,
        ];

        if ($cost !== null && $article instanceof Article && $current->isPortions() && $cost->complete) {
            $net = $article->price_ore->ore;
            $data['margin'] = [
                'net' => $net,
                'contribution' => $net - $cost->perUnitOre,
                'margin_bp' => StructureMath::share($net - $cost->perUnitOre, $net),
                'food_cost_bp' => StructureMath::share($cost->perUnitOre, $net),
            ];
        }

        if ($this->editing) {
            $articles = Article::query()->with('stockUnit:id,code')->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name', 'unit', 'is_stock_item', 'stock_unit_id']);
            $conversions = ArticleUnitConversion::query()->get(['article_id', 'unit', 'numerator', 'denominator'])->groupBy('article_id');
            $data['articles'] = $articles;
            $data['units'] = $articles->mapWithKeys(fn (Article $item): array => [$item->id => StructureUnits::choices(ArticleUnits::stockCode($item), ArticleUnits::fractions($conversions->get($item->id, collect())))])->all();
        }

        return view('kitchen::livewire.recipe', $data);
    }

    /** Percent with up to two decimals, from basis points. */
    public static function percent(?int $bp): string
    {
        if ($bp === null) {
            return '–';
        }

        return str_replace('.', ',', (string) BigDecimal::of($bp)->dividedBy(100, 1, RoundingMode::HalfUp)).' %';
    }

    private function startNew(): void
    {
        $this->form = [
            'output_article_id' => '',
            'name' => '',
            'valid_from' => CarbonImmutable::today()->toDateString(),
            'yield' => '',
            'yield_unit' => BillOfMaterials::PORTIONS,
            'method' => '',
            'allergens' => '',
            'rows' => [['article_id' => '', 'quantity' => '', 'unit' => '', 'loss' => '', 'note' => '']],
        ];
        $this->editing = true;
    }
}
