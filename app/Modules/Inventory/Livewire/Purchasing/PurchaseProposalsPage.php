<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Models\Supplier;
use App\Modules\Inventory\Actions\Purchasing\CreatePurchaseOrdersFromProposal;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Purchasing\PurchaseDemandSources;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Support\Toast;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lager → Inköpsförslag (ADR-071 §5): per article in stock what is on hand,
 * reserved by open customer orders, coming on open purchase orders and
 * short, against its reorder point, with a proposed quantity in the
 * purchase unit. "Skapa inköpsordrar" makes one draft per supplier from
 * the chosen lines; nothing is sent.
 */
class PurchaseProposalsPage extends Component
{
    use PurchasingPage;

    public const string NAME = 'inventory.purchase-proposals';

    /** @var array<int|string, array{selected: bool, quantity: string, supplier_id: string}> article id => what to order */
    public array $choices = [];

    public function mount(PurchaseProposal $proposal): void
    {
        abort_unless($this->canPurchase('viewAny'), 403);

        foreach ($proposal->lines($this->company()) as $line) {
            $this->choices[$line->article->id] = [
                'selected' => true,
                'quantity' => str_replace('.', ',', $line->proposedQuantity),
                'supplier_id' => (string) ($line->article->default_supplier_id ?? ''),
            ];
        }
    }

    public function create(CreatePurchaseOrdersFromProposal $create, PurchaseProposal $proposal): void
    {
        $units = [];

        foreach ($proposal->lines($this->company()) as $line) {
            $units[$line->article->id] = $line->unit->id;
        }

        $lines = [];

        foreach ($this->choices as $articleId => $choice) {
            if (! $choice['selected'] || ! isset($units[(int) $articleId])) {
                continue;
            }

            $lines[] = ['article_id' => (int) $articleId, 'quantity' => (string) $choice['quantity'], 'unit_id' => $units[(int) $articleId], 'supplier_id' => $choice['supplier_id'] ?: null];
        }

        $orders = [];
        $ok = $this->attempt(function () use ($create, $lines, &$orders): void {
            $orders = $create($this->user(), $this->company(), $lines);
        }, '', '');

        if (! $ok) {
            return;
        }

        Toast::flash(trans_choice('{1} En inköpsorder är skapad som utkast. Granska och skicka den.|[2,*] :count inköpsorder är skapade som utkast, en per leverantör. Granska och skicka dem.', count($orders), ['count' => count($orders)]));
        $this->redirectRoute('inventory.purchaseOrders', ['visa' => 'utkast']);
    }

    public function render(PurchaseProposal $proposal, PurchaseDemandSources $sources): View
    {
        return view('inventory::livewire.purchasing.proposals', [
            'lines' => $proposal->lines($this->company()),
            'sources' => array_map(fn ($source): string => $source->label(), $sources->all()),
            'suppliers' => Supplier::query()->whereNull('processing_restricted_at')->orderBy('name')->get(['id', 'name']),
            'canManage' => $this->canPurchase('manage'),
        ]);
    }
}
