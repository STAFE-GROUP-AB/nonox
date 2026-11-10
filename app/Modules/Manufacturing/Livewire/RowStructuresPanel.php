<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire;

use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveRowStructure;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Support\DocumentCosting;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Stycklistor och kalkyl" on a quote or an order (ADR-071 §4, §6), for
 * users with manufacturing:view, never on the customer's PDF. Per saved
 * row: choose a bill of materials (the row's article's by default), which
 * copies it into the row; change the copy for this row only; take it off;
 * or save it as a new bill of materials. Each row with a copy shows its
 * material, labour, overhead, cost per unit, contribution and margin, and
 * the document the same over those rows. A fixed number of queries
 * whatever the number of rows.
 */
class RowStructuresPanel extends Component
{
    use ManufacturingPage;

    #[Locked]
    public string $documentId;

    /** @var array<string, string> line_key => the article whose bill of materials to copy */
    public array $choice = [];

    #[Locked]
    public ?string $editingKey = null;

    /** @var array<string, mixed> */
    public array $form = [];

    #[Locked]
    public ?string $savingAsKey = null;

    /** @var array<string, mixed> */
    public array $saveAs = [];

    public function mount(string $documentId): void
    {
        abort_unless($this->can('viewAny'), 403);
        $document = SalesDocument::query()->findOrFail($documentId);
        abort_unless(Gate::allows('view', $document), 403);
        $this->documentId = $document->id;
    }

    /** The editor saved the document: rows may have changed, so the panel reads them again. */
    #[On('sales-document-saved')]
    public function refreshRows(): void
    {
        $this->editingKey = null;
        $this->savingAsKey = null;
    }

    public function choose(string $lineKey, SaveRowStructure $save): void
    {
        $article = Article::query()->find((int) ($this->choice[$lineKey] ?? 0));

        if (! $article instanceof Article) {
            $this->toast(__('Välj vilken stycklista raden ska ha.'), 'error');

            return;
        }

        $this->attempt(fn () => $save->choose($this->user(), $this->document(), $lineKey, $article), __('Stycklistan för :name är kopierad till raden. Ändringar här gäller bara den här raden.', ['name' => $article->name]), 'panel.');
    }

    public function edit(string $lineKey): void
    {
        $structure = RowStructure::query()->where('sales_document_id', $this->documentId)->where('line_key', $lineKey)->with('lines')->firstOrFail();
        abort_unless(Gate::allows('update', $this->document()), 403);

        $this->form = [
            'name' => $structure->name,
            'yield' => Quantity::decimal($structure->yield_milli),
            'lines' => $structure->lines->map(fn (RowStructureLine $line): array => $line->isOperation()
                ? [...self::blankLine(BillOfMaterialsPage::OPERATION), 'operation_id' => (string) $line->operation_id, 'minutes' => (string) $line->minutes, 'note' => (string) $line->note]
                : [
                    ...self::blankLine(BillOfMaterialsPage::COMPONENT),
                    'article_id' => (string) $line->component_article_id,
                    'quantity' => $line->entered_quantity ?? Quantity::decimal($line->quantity_milli),
                    'unit' => (string) ($line->entered_unit ?? ''),
                    'loss' => self::percentInput($line->loss_bp),
                    'note' => (string) $line->note,
                ])->values()->all(),
        ];
        $this->editingKey = $lineKey;
        $this->savingAsKey = null;
        $this->resetErrorBag();
    }

