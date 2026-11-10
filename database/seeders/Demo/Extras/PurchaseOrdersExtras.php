<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\Article;
use App\Models\Supplier;
use App\Modules\Inventory\Actions\Purchasing\CreatePurchaseOrdersFromProposal;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Inköpsorder in the shop (ADR-071 §2, §5), for the manual: an order to the
 * wholesaler sent three weeks ago and partly received, an order to the
 * textile house received in full and matched with its supplier invoice,
 * and a draft made from one line of the purchase proposal, so the proposal
 * still shows the rest. Runs after InventoryExtras (file name
 * order), on its articles and suppliers. Orders are marked as printed, so
 * the demo never sends mail. Made-up quantities and prices.
 */
final class PurchaseOrdersExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-butiker'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Inköpsorder', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $wholesaler = Supplier::query()->where('name', 'Inredningsgrossisten Demo AB')->firstOrFail();
            $textiles = Supplier::query()->where('name', 'Textilhuset Demo AB')->firstOrFail();
            $article = fn (string $number): int => Article::query()->where('number', $number)->firstOrFail()->id;
            $save = app(SavePurchaseOrder::class);
            $send = app(SendPurchaseOrder::class);

            $partly = $save($c->user, $c->company, [
                'supplier_id' => $wholesaler->id,
                'order_date' => $c->today->subDays(21)->toDateString(),
                'requested_delivery_on' => $c->today->subDays(7)->toDateString(),
                'our_reference' => $c->user->name,
                'notes' => 'Leverera gärna allt på en pall.',
                'rows' => [
                    ['article_id' => $article('L-303'), 'quantity' => '6', 'price' => '245', 'expected_on' => $c->today->subDays(7)->toDateString()],
                    ['article_id' => $article('L-304'), 'quantity' => '10', 'price' => '128', 'expected_on' => $c->today->addDays(5)->toDateString()],
                    ['article_id' => $article('L-202'), 'quantity' => '12', 'price' => '86', 'expected_on' => $c->today->addDays(5)->toDateString()],
                ],
            ]);
            $send->printed($c->user, $c->company, $partly);
            $receipt = app(SaveReceipt::class)->fromPurchaseOrder($c->user, $c->company, $partly->refresh());
            $line = $receipt->lines()->where('article_id', $article('L-303'))->firstOrFail();
            app(ReceiveStock::class)($c->user, $c->company, $receipt, [$line->id => ['quantity' => '6']], $c->today->subDays(6));

            $received = $save($c->user, $c->company, [
                'supplier_id' => $textiles->id,
                'order_date' => $c->today->subDays(30)->toDateString(),
                'our_reference' => $c->user->name,
                'rows' => [
                    ['article_id' => $article('T-303'), 'quantity' => '6', 'price' => '205'],
                    ['article_id' => $article('T-202'), 'quantity' => '8', 'price' => '168'],
                ],
            ]);
            $send->printed($c->user, $c->company, $received);
            app(ReceiveStock::class)->all($c->user, $c->company, app(SaveReceipt::class)->fromPurchaseOrder($c->user, $c->company, $received->refresh()), $c->today->subDays(20));

            // 6 × 205 + 8 × 168 = 2 574 kr excluding VAT.
            $invoice = app(SaveSupplierInvoice::class)($c->user, $c->company, [
                'supplier_id' => $textiles->id,
                'invoice_number' => 'TH-9512',
                'invoice_date' => $c->today->subDays(19)->toDateString(),
                'total' => '3 217,50',
                'vat' => '643,50',
                'rows' => [['account' => '4010', 'amount' => '2 574,00', 'description' => 'Linneduk och pläd']],
            ]);
            $invoice = app(RegisterSupplierInvoice::class)($c->user, $invoice);
            app(MatchSupplierInvoice::class)->link($c->user, $c->company, $received->refresh(), $invoice);

            $lines = [];

            foreach (app(PurchaseProposal::class)->lines($c->company) as $proposed) {
                // One textile line, so the proposal page still has the rest to show.
                if ($proposed->article->default_supplier_id === $textiles->id && $lines === []) {
                    $lines[] = ['article_id' => $proposed->article->id, 'quantity' => str_replace('.', ',', $proposed->proposedQuantity), 'unit_id' => $proposed->unit->id];
                }
            }

            if ($lines !== []) {
                app(CreatePurchaseOrdersFromProposal::class)($c->user, $c->company, $lines);
            }
        }));
    }
}
