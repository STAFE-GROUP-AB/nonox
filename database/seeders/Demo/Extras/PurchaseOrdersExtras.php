<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Approval\SaveApprovalPolicy;
use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\Article;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\AllocateSupplierInvoiceRows;
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
 *
 * ADR-081 E10: an approval flow for orders from 10 000 kr, a draft from the
 * bookkeeper that waits for the owner's approval, and a sent order with a
 * service row whose delivery is not yet confirmed.
 *
 * ADR-081 E11: the textile house's invoice has a row per order row and is
 * allocated by amount, so every row is within the tolerances; the
 * wholesaler's invoice for the mirrors that arrived charges 255 kr instead
 * of 245 kr a piece and adds 49 kr freight, so the mirror row is outside the
 * tolerances and the freight passes as a small row without an order row.
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
                'rows' => [
                    ['account' => '4010', 'amount' => '1 230,00', 'description' => 'Linneduk'],
                    ['account' => '4010', 'amount' => '1 344,00', 'description' => 'Pläd'],
                ],
            ]);
            $invoice = app(RegisterSupplierInvoice::class)($c->user, $invoice);
            app(MatchSupplierInvoice::class)->link($c->user, $c->company, $received->refresh(), $invoice);
            app(AllocateSupplierInvoiceRows::class)->suggest($c->user, $c->company, $invoice);

            // Six mirrors arrived at 245 kr; the invoice charges 255 kr a piece and 49 kr freight.
            $mirrors = app(SaveSupplierInvoice::class)($c->user, $c->company, [
                'supplier_id' => $wholesaler->id,
                'invoice_number' => self::SHELF_INVOICE,
                'invoice_date' => $c->today->subDays(5)->toDateString(),
                'total' => '1 973,75',
                'vat' => '394,75',
                'rows' => [
                    ['account' => '4010', 'amount' => '1 530,00', 'description' => 'Spegel rund 50 cm, 6 st'],
                    ['account' => '4010', 'amount' => '49,00', 'description' => 'Frakt'],
                ],
            ]);
            $mirrors = app(RegisterSupplierInvoice::class)($c->user, $mirrors);
            app(MatchSupplierInvoice::class)->link($c->user, $c->company, $partly->refresh(), $mirrors);
            $mirrorRow = $partly->rows()->where('article_id', $article('L-303'))->firstOrFail();
            app(AllocateSupplierInvoiceRows::class)->save($c->user, $c->company, $mirrors, [
                $mirrors->rows()->orderBy('position')->firstOrFail()->id => ['order_row' => $mirrorRow->id, 'quantity' => '6'],
            ]);

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

            $this->approvalAndServices($c, $wholesaler);
        }));
    }

    public const string DRAFT_NOTE = 'Inköp av hyllor och städning inför omläggningen';

    public const string SHELF_INVOICE = 'IG-4410';

    public const string SENT_NOTE = 'Skyltar och uppsättning till skyltfönstret';

    /** A flow for orders from 10 000 kr, an order waiting for it, and a sent order with a service row. */
    private function approvalAndServices(DemoContext $c, Supplier $supplier): void
    {
        config(['mail.default' => 'log']);

        $buyer = $this->member($c, ['name' => 'Elin Inköpare', 'email' => 'elin.inkopare@example.test'], 'bookkeeper');
        $company = $c->company->fresh() ?? $c->company;
        $article = fn (string $number): int => Article::query()->where('number', $number)->firstOrFail()->id;

        app(SaveApprovalPolicy::class)($c->user, $company, [
            'subject_type' => 'PURCHASE_ORDER',
            'name' => 'Inköp från 10 000 kr',
            'priority' => 10,
            'is_active' => true,
            'steps' => [
                ['min_amount' => '10 000', 'approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send', 'required_count' => 1, 'exclude_preparer' => true],
            ],
        ]);

        // 20 × 245 + 7 500 = 12 400 kr: over the flow's amount, so it waits for approval.
        app(SavePurchaseOrder::class)($buyer, $company, [
            'supplier_id' => $supplier->id,
            'order_date' => $c->today->subDays(1)->toDateString(),
            'our_reference' => $buyer->name,
            'notes' => self::DRAFT_NOTE,
            'rows' => [
                ['article_id' => $article('L-303'), 'quantity' => '20', 'price' => '245', 'expected_on' => $c->today->addDays(10)->toDateString()],
                ['description' => 'Städning av butiken, en gång', 'account' => '5060', 'quantity' => '1', 'price' => '7 500', 'expected_on' => $c->today->addDays(9)->toDateString()],
            ],
        ]);

        // Below the amount, so it follows the default policy and goes out; the service row waits for a confirmation.
        $sent = app(SavePurchaseOrder::class)($c->user, $company, [
            'supplier_id' => $supplier->id,
            'order_date' => $c->today->subDays(4)->toDateString(),
            'our_reference' => $c->user->name,
            'notes' => self::SENT_NOTE,
            'rows' => [
                ['article_id' => $article('L-202'), 'quantity' => '10', 'price' => '86', 'expected_on' => $c->today->addDays(3)->toDateString()],
                ['description' => 'Uppsättning av skyltar', 'account' => '5460', 'quantity' => '1', 'price' => '1 200', 'expected_on' => $c->today->addDays(3)->toDateString()],
            ],
        ]);
        app(SendPurchaseOrder::class)->printed($c->user, $company, $sent);
    }

    /** @param  array{name: string, email: string}  $person */
    private function member(DemoContext $c, array $person, string $role): User
    {
        $user = User::query()->firstOrCreate(['email' => $person['email']], ['name' => $person['name'], 'email_verified_at' => now()]);

        if (! $c->company->users()->whereKey($user->id)->exists()) {
            app(AddTeamMember::class)->add($c->user, $c->company, $person['email'], $role);
        }

        return $user->fresh() ?? $user;
    }
}
