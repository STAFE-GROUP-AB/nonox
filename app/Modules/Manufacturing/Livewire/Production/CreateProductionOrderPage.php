<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Production;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Tillverkningsorder → Ny (ADR-071 §8): a production order
 * for an article with a bill of materials (stock production), or for a row
 * of an open customer order, which brings the row's quantity and its own
 * copy of the bill of materials. The order page of a customer order links
 * here with the order and the row in the address.
 */
class CreateProductionOrderPage extends Component
{
    use ManufacturingPage;

    public const string ARTICLE = 'artikel';

    public const string SALES_ORDER = 'kundorder';

    #[Url(as: 'kundorder')]
    public string $salesDocumentId = '';

    #[Url(as: 'rad')]
    public string $lineKey = '';

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->can('manage'), 403);

        $this->form = [
            'source' => $this->salesDocumentId !== '' ? self::SALES_ORDER : self::ARTICLE,
            'article_id' => '',
            'quantity' => '',
            'unit' => '',
            'location_id' => '',
            'planned_start_on' => CarbonImmutable::today()->toDateString(),
            'due_on' => '',
            'notes' => '',
        ];
    }

    public function updatedFormArticleId(): void
    {
        $this->form['unit'] = '';
    }

    public function updatedSalesDocumentId(): void
    {
        $this->lineKey = '';
    }

    public function save(SaveProductionOrder $save): void
    {
        abort_unless($this->can('manage'), 403);
        $fromOrder = ($this->form['source'] ?? '') === self::SALES_ORDER;
        $created = null;

        $ok = $this->attempt(function () use ($save, $fromOrder, &$created): void {
            $created = $save->create($this->user(), $this->company(), [
                ...($fromOrder
                    ? ['sales_document_id' => $this->salesDocumentId, 'line_key' => $this->lineKey]
                    : ['article_id' => $this->form['article_id'] ?? '']),
                'quantity' => $this->form['quantity'] ?? '',
                'unit' => $this->form['unit'] ?? '',
                'location_id' => $this->form['location_id'] ?? '',
                'planned_start_on' => $this->form['planned_start_on'] ?? '',
                'due_on' => $this->form['due_on'] ?? '',
                'notes' => $this->form['notes'] ?? '',
            ]);
        }, '');

        if (! $ok || ! $created instanceof ProductionOrder) {
            return;
        }

        foreach ($save->warnings as $warning) {
            Toast::flash($warning, 'warning');
        }

        Toast::flash(__('Tillverkningsorder :number är skapad som utkast. Frisläpp den när den ska göras.', ['number' => $created->reference()]));
        $this->redirectRoute('manufacturing.productionOrders.show', ['order' => $created->id]);
    }

    public function render(): View
    {
        $withBom = BillOfMaterials::query()->where('kind', StructureKind::Bom)->where('is_active', true)->distinct()->pluck('output_article_id');
        $articles = Article::query()->with('stockUnit:id,code')->whereKey($withBom->all())->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name', 'unit', 'stock_unit_id', 'is_stock_item']);
        $fromOrder = ($this->form['source'] ?? '') === self::SALES_ORDER;
        $salesOrders = $fromOrder
            ? SalesDocument::query()->with('customer:id,name')->where('kind', SalesDocument::ORDER)->where('status', SalesDocumentStatus::Open->value)->orderByDesc('number')->limit(200)->get(['id', 'kind', 'number', 'customer_id', 'document_date'])
            : collect();
        $rows = collect();
        $copies = collect();

        if ($fromOrder && $this->salesDocumentId !== '' && $salesOrders->contains('id', $this->salesDocumentId)) {
            $rows = SalesDocumentRow::query()->with('article:id,number,name,is_stock_item')->where('sales_document_id', $this->salesDocumentId)->whereNotNull('article_id')->whereNotNull('line_key')->orderBy('position')->get(['id', 'sales_document_id', 'article_id', 'description', 'quantity', 'unit', 'position', 'line_key']);
            $copies = RowStructure::query()->where('sales_document_id', $this->salesDocumentId)->pluck('line_key')->flip();
        }

        $selected = $fromOrder
            ? $rows->firstWhere('line_key', $this->lineKey)?->article
            : $articles->firstWhere('id', (int) ($this->form['article_id'] ?? 0));
        $units = [];

        if ($selected instanceof Article) {
            $selected->loadMissing('stockUnit:id,code');
            $units = ArticleUnits::choices(ArticleUnits::stockCode($selected), ArticleUnits::fractions(ArticleUnitConversion::query()->where('article_id', $selected->id)->get()));
        }

        return view('manufacturing::livewire.production.create', [
            'articles' => $articles,
            'salesOrders' => $salesOrders,
            'rows' => $rows,
            'copies' => $copies,
            'withBom' => $withBom->flip(),
            'selected' => $selected,
            'units' => $units,
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'fromOrder' => $fromOrder,
        ]);
    }
}
