<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire;

use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tillverkning → Stycklistor → one bill of materials (ADR-071 §3, §6):
 * the version that applies today with its components (net quantity in the
 * stock unit and as typed, scrap, gross) and operations (minutes), each
 * with its cost, and the cost per unit split into material, labour and
 * overhead. "Ändra" saves a new version from a date; quotes and orders
 * keep the copy they made.
 */
class BillOfMaterialsPage extends Component
{
    use ManufacturingPage;

    public const string COMPONENT = 'component';

    public const string OPERATION = 'operation';

    #[Locked]
    public ?int $articleId = null;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $artikel = null): void
    {
        abort_unless($this->can('viewAny'), 403);

        if ($artikel === null) {
            abort_unless($this->can('manage'), 403);
            $this->startNew();

            return;
        }

        $article = Article::query()->findOrFail($artikel);
        abort_unless(BillOfMaterials::query()->where('kind', StructureKind::Bom)->where('output_article_id', $article->id)->exists(), 404);
        $this->articleId = $article->id;
    }

    public function edit(): void
    {
        abort_unless($this->can('manage') && $this->articleId !== null, 403);
        $book = ManufacturingBook::for($this->company());
        $base = $book->validOn($this->articleId) ?? $book->versions($this->articleId)->first();
        abort_unless($base instanceof BillOfMaterials, 404);

        $this->form = [
            'output_article_id' => (string) $this->articleId,
            'name' => $base->name,
            'valid_from' => CarbonImmutable::today()->toDateString(),
            'yield' => Quantity::decimal($base->yield_milli),
            'method' => (string) $base->method,
            'rows' => $base->rows->map(fn (BomRow $row): array => $row->operation_id !== null
                ? [...self::blankRow(self::OPERATION), 'operation_id' => (string) $row->operation_id, 'minutes' => (string) $row->minutes, 'note' => (string) $row->note]
                : [
                    ...self::blankRow(self::COMPONENT),
                    'article_id' => (string) $row->component_article_id,
                    'quantity' => $row->entered_quantity ?? Quantity::decimal($row->quantity_milli),
                    'unit' => (string) ($row->entered_unit ?? ''),
                    'loss' => self::percentInput($row->loss_bp),
                    'note' => (string) $row->note,
                ])->values()->all(),
        ];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function addRow(string $type): void
    {
        $this->form['rows'][] = self::blankRow($type === self::OPERATION ? self::OPERATION : self::COMPONENT);
    }

    public function removeRow(int $index): void
    {
        unset($this->form['rows'][$index]);
        $this->form['rows'] = array_values($this->form['rows']);
    }

    public function cancel(): void
    {
        if ($this->articleId === null) {
            $this->redirectRoute('manufacturing.boms.index');

            return;
        }

        $this->editing = false;
        $this->resetErrorBag();
    }

    public function save(SaveBillOfMaterials $save): void
    {
        abort_unless($this->can('manage'), 403);
        $output = Article::query()->find((int) ($this->form['output_article_id'] ?? 0));

        if (! $output instanceof Article) {
            $this->addError('form.output_article_id', __('Välj vilken produkt stycklistan gör.'));
            $this->toast(__('Välj vilken produkt stycklistan gör.'), 'error');

            return;
        }

        $structure = null;
        $ok = $this->attempt(function () use ($save, $output, &$structure): void {
            $structure = $save($this->user(), $this->company(), $output, [
                ...$this->form,
                // A row's type decides which fields it sends; the writer reads an operation row by its empty article.
                'rows' => array_map(fn (array $row): array => ($row['type'] ?? self::COMPONENT) === self::OPERATION
                    ? ['operation_id' => $row['operation_id'] ?? '', 'minutes' => $row['minutes'] ?? '', 'note' => $row['note'] ?? '']
                    : ['article_id' => $row['article_id'] ?? '', 'quantity' => $row['quantity'] ?? '', 'unit' => $row['unit'] ?? '', 'loss' => $row['loss'] ?? '', 'note' => $row['note'] ?? ''], (array) ($this->form['rows'] ?? [])),
            ]);
        }, '');

        if (! $ok || ! $structure instanceof BillOfMaterials) {
            return;
        }

        $this->toast(__('Stycklistan är sparad som version :version, som gäller från :date.', ['version' => $structure->version, 'date' => $structure->valid_from->toDateString()]));

        foreach ($save->warnings() as $warning) {
            $this->toast($warning, 'warning');
        }

        $this->editing = false;

        if ($this->articleId === null) {
            $this->redirectRoute('manufacturing.boms.show', ['artikel' => $output->id]);
        }
    }

    public function setActive(bool $active, SaveBillOfMaterials $save): void
    {
        abort_unless($this->can('manage') && $this->articleId !== null, 403);
        $article = Article::query()->findOrFail($this->articleId);

        $this->attempt(fn () => $save->setActive($this->user(), $this->company(), $article, $active), $active ? __('Stycklistan används igen.') : __('Stycklistan är inaktiverad.'));
    }

    public function render(): View
    {
        $book = ManufacturingBook::for($this->company());
        $article = $this->articleId === null ? null : Article::query()->with('stockUnit:id,code')->find($this->articleId);
        $current = $this->articleId === null ? null : $book->validOn($this->articleId);
        $current?->rows->load('component:id,number,name,unit,stock_unit_id', 'component.stockUnit:id,code');
        $data = [
            'article' => $article,
            'current' => $current,
            'versions' => $this->articleId === null ? collect() : $book->versions($this->articleId),
            'costing' => $current === null ? null : $book->cost($current->rows, $current->yield_milli),
            'operations' => $book->operations,
            'canManage' => $this->can('manage'),
        ];

        if ($this->editing) {
            $articles = Article::query()->with('stockUnit:id,code')->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name', 'unit', 'stock_unit_id']);
            $conversions = ArticleUnitConversion::query()->get(['article_id', 'unit', 'numerator', 'denominator', 'factor_milli'])->groupBy('article_id');
            $data['articles'] = $articles;
            $data['units'] = $articles->mapWithKeys(fn (Article $item): array => [$item->id => StructureUnits::choices(ArticleUnits::stockCode($item), ArticleUnits::fractions($conversions->get($item->id, collect())))])->all();
            $data['activeOperations'] = Operation::query()->active()->orderBy('position')->orderBy('name')->get(['id', 'name']);
        }

        return view('manufacturing::livewire.bom', $data);
    }

    /** @return array<string, string> */
    private static function blankRow(string $type): array
    {
        return ['type' => $type, 'article_id' => '', 'quantity' => '', 'unit' => '', 'loss' => '', 'operation_id' => '', 'minutes' => '', 'note' => ''];
    }

    private function startNew(): void
    {
        $this->form = [
            'output_article_id' => '',
            'name' => '',
            'valid_from' => CarbonImmutable::today()->toDateString(),
            'yield' => '1',
            'method' => '',
            'rows' => [self::blankRow(self::COMPONENT)],
        ];
        $this->editing = true;
    }
}
