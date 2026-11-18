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
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\ConvertPurchaseRequisitions;
use App\Modules\Inventory\Actions\Purchasing\CreatePurchaseOrdersFromProposal;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\PurchaseRequisitionLifecycle;
use App\Modules\Inventory\Actions\Purchasing\RejectPurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
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
 * ADR-081 E9: purchase requisitions from the bookkeeper: one waiting for
 * approval, one approved and waiting for a buyer, one rejected, and one
 * approved and already turned into a draft order.
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

            $buyer = $this->approvalAndServices($c, $wholesaler);
            $this->requisitions($c, $wholesaler, $buyer);
        }));
    }

    public const string DRAFT_NOTE = 'Inköp av hyllor och städning inför omläggningen';

    public const string SENT_NOTE = 'Skyltar och uppsättning till skyltfönstret';

    /** A flow for orders from 10 000 kr, an order waiting for it, and a sent order with a service row. */
    private function approvalAndServices(DemoContext $c, Supplier $supplier): User
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

        return $buyer;
    }

    public const string WAITING_REASON = 'Fyller på inför höstkampanjen';

    public const string APPROVED_REASON = 'Skyltfönstret och hyllorna behöver nytt material';

    /** Purchase requisitions in the four states a requester and a buyer meet (ADR-081 E9). */
    private function requisitions(DemoContext $c, Supplier $supplier, User $requester): void
    {
        $company = $c->company->fresh() ?? $c->company;
        $article = fn (string $number): int => Article::query()->where('number', $number)->firstOrFail()->id;
        $save = app(SavePurchaseRequisition::class);
        $lifecycle = app(PurchaseRequisitionLifecycle::class);
        $approve = app(ApprovePurchaseRequisition::class);
        $subject = fn ($requisition): string => PurchaseRequisitionApprovals::subjectId($requisition->fresh());

        // Waiting for approval: the owner sees an Attestera card, the requester does not.
        $waiting = $save($requester, $company, [
            'needed_by' => $c->today->addDays(14)->toDateString(),
            'reason' => self::WAITING_REASON,
            'rows' => [
                ['article_id' => $article('L-303'), 'quantity' => '12', 'price' => '245', 'supplier_id' => $supplier->id],
                ['description' => 'Skyltmaterial till skyltfönstret', 'account' => '5460', 'quantity' => '1', 'price' => '2 400'],
            ],
        ]);
        $lifecycle->submit($requester, $company, $waiting);

        // Approved and waiting for a buyer to order it.
        $approved = $save($requester, $company, [
            'needed_by' => $c->today->addDays(21)->toDateString(),
            'reason' => self::APPROVED_REASON,
            'rows' => [
                ['article_id' => $article('L-202'), 'quantity' => '20', 'price' => '86', 'supplier_id' => $supplier->id],
                ['description' => 'Städning av butiken, en gång', 'account' => '5060', 'quantity' => '1', 'price' => '3 500'],
            ],
        ]);
        $lifecycle->submit($requester, $company, $approved);
        $approve($c->user, $company, $subject($approved));

        $rejected = $save($requester, $company, [
            'needed_by' => $c->today->addDays(7)->toDateString(),
            'reason' => 'Ny kaffemaskin till personalrummet',
            'rows' => [['description' => 'Kaffemaskin', 'account' => '5460', 'quantity' => '1', 'price' => '14 900']],
        ]);
        $lifecycle->submit($requester, $company, $rejected);
        app(RejectPurchaseRequisition::class)($c->user, $company, $subject($rejected), 'Vänta till nästa budgetår.');

        // Approved and already turned into a draft order.
        $ordered = $save($requester, $company, [
            'needed_by' => $c->today->addDays(10)->toDateString(),
            'reason' => 'Fler hyllfästen till lagret',
            'rows' => [['article_id' => $article('L-304'), 'quantity' => '6', 'price' => '128']],
        ]);
        $lifecycle->submit($requester, $company, $ordered);
        $approve($c->user, $company, $subject($ordered));
        app(ConvertPurchaseRequisitions::class)($requester, $company, $ordered->rows()->pluck('id')->all(), $supplier->id);
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
