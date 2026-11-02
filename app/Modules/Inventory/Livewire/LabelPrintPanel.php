<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\LabelTemplates;
use App\Modules\Inventory\Actions\PrintLabels;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelMedia;
use App\Modules\Inventory\Labels\LabelRenderer;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\LabelTemplate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The print step for labels (ADR-041), shown on the article list, the
 * receipts and the locations: choose a template, how many of each, where to
 * start on a partly used sheet, and whether to mark the articles as
 * printed. The form posts to the PDF route and opens it in a new tab.
 */
class LabelPrintPanel extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.label-print';

    /** ARTICLE or LOCATION */
    public string $kind = 'ARTICLE';

    /** @var array<int|string, int> id => copies proposed by the page (1, or the quantity received) */
    public array $items = [];

    /** What the proposed copies mean, for the copies choice ("1 per artikel", "mottaget antal"). */
    public string $givenLabel = '';

    public ?int $templateId = null;

    /** given, stock or fixed */
    public string $mode = 'given';

    public string $fixed = '1';

    public string $start = '1';

    public bool $markPrinted = true;

    public function mount(): void
    {
        abort_unless($this->can('printLabels'), 403);
        app(LabelTemplates::class)->ensureDefaults($this->company(), $this->user());
        $this->templateId = LabelTemplate::query()->where('kind', $this->kind)->orderByDesc('is_default')->orderBy('name')->value('id');
        $this->givenLabel = $this->givenLabel !== '' ? $this->givenLabel : __('En per artikel');
    }

    public function printed(): void
    {
        $this->toast(__('Etiketterna öppnas som PDF i en ny flik.'));
        $this->dispatch('labels-printed');
    }

    public function close(): void
    {
        $this->dispatch('labels-closed');
    }

    public function render(): View
    {
        $templates = LabelTemplate::query()->where('kind', $this->kind)->orderByDesc('is_default')->orderBy('name')->get();
        $template = $templates->firstWhere('id', $this->templateId) ?? $templates->first();
        $copies = $this->copies();

        return view('inventory::livewire.label-print', [
            'templates' => $templates,
            'template' => $template,
            'copies' => $copies,
            'total' => array_sum($copies),
            'isArticles' => $this->kind === LabelKind::Article->value,
            'isSheet' => $template?->media === LabelMedia::Sheet,
            'withPrices' => $this->can('printPrices'),
            'tooMany' => array_sum($copies) > LabelRenderer::MAX_LABELS,
            'max' => LabelRenderer::MAX_LABELS,
        ]);
    }

    /**
     * The final number of labels per id: as proposed, by stock (articles,
     * whole units, at least one), or a fixed number; each 1 to 500.
     *
     * @return array<int, int>
     */
    private function copies(): array
    {
        $ids = array_map('intval', array_keys($this->items));
        $stock = [];

        if ($this->mode === 'stock' && $this->kind === LabelKind::Article->value) {
            $stock = Article::query()->whereKey($ids)->withSum('stockLevels as stock_milli', 'quantity_milli')->pluck('stock_milli', 'id')->all();
        }

        $copies = [];

        foreach ($this->items as $id => $given) {
            $count = match ($this->mode) {
                'stock' => intdiv(max(0, (int) ($stock[(int) $id] ?? 0)), 1000),
                'fixed' => (int) $this->fixed,
                default => (int) $given,
            };
            $copies[(int) $id] = max(1, min(PrintLabels::MAX_COPIES, $count));
        }

        return $copies;
    }
}
