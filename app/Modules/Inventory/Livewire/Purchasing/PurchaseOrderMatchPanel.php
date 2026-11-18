<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Modules\Inventory\Actions\Purchasing\AllocateSupplierInvoiceRows;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\OverridePurchaseMatch;
use App\Modules\Inventory\Actions\Purchasing\RecomputePurchaseMatch;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Models\PurchaseMatchAllocation;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Purchasing\Matching\MatchTolerances;
use App\Modules\Inventory\Purchasing\Matching\PurchaseMatchAttest;
use App\Modules\Inventory\Purchasing\PurchaseOrderMatch;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * On a supplier invoice's page, while Lager is on: the purchase orders the
 * invoice is matched with, their goods receipts, and the differences in
 * quantity and price (ADR-071 §2), and the three-way comparison per row
 * (ADR-081 E11): which order row each invoice row pays for, the stored
 * result per row against the company's tolerances, and the override of a
 * deviation with a reason. A person matches and judges; nothing is booked
 * here, and the invoice is approved in its own card.
 */
class PurchaseOrderMatchPanel extends Component
{
    use PurchasingPage;

    public const string NAME = 'inventory.purchase-order-match';

    public const string UPDATED_EVENT = 'purchase-match-updated';

    #[Locked]
    public string $invoiceId;

    public string $orderId = '';

    /** @var array<int|string, array{order_row: string, quantity: string}> by invoice row id */
    public array $allocations = [];

    /** The result whose deviation is being accepted: the one the person saw. */
    public ?int $overrideResultId = null;

    public string $overrideReason = '';

    public function mount(string $invoiceId): void
    {
        abort_unless($this->canPurchase('viewAny'), 403);
        $invoice = SupplierInvoice::query()->findOrFail($invoiceId);
        $this->invoiceId = $invoice->id;

        if (RecomputePurchaseMatch::compares($this->company(), $invoice)) {
            app(RecomputePurchaseMatch::class)($this->company(), $invoice, $this->user());
        }

        $this->fillAllocations();
    }

    public function link(MatchSupplierInvoice $match): void
    {
        if ($this->orderId === '') {
            $this->toast(__('Välj en inköpsorder.'), 'error');

            return;
        }

        $order = PurchaseOrder::query()->findOrFail((int) $this->orderId);

        if ($this->attempt(fn () => $match->link($this->user(), $this->company(), $order, $this->invoice()), __(':order är kopplad till fakturan.', ['order' => $order->title()]), '')) {
            $this->orderId = '';
            $this->changed();
        }
    }

    public function unlink(int $orderId, MatchSupplierInvoice $match): void
    {
        $order = PurchaseOrder::query()->findOrFail($orderId);

        if ($this->attempt(fn () => $match->unlink($this->user(), $this->company(), $order, $this->invoice()), __('Kopplingen till :order är borttagen.', ['order' => $order->title()]), '')) {
            $this->changed();
        }
    }

    public function saveAllocations(AllocateSupplierInvoiceRows $allocate): void
    {
        if ($this->attempt(fn () => $allocate->save($this->user(), $this->company(), $this->invoice(), $this->allocations), __('Fördelningen mot inköpsordern är sparad.'), '')) {
            $this->changed();
        }
    }

    public function suggest(AllocateSupplierInvoiceRows $allocate): void
    {
        $count = 0;

        if (! $this->attempt(function () use ($allocate, &$count): void {
            $count = $allocate->suggest($this->user(), $this->company(), $this->invoice());
        }, '', '')) {
            return;
        }

        $this->toast($count === 0
            ? __('Ingen rad hade ett belopp som stämmer med exakt en orderrad. Välj orderrad för hand.')
            : trans_choice('{1} En rad fördelades efter beloppet. Kontrollera den.|[2,*] :count rader fördelades efter beloppet. Kontrollera dem.', $count, ['count' => $count]));
        $this->changed();
    }

    public function startOverride(int $resultId): void
    {
        $this->overrideResultId = PurchaseMatchResult::query()->where('supplier_invoice_id', $this->invoiceId)->whereKey($resultId)->firstOrFail()->id;
        $this->overrideReason = '';
        $this->resetErrorBag();
    }

    public function cancelOverride(): void
    {
        $this->overrideResultId = null;
        $this->overrideReason = '';
        $this->resetErrorBag();
    }

    public function override(OverridePurchaseMatch $override): void
    {
        if ($this->overrideResultId === null) {
            return;
        }

        if ($this->attempt(fn () => $override($this->user(), $this->company(), $this->invoice(), (int) $this->overrideResultId, $this->overrideReason), __('Avvikelsen är godkänd med ditt skäl.'), '')) {
            $this->cancelOverride();
            $this->changed();
        }
    }

    public function render(): View
    {
        $invoice = $this->invoice();
        $match = PurchaseOrderMatch::forInvoice($invoice);
        $results = RecomputePurchaseMatch::current($invoice);
        $registered = RecomputePurchaseMatch::compares($this->company(), $invoice);
        $orderRows = $match->orders->flatMap(fn (PurchaseOrder $order): Collection => $order->rows->each(fn (PurchaseOrderRow $row) => $row->setRelation('purchaseOrder', $order)))->keyBy('id');
        $invoiceRows = $match->orders->isEmpty() ? collect() : SupplierInvoiceRow::query()->with('account:id,number,name')->where('supplier_invoice_id', $invoice->id)->orderBy('position')->orderBy('id')->get();
        $userIds = $results->pluck('overridden_by')->filter()->unique()->values()->all();

        return view('inventory::livewire.purchasing.match', [
            'match' => $match,
            'canManage' => $this->canPurchase('manage'),
            'registered' => $registered,
            'results' => $results,
            'orderRows' => $orderRows,
            'invoiceRows' => $invoiceRows->keyBy('id'),
            'tolerances' => MatchTolerances::for($this->company()),
            'canOverride' => $registered && $this->canOverride($invoice),
            'overriders' => $userIds === [] ? [] : $this->company()->allUsers()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
        ]);
    }

    /** After every change: the form shows what is stored, and the attest card learns which comparison is on the page. */
    private function changed(): void
    {
        $this->fillAllocations();
        $this->dispatch(self::UPDATED_EVENT);
    }

    private function fillAllocations(): void
    {
        $stored = PurchaseMatchAllocation::query()->where('supplier_invoice_id', $this->invoiceId)->get()->keyBy('supplier_invoice_row_id');
        $this->allocations = SupplierInvoiceRow::query()->where('supplier_invoice_id', $this->invoiceId)->orderBy('position')->orderBy('id')->pluck('id')
            ->mapWithKeys(function (int $id) use ($stored): array {
                $allocation = $stored->get($id);

                return [$id => $allocation instanceof PurchaseMatchAllocation
                    ? ['order_row' => (string) $allocation->purchase_order_row_id, 'quantity' => Quantity::format($allocation->invoiced_milli)]
                    : ['order_row' => '', 'quantity' => '']];
            })->all();
    }

    private function canOverride(SupplierInvoice $invoice): bool
    {
        return $this->user()->can('approve', $invoice)
            && ! PurchaseMatchAttest::preparedBy($this->company(), $this->user(), $invoice);
    }

    private function invoice(): SupplierInvoice
    {
        return SupplierInvoice::query()->findOrFail($this->invoiceId);
    }
}