    public function addLine(string $type): void
    {
        $this->form['lines'][] = self::blankLine($type === BillOfMaterialsPage::OPERATION ? BillOfMaterialsPage::OPERATION : BillOfMaterialsPage::COMPONENT);
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function cancel(): void
    {
        $this->editingKey = null;
        $this->savingAsKey = null;
        $this->resetErrorBag();
    }

    public function update(SaveRowStructure $save): void
    {
        if ($this->editingKey === null) {
            return;
        }

        $input = [
            ...$this->form,
            'lines' => array_map(fn (array $line): array => ($line['type'] ?? BillOfMaterialsPage::COMPONENT) === BillOfMaterialsPage::OPERATION
                ? ['operation_id' => $line['operation_id'] ?? '', 'minutes' => $line['minutes'] ?? '', 'note' => $line['note'] ?? '']
                : ['article_id' => $line['article_id'] ?? '', 'quantity' => $line['quantity'] ?? '', 'unit' => $line['unit'] ?? '', 'loss' => $line['loss'] ?? '', 'note' => $line['note'] ?? ''], (array) ($this->form['lines'] ?? [])),
        ];

        $ok = $this->attempt(fn () => $save->update($this->user(), $this->document(), (string) $this->editingKey, $input), __('Radens stycklista är sparad. Mallen är oförändrad.'));

        if (! $ok) {
            return;
        }

        foreach ($save->warnings as $warning) {
            $this->toast($warning, 'warning');
        }

        $this->editingKey = null;
    }

    public function remove(string $lineKey, SaveRowStructure $save): void
    {
        $this->attempt(fn () => $save->remove($this->user(), $this->document(), $lineKey), __('Stycklistan är borttagen från raden.'), 'panel.');

        if ($this->editingKey === $lineKey) {
            $this->editingKey = null;
        }
    }

    public function startSaveAs(string $lineKey): void
    {
        abort_unless($this->can('manage'), 403);
        $structure = RowStructure::query()->where('sales_document_id', $this->documentId)->where('line_key', $lineKey)->firstOrFail();

        $this->saveAs = [
            'output_article_id' => (string) $structure->output_article_id,
            'name' => $structure->name,
            'valid_from' => CarbonImmutable::today()->toDateString(),
            'confirm_backdate' => false,
        ];
        $this->savingAsKey = $lineKey;
        $this->editingKey = null;
        $this->resetErrorBag();
    }

    public function saveAsBillOfMaterials(SaveRowStructure $save): void
    {
        abort_unless($this->can('manage') && $this->savingAsKey !== null, 403);
        $output = Article::query()->find((int) ($this->saveAs['output_article_id'] ?? 0));

        if (! $output instanceof Article) {
            $this->addError('saveAs.output_article_id', __('Välj vilken produkt stycklistan gör.'));
            $this->toast(__('Välj vilken produkt stycklistan gör.'), 'error');

            return;
        }

        $structure = null;
        $ok = $this->attempt(function () use ($save, $output, &$structure): void {
            $structure = $save->saveAsBillOfMaterials($this->user(), $this->document(), (string) $this->savingAsKey, $output, $this->saveAs);
        }, '', 'saveAs.');

        if (! $ok || ! $structure instanceof BillOfMaterials) {
            return;
        }

        $this->toast(__('Stycklistan för :name är sparad som version :version, som gäller från :date.', ['name' => $output->name, 'version' => $structure->version, 'date' => $structure->valid_from->toDateString()]));
        $this->savingAsKey = null;
    }

    public function render(): View
    {
        $company = $this->company();
        $document = SalesDocument::query()->findOrFail($this->documentId);
        $rows = SalesDocumentRow::query()->where('sales_document_id', $document->id)->with('article:id,number,name')->orderBy('position')->get();
        $structures = RowStructure::query()
            ->where('sales_document_id', $document->id)
            ->with(['lines.component:id,number,name,unit,stock_unit_id', 'lines.component.stockUnit:id,code', 'outputArticle:id,number,name,unit,stock_unit_id', 'outputArticle.stockUnit:id,code', 'source:id,version'])
            ->get()
            ->keyBy('line_key');
        $book = ManufacturingBook::for($company);
        $conversions = ArticleUnitConversion::query()->whereIn('article_id', $structures->pluck('output_article_id')->unique()->values()->all())->get()->groupBy('article_id')->map(fn ($group): array => ArticleUnits::fractions($group))->all();
        $costing = DocumentCosting::of($rows, $structures, $book, $conversions);
        $made = array_values(array_filter($book->outputs(), fn (int $id): bool => $book->validOn($id) !== null));
        $bomArticles = Article::query()->whereKey($made)->orderBy('name')->get(['id', 'number', 'name']);

        foreach ($rows as $row) {
            if ($row->line_key !== null && ! isset($this->choice[$row->line_key]) && $row->article_id !== null && in_array($row->article_id, $made, true)) {
                $this->choice[$row->line_key] = (string) $row->article_id;
            }
        }

        $data = [
            'document' => $document,
            'rows' => $rows,
            'structures' => $structures,
            'costing' => $costing,
            'bomArticles' => $bomArticles,
            'operations' => $book->operations,
            'editable' => Gate::allows('update', $document),
            'canManage' => $this->can('manage'),
        ];

        if ($this->editingKey !== null) {
            $articles = Article::query()->with('stockUnit:id,code')->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name', 'unit', 'stock_unit_id']);
            $unitConversions = ArticleUnitConversion::query()->get(['article_id', 'unit', 'numerator', 'denominator', 'factor_milli'])->groupBy('article_id');
            $data['articles'] = $articles;
            $data['units'] = $articles->mapWithKeys(fn (Article $item): array => [$item->id => StructureUnits::choices(ArticleUnits::stockCode($item), ArticleUnits::fractions($unitConversions->get($item->id, collect())))])->all();
            $data['activeOperations'] = Operation::query()->active()->orderBy('position')->orderBy('name')->get(['id', 'name']);
        }

        if ($this->savingAsKey !== null) {
            $data['outputArticles'] = Article::query()->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name']);
        }

        return view('manufacturing::livewire.row-structures', $data);
    }

    private function document(): SalesDocument
    {
        return SalesDocument::query()->findOrFail($this->documentId);
    }

    /** @return array<string, string> */
    private static function blankLine(string $type): array
    {
        return ['type' => $type, 'article_id' => '', 'quantity' => '', 'unit' => '', 'loss' => '', 'operation_id' => '', 'minutes' => '', 'note' => ''];
    }
}
