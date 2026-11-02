<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockReceipt;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Inleveranser: expected and received goods. A receipt is made on
 * its own or from a supplier invoice (?leverantorsfaktura=<id>), and goods
 * are received in full or line by line. Mobile friendly: one column per
 * line on a phone.
 */
class ReceiptsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'visa')]
    public string $status = 'oppna';

    public bool $creating = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?int $receiving = null;

    /** @var array<int|string, array{quantity: string, location_id: mixed}> */
    public array $receive = [];

    public string $receiveDate = '';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);

        $invoiceId = request()->query('leverantorsfaktura');

        if (is_string($invoiceId) && $invoiceId !== '' && $this->can('count')) {
            $invoice = SupplierInvoice::query()->find($invoiceId);

            if ($invoice instanceof SupplierInvoice) {
                $this->create();
                $this->form['supplier_invoice_id'] = $invoice->id;
                $this->form['supplier_id'] = $invoice->supplier_id;
                $this->form['reference'] = (string) $invoice->invoice_number;
            }
        }
    }

    public function create(): void
    {
        abort_unless($this->can('count'), 403);
        $this->creating = true;
        $this->receiving = null;
        $this->form = ['supplier_id' => null, 'supplier_invoice_id' => null, 'expected_on' => '', 'reference' => '', 'note' => '', 'lines' => [self::blankLine()]];
        $this->resetErrorBag();
    }

    public function addLine(): void
    {
        $this->form['lines'][] = self::blankLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function cancel(): void
    {
        $this->reset('creating', 'form', 'receiving', 'receive');
        $this->resetErrorBag();
    }

    public function save(SaveReceipt $save): void
    {
        $receipt = null;
        $ok = $this->attempt(function () use ($save, &$receipt): void {
            $receipt = $save($this->user(), $this->company(), [
                ...$this->form,
                'supplier_id' => ($this->form['supplier_id'] ?? null) ?: null,
                'supplier_invoice_id' => ($this->form['supplier_invoice_id'] ?? null) ?: null,
                'expected_on' => ($this->form['expected_on'] ?? '') ?: null,
            ]);
        }, __('Inleveransen är skapad.'));

        if ($ok && $receipt instanceof StockReceipt) {
            $this->cancel();
            $this->openReceive($receipt->id);
        }
    }

    public function openReceive(int $id): void
    {
        abort_unless($this->can('count'), 403);
        $receipt = StockReceipt::query()->with('lines')->findOrFail($id);

        $this->creating = false;
        $this->receiving = $receipt->id;
        $this->receiveDate = CarbonImmutable::today()->toDateString();
        $this->receive = [];

        foreach ($receipt->lines as $line) {
            $this->receive[$line->id] = ['quantity' => $line->remainingMilli() > 0 ? Quantity::decimal($line->remainingMilli()) : '0', 'location_id' => $line->location_id];
        }
    }

    public function receiveGoods(ReceiveStock $receiveStock): void
    {
        $receipt = StockReceipt::query()->findOrFail($this->receiving);
        $ok = $this->attempt(fn () => $receiveStock($this->user(), $this->company(), $receipt, $this->receive, CarbonImmutable::parse($this->receiveDate)), __('Varorna är mottagna och finns i lager.'), '');

        if ($ok) {
            $this->cancel();
        }
    }

    public function cancelReceipt(int $id, SaveReceipt $save): void
    {
        $this->attempt(fn () => $save->cancel($this->user(), $this->company(), StockReceipt::query()->findOrFail($id)), __('Inleveransen är makulerad.'), '');
    }

    public function render(): View
    {
        $receipts = StockReceipt::query()
            ->with(['supplier:id,name', 'lines'])
            ->when($this->status === 'oppna', fn ($query) => $query->whereIn('status', [StockReceipt::OPEN, StockReceipt::PARTIAL]))
            ->latest('number')
            ->paginate(25);

        $receiving = $this->receiving === null ? null : StockReceipt::query()->with(['lines.article:id,number,name,unit', 'supplier:id,name'])->find($this->receiving);

        return view('inventory::livewire.receipts', [
            'receipts' => $receipts,
            'receivingReceipt' => $receiving,
            'articles' => $this->creating ? Article::query()->where('is_stock_item', true)->where('is_active', true)->orderBy('number')->get(['id', 'number', 'name', 'unit', 'purchase_price_ore']) : collect(),
            'suppliers' => $this->creating ? Supplier::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'invoices' => $this->creating && ($this->form['supplier_id'] ?? null)
                ? SupplierInvoice::query()->where('supplier_id', (int) $this->form['supplier_id'])->latest('invoice_date')->limit(30)->get(['id', 'invoice_number', 'invoice_date', 'total_ore'])
                : collect(),
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'canCount' => $this->can('count'),
            'seesPrices' => $this->seesPrices(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function blankLine(): array
    {
        return ['article_id' => '', 'quantity' => '', 'location_id' => '', 'unit_cost' => ''];
    }
}
