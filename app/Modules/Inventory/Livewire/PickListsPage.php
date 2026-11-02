<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Models\Article;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Plocklistor: make a list from an order, an invoice or by hand,
 * pick it on the phone line by line (the lines in walking order), print it,
 * and complete it, which takes the goods out of stock.
 */
class PickListsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'visa')]
    public string $status = 'oppna';

    #[Url(as: 'lista')]
    public ?int $open = null;

    /** order, invoice or manual while the create form is open */
    public ?string $creating = null;

    public string $sourceId = '';

    /** @var list<array{article_id: mixed, quantity: string}> */
    public array $rows = [];

    /** @var array<int|string, string> picked quantity per line id */
    public array $picked = [];

    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->date = CarbonImmutable::today()->toDateString();

        if ($this->open !== null) {
            $this->show($this->open);
        }
    }

    public function create(string $kind): void
    {
        abort_unless($this->can('count'), 403);
        $this->creating = in_array($kind, ['order', 'invoice', 'manual'], true) ? $kind : 'order';
        $this->sourceId = '';
        $this->rows = [['article_id' => '', 'quantity' => '']];
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        $this->rows[] = ['article_id' => '', 'quantity' => ''];
    }

    public function cancel(): void
    {
        $this->reset('creating', 'sourceId', 'rows');
        $this->resetErrorBag();
    }

    public function make(PickLists $lists): void
    {
        $list = null;
        $ok = $this->attempt(function () use ($lists, &$list): void {
            $list = match ($this->creating) {
                'order' => $lists->fromSource($this->user(), $this->company(), SalesDocument::query()->findOrFail($this->sourceId)),
                'invoice' => $lists->fromSource($this->user(), $this->company(), Invoice::query()->findOrFail($this->sourceId)),
                default => $lists->manual($this->user(), $this->company(), array_values(array_filter($this->rows, fn (array $row): bool => ($row['article_id'] ?? '') !== ''))),
            };
        }, __('Plocklistan är skapad.'), '');

        if ($ok && $list instanceof PickList) {
            $this->cancel();
            $this->show($list->id);
        }
    }

    public function show(int $id): void
    {
        $list = PickList::query()->with('lines')->find($id);

        if (! $list instanceof PickList) {
            $this->open = null;

            return;
        }

        $this->open = $list->id;
        $this->picked = [];

        foreach ($list->lines as $line) {
            $this->picked[$line->id] = Quantity::decimal($line->picked_milli ?? $line->quantity_milli);
        }
    }

    public function close(): void
    {
        $this->reset('open', 'picked');
    }

    public function updatedPicked(mixed $value, string $lineId): void
    {
        $line = PickListLine::query()->where('pick_list_id', $this->open)->findOrFail((int) $lineId);
        $this->attempt(fn () => app(PickLists::class)->pick($this->user(), $this->company(), $line, (string) $value), __('Plockat antal är sparat.'), "picked.{$lineId}.");
    }

    public function complete(PickLists $lists): void
    {
        $list = PickList::query()->findOrFail($this->open);

        foreach ($this->picked as $lineId => $value) {
            $line = PickListLine::query()->where('pick_list_id', $list->id)->find((int) $lineId);

            if ($line instanceof PickListLine && $this->attempt(fn () => $lists->pick($this->user(), $this->company(), $line, (string) $value), '', "picked.{$lineId}.") === false) {
                return;
            }
        }

        if ($this->attempt(fn () => $lists->complete($this->user(), $this->company(), $list, CarbonImmutable::parse($this->date)), __('Plocklistan är klar och varorna är dragna från lagret.'), '')) {
            $this->close();
        }
    }

    public function cancelList(int $id, PickLists $lists): void
    {
        if ($this->attempt(fn () => $lists->cancel($this->user(), $this->company(), PickList::query()->findOrFail($id)), __('Plocklistan är makulerad.'), '')) {
            $this->close();
        }
    }

    public function render(): View
    {
        $creating = $this->creating;

        return view('inventory::livewire.pick-lists', [
            'lists' => PickList::query()->withCount('lines')->with('customer:id,name')
                ->when($this->status === 'oppna', fn ($query) => $query->where('status', PickList::OPEN))
                ->latest('number')->paginate(25),
            'current' => $this->open === null ? null : PickList::query()->with(['lines.article:id,number,name,unit,gtin', 'lines.location:id,full_code,name', 'customer:id,name'])->find($this->open),
            'orders' => $creating === 'order' ? SalesDocument::query()->where('kind', SalesDocument::ORDER)->whereIn('status', [SalesDocumentStatus::Open->value, SalesDocumentStatus::Accepted->value])->with('customer:id,name')->latest('number')->limit(100)->get(['id', 'number', 'customer_id', 'document_date']) : collect(),
            'invoices' => $creating === 'invoice' ? Invoice::query()->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])->with('customer:id,name')->latest('invoice_date')->limit(100)->get(['id', 'number', 'customer_id', 'invoice_date', 'status']) : collect(),
            'articles' => $creating === 'manual' ? Article::query()->where('is_stock_item', true)->orderBy('number')->get(['id', 'number', 'name', 'unit']) : collect(),
            'canCount' => $this->can('count'),
        ]);
    }
}
