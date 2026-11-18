<?php

declare(strict_types=1);

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Actions\Approval\SaveApprovalPolicy;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Livewire\Approval\ApprovalPolicies;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\ConfirmOrderRowDelivery;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderLifecycle;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderEditor;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\Inventory\Purchasing\PurchaseOrderMatch;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * ADR-081 E10: purchase orders through the approval engine (the amount
 * threshold is the default policy, so nothing changes until a company edits
 * a policy; the creator may not send an order above it while separate
 * approval is on) and rows without an article (services and consumables on an
 * account, delivery confirmed by a tick).
 */

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->supplier = Purchasing::supplier($this->owner, $this->company);
    $this->screw = Purchasing::screw($this->owner, $this->company, $this->supplier, ['default_location_id' => $this->places['bin2']->id]);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'approver');
    $this->approver2 = Bookkeeping::member($this->company, 'approver');
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->company = $this->company->fresh();
});

/** A one-row order of the screw, $kr kronor in all (200 screws per pack at the price per pack). */
function e10Order(User $by, object $test, string $kr = '1000'): PurchaseOrder
{
    return Purchasing::order($by, $test->company, $test->supplier, [['article_id' => $test->screw->id, 'quantity' => '1', 'price' => $kr]]);
}

/** @return array<string, mixed> a row without an article */
function e10Service(string $price = '2000', array $extra = []): array
{
    return ['description' => 'Hyra av lift, 2 dagar', 'account' => '4010', 'quantity' => '2', 'price' => $price, ...$extra];
}

function e10Policy(object $test, array $steps = [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send']], string $name = 'Inköpsflöde'): void
{
    ApprovalFixtures::policy($test->owner, $test->company, $steps, type: 'PURCHASE_ORDER', name: $name);
}

function e10Limit(object $test, int $ore = 50000): void
{
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $test->company, $test->owner, ['purchase_order_approval_above_ore' => $ore]);
}

it('changes nothing for a company without a policy: no request rows and the same sending', function () {
    $small = e10Order($this->bookkeeper, $this);
    $big = e10Order($this->bookkeeper, $this, '9000');

    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $small);
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $big);

    expect($small->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($big->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalDecision::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'approval.%')->count())->toBe(0)
        ->and($small->versions()->sole()->approved_by)->toBeNull()
        ->and($small->versions()->sole()->approval_request_id)->toBeNull();
});

it('keeps the threshold as the default policy and records the sender as approver above it', function () {
    e10Limit($this);
    $order = e10Order($this->bookkeeper, $this, '1000');

    expect(fn () => app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order))->toThrow(AuthorizationException::class);

    app(SendPurchaseOrder::class)->printed($this->approver, $this->company, $order);

    $version = $order->versions()->sole();
    expect($version->approved_by)->toBe($this->approver->id)
        ->and($version->approval_request_id)->toBeNull()
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('does not let the creator send an order above the threshold while separate approval is on (owner decision 9)', function () {
    e10Limit($this);
    $order = e10Order($this->admin, $this, '1000');

    expect(fn () => app(SendPurchaseOrder::class)->printed($this->admin, $this->company, $order))
        ->toThrow(ValidationException::class, 'Du skapade eller ändrade ordern och kan därför inte skicka den');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft);

    // Another approver may; and with the rule off the creator may too.
    app(SendPurchaseOrder::class)->printed($this->approver2, $this->company, $order);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent);

    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $second = e10Order($this->admin, $this, '1000');
    app(SendPurchaseOrder::class)->printed($this->admin, $this->company->fresh(), $second);

    expect($second->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('lets the creator send an order below the threshold whatever the rule says', function () {
    e10Limit($this, 500000);
    $order = e10Order($this->owner, $this, '1000');

    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('holds an order a policy applies to until the steps have approved it, then sends it with the approval kept on the version', function () {
    e10Policy($this, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send', 'min_amount' => '500'],
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->approver2->id], 'min_amount' => '500'],
    ]);
    $order = e10Order($this->bookkeeper, $this, '1000');

    expect(fn () => app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order))
        ->toThrow(ValidationException::class, 'väntar på attest');

    // The bookkeeper who made it cannot approve, and has no right to anyway.
    expect(fn () => app(ApprovePurchaseOrder::class)($this->bookkeeper, $this->company, PurchaseOrderApprovals::subjectId($order->fresh())))->toThrow(AuthorizationException::class);

    $order = app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));
    expect($order->isApproved())->toBeFalse()
        ->and(fn () => app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order))->toThrow(ValidationException::class, 'väntar på attest');

    $order = app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));

    expect($order->isApproved())->toBeTrue()
        ->and($order->approved_by)->toBe($this->approver2->id);

    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);

    $version = $order->versions()->sole();
    $request = ApprovalRequest::query()->sole();
    expect($version->approval_request_id)->toBe($request->id)
        ->and($version->approved_by)->toBe($this->approver2->id)
        ->and($request->status)->toBe(ApprovalRequestStatus::Approved)
        ->and($request->subject_id)->toBe($order->id.'.1')
        ->and($request->subject_type)->toBe(ApprovalSubjectType::PurchaseOrder)
        ->and(ApprovalDecision::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'inventory.purchase_order_approved')->sole()->payload)->toEqual(['number' => $order->number, 'version' => 1, 'round' => 1, 'approval_request_id' => $request->id])
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('follows the default policy for an order below the policy\'s amount', function () {
    e10Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send', 'min_amount' => '5000']]);
    $order = e10Order($this->bookkeeper, $this, '1000');

    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(fn () => app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId(e10Order($this->bookkeeper, $this, '100')->fresh())))->toThrow(ValidationException::class, 'standardflödet');
});

it('keeps the creator out of the steps while separate approval is on', function () {
    e10Policy($this);
    $order = e10Order($this->admin, $this, '1000');

    expect(fn () => app(ApprovePurchaseOrder::class)($this->admin, $this->company, PurchaseOrderApprovals::subjectId($order->fresh())))->toThrow(ValidationException::class);

    app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));

    expect($order->fresh()->isApproved())->toBeTrue();
});

it('rejects an order back to its creator, who sends it to approval again', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);

    app(RejectApproval::class)($this->approver, $this->company, ApprovalSubjectType::PurchaseOrder, PurchaseOrderApprovals::subjectId($order), 'Fel leverantör');

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Rejected)
        ->and(fn () => app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($order->fresh())))->toThrow(ValidationException::class, 'avvisades')
        ->and(fn () => app(ResubmitApproval::class)($this->approver, $this->company, ApprovalSubjectType::PurchaseOrder, PurchaseOrderApprovals::subjectId($order)))->toThrow(AuthorizationException::class);

    app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::PurchaseOrder, PurchaseOrderApprovals::subjectId($order));
    $order = app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));

    expect($order->isApproved())->toBeTrue()
        ->and(ApprovalRequest::query()->count())->toBe(2);
});

it('starts a new approval round when an approved draft is saved again', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);
    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));
    expect($order->fresh()->isApproved())->toBeTrue();

    $saved = app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '9999']]], $order);

    expect($saved->isApproved())->toBeFalse()
        ->and($saved->approval_round)->toBe(2)
        ->and(fn () => app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $saved))->toThrow(ValidationException::class, 'väntar på attest');

    app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($saved->fresh()));

    expect($saved->fresh()->isApproved())->toBeTrue()
        ->and(ApprovalRequest::query()->orderBy('id')->pluck('subject_id')->all())->toBe([$order->id.'.1', $order->id.'.2'])
        ->and(AuditLog::query()->where('action', 'inventory.purchase_order_approval_reset')->count())->toBe(1);
});

it('withdraws an open request when the order is saved again or cancelled', function () {
    e10Policy($this, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send'],
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send'],
    ]);
    $order = e10Order($this->bookkeeper, $this);
    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));
    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Pending);

    app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $this->screw->id, 'quantity' => '2', 'price' => '1000']]], $order);
    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Withdrawn);

    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));
    app(PurchaseOrderLifecycle::class)->cancel($this->bookkeeper, $this->company, $order->fresh());

    expect(ApprovalRequest::query()->orderByDesc('id')->first()->status)->toBe(ApprovalRequestStatus::Withdrawn)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('needs a new approval for the next version of a sent order, and keeps the first approval on its version', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);
    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);

    $revised = app(PurchaseOrderLifecycle::class)->revise($this->bookkeeper, $this->company, $order->fresh());

    expect($revised->version)->toBe(2)
        ->and($revised->isApproved())->toBeFalse()
        ->and(fn () => app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $revised))->toThrow(ValidationException::class, 'väntar på attest');

    app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($revised->fresh()));
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $revised->fresh());

    $versions = $order->versions()->reorder('version')->get();
    expect($versions->pluck('approved_by')->all())->toBe([$this->approver->id, $this->approver2->id])
        ->and($versions->pluck('approval_request_id')->unique()->count())->toBe(2);
});

it('keeps a policy from the owner, a decision by a delegate and the engine\'s rules for named people', function () {
    e10Policy($this, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->approver->id]]]);
    $order = e10Order($this->bookkeeper, $this);

    expect(fn () => app(ApprovePurchaseOrder::class)($this->approver2, $this->company, PurchaseOrderApprovals::subjectId($order->fresh())))->toThrow(AuthorizationException::class);

    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));

    expect($order->fresh()->isApproved())->toBeTrue();
});

it('tests the cost centre, project, supplier and account conditions on rows without an article', function () {
    $center = CurrentCompany::run($this->company, fn (): CostCenter => CostCenter::create(['code' => '20', 'name' => 'Verkstad']));
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send', 'cost_center_id' => $center->id]], type: 'PURCHASE_ORDER');

    $plain = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [e10Service()]);
    $dimensioned = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [e10Service('2000', ['cost_center' => '20'])]);

    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $plain);

    expect($plain->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(fn () => app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $dimensioned))->toThrow(ValidationException::class, 'väntar på attest');

    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($dimensioned->fresh()));
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $dimensioned->fresh());

    expect($dimensioned->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('is off with Lager: the policy cannot be made and no order is found', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);
    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(fn () => e10Policy($this, name: 'Ett till'))->toThrow(Exception::class)
        ->and(fn () => app(RejectApproval::class)($this->owner, $this->company, ApprovalSubjectType::PurchaseOrder, PurchaseOrderApprovals::subjectId($order)))->toThrow(Exception::class)
        ->and(fn () => app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh())))->toThrow(Exception::class);
});

it('stores a row without an article with its account, cost centre and project, and counts it in the total', function () {
    CurrentCompany::run($this->company, fn () => CostCenter::create(['code' => '20', 'name' => 'Verkstad']));
    $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [
        ['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189'],
        e10Service('2000,50', ['cost_center' => '20', 'quantity' => '2,5']),
    ]);

    $order->rows->load(['account', 'costCenter', 'unit']);
    [$stock, $service] = $order->rows->all();

    expect($service->article_id)->toBeNull()
        ->and($service->isStock())->toBeFalse()
        ->and($service->account->number)->toBe(4010)
        ->and($service->costCenter->code)->toBe('20')
        ->and($service->entered_quantity)->toBe('2.5')
        ->and($service->quantity_milli)->toBe(2500)
        ->and($service->unit->code)->toBe('st')
        ->and($service->valueOre())->toBe(500125)
        ->and($service->remainingMilli())->toBe(0)
        ->and($stock->isStock())->toBeTrue()
        ->and($order->totalOre())->toBe($stock->valueOre() + 500125);
});

it('refuses a row without an article that lacks a description, a known account, a price or a required dimension', function () {
    $save = fn (array $row) => fn () => Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [$row]);

    expect($save(e10Service('2000', ['description' => ''])))->toThrow(ValidationException::class, 'Beskriv vad som beställs');
    expect($save(e10Service('2000', ['account' => ''])))->toThrow(ValidationException::class, 'Ange ett konto');
    expect($save(e10Service('2000', ['account' => '9999']))->__invoke(...))->toThrow(ValidationException::class, 'Ange ett konto');
    expect($save(e10Service('')))->toThrow(ValidationException::class, 'Ange à-pris');
    expect($save(e10Service('2000', ['quantity' => '0'])))->toThrow(ValidationException::class, 'större än noll');
    expect($save(e10Service('2000', ['cost_center' => '99'])))->toThrow(ValidationException::class, 'Kostnadsstället 99');

    CurrentCompany::run($this->company, fn () => DB::table('accounts')->where('number', 4010)->update(['requires_project' => true]));

    expect($save(e10Service()))->toThrow(ValidationException::class, 'kräver ett projekt');
});

it('confirms the delivery of a row without an article with no stock movement, and the order becomes received', function () {
    $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [
        ['article_id' => $this->screw->id, 'quantity' => '1', 'price' => '189'],
        e10Service(),
    ]);
    $service = $order->rows->last();

    expect(fn () => app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order, $service->id))->toThrow(ValidationException::class, 'skickad');

    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);
    $movements = DB::table('stock_movements')->count();

    app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $service->id);

    expect($service->fresh()->confirmed_at)->not->toBeNull()
        ->and($service->fresh()->confirmed_by)->toBe($this->bookkeeper->id)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(fn () => app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $order->rows->first()->id))->toThrow(ValidationException::class, 'lagerartikel')
        ->and(fn () => app(PurchaseOrderLifecycle::class)->cancel($this->bookkeeper, $this->company, $order->fresh()))->toThrow(ValidationException::class, 'bekräftad');

    // The goods receipt asks only for the stock row.
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh());
    expect($receipt->lines()->count())->toBe(1)
        ->and($receipt->lines()->sole()->article_id)->toBe($this->screw->id);

    app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $service->id, false);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(AuditLog::query()->where('action', 'like', 'inventory.purchase_order_row_delivery_%')->count())->toBe(2);
});

it('is received from the confirmation alone on an order of services only', function () {
    $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [e10Service()]);
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);

    expect(fn () => app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh()))->toThrow(ValidationException::class, 'tagits emot');

    app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $order->rows()->sole()->id);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('counts a confirmed row as delivered in the match with the supplier invoice, and flags one that is not', function () {
    $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [e10Service('1000')]);
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);
    $row = $order->rows()->sole();
    $invoice = Purchasing::invoice($this->owner, $this->company, $this->supplier, '2000');
    app(MatchSupplierInvoice::class)->link($this->bookkeeper, $this->company, $order, $invoice);

    $before = CurrentCompany::run($this->company, fn (): PurchaseOrderMatch => PurchaseOrderMatch::forInvoice($invoice));
    expect($before->receivedOre)->toBe(0)
        ->and(implode(' ', $before->flags))->toContain('leveransen är inte bekräftad');

    app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $row->id);

    $after = CurrentCompany::run($this->company, fn (): PurchaseOrderMatch => PurchaseOrderMatch::forInvoice($invoice));
    expect($after->receivedOre)->toBe(200000)
        ->and(implode(' ', $after->flags))->not->toContain('inte bekräftad');
});

it('keeps the confirmed row on the order when it is saved again', function () {
    $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [e10Service(), ['article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100']]);
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);
    $row = $order->rows()->whereNull('article_id')->sole();
    $stockRow = $order->rows()->whereNotNull('article_id')->sole();
    app(ConfirmOrderRowDelivery::class)($this->bookkeeper, $this->company, $order->fresh(), $row->id);
    $revised = app(PurchaseOrderLifecycle::class)->revise($this->bookkeeper, $this->company, $order->fresh());

    expect(fn () => app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $row->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '1'], ['id' => $stockRow->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100']]], $revised))
        ->toThrow(ValidationException::class, 'bekräftad');

    expect(fn () => app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $stockRow->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100']]], $revised))
        ->toThrow(ValidationException::class, 'bekräftad');

    // A confirmed row keeps its quantity and price in the new version.
    expect(fn () => app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [e10Service('2500', ['id' => $row->id]), ['id' => $stockRow->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100']]], $revised))
        ->toThrow(ValidationException::class, 'antal och à-pris kan inte ändras');

    $kept = app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [e10Service('2000', ['id' => $row->id, 'description' => 'Hyra av lift, ny text']), ['id' => $stockRow->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100']]], $revised);

    expect($kept->rows->first()->confirmed_at)->not->toBeNull()
        ->and($kept->rows->first()->description)->toBe('Hyra av lift, ny text')
        ->and($kept->rows->first()->price_ore)->toBe(200000);
});

it('shows the approval card with Attestera for an approver and hides sending until approved', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);

    $this->actingAs($this->bookkeeper);
    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])
        ->assertSee('Attestflöde')
        ->assertSee('kan skickas först när den är attesterad')
        ->assertDontSee('Attestera ordern')
        ->assertDontSee('Skicka till leverantören');

    $this->actingAs($this->approver);
    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])
        ->assertSee('Attestera ordern')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Skicka till leverantören');

    expect($order->fresh()->isApproved())->toBeTrue();

    $this->actingAs($this->bookkeeper);
    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])->assertSee('Skicka till leverantören');
});

it('lets an approver reject from the page and the creator send it back', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);

    $this->actingAs($this->approver);
    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])->set('rejectReason', 'För dyrt')->call('reject')->assertHasNoErrors()->assertSee('Avvisad i attesten');

    $this->actingAs($this->bookkeeper);
    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])->assertSee('Skicka till attest igen')->call('resubmit')->assertHasNoErrors();

    expect(ApprovalRequest::query()->count())->toBe(2)
        ->and(ApprovalRequest::query()->orderByDesc('id')->first()->status)->toBe(ApprovalRequestStatus::Pending);
});

it('adds a row without an article in the editor', function () {
    Livewire::test(PurchaseOrderEditor::class)
        ->set('form.supplier_id', (string) $this->supplier->id)
        ->set('form.rows.0.free', true)
        ->set('form.rows.0.description', 'Städning av lokal')
        ->set('form.rows.0.account', '4010')
        ->set('form.rows.0.quantity', '1')
        ->set('form.rows.0.price', '1 500')
        ->call('save')
        ->assertHasNoErrors();

    $row = PurchaseOrder::query()->sole()->rows()->sole();
    expect($row->article_id)->toBeNull()->and($row->description)->toBe('Städning av lokal')->and($row->price_ore)->toBe(150000);

    Livewire::test(PurchaseOrderEditor::class, ['orderId' => $row->purchase_order_id])
        ->assertSet('form.rows.0.free', true)
        ->assertSet('form.rows.0.account', '4010')
        ->assertSee('Utan artikel');
});

it('offers a purchase order flow on the policies page only while Lager is on', function () {
    Livewire::test(ApprovalPolicies::class)->assertSee('Inköpsorder')->assertSee('Nytt flöde för inköpsorder');

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);

    Livewire::test(ApprovalPolicies::class)->assertDontSee('Nytt flöde för inköpsorder');
});

it('runs a fixed number of queries on the order page however many rows and decisions it has', function () {
    e10Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send']]);

    $count = function (int $rows): int {
        $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, array_map(fn (int $index): array => $index % 2 === 0
            ? ['article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100']
            : e10Service('100'), range(0, $rows - 1)));
        $this->actingAs($this->approver);
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count(2);
    $short = $count(2);
    $long = $count(30);

    expect($long)->toBe($short);
});

it('registers the purchase order approval in the audit log with ids and counts only', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);
    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($order->fresh()));

    $entry = AuditLog::query()->where('action', 'approval.requested')->sole();

    expect(json_encode($entry->payload))->not->toContain('Hyra')->not->toContain('Virkes')
        ->and($entry->payload['subject_type'])->toBe('PURCHASE_ORDER');
});

it('does not let a policy be saved for a purchase order with a user who cannot send orders', function () {
    expect(fn () => app(SaveApprovalPolicy::class)($this->owner, $this->company, [
        'subject_type' => 'PURCHASE_ORDER', 'name' => 'Fel', 'priority' => 1, 'is_active' => true,
        'steps' => [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->bookkeeper->id], 'required_count' => 1, 'exclude_preparer' => false]],
    ]))->toThrow(ValidationException::class);
});

it('does not leave stock receipt lines for rows without an article when a sent order is changed', function () {
    $order = Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1', 'price' => '100'], e10Service()]);
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $order);
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh());
    $revised = app(PurchaseOrderLifecycle::class)->revise($this->bookkeeper, $this->company, $order->fresh());

    app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [
        ['id' => $revised->rows->first()->id, 'article_id' => $this->screw->id, 'quantity' => '2', 'price' => '100'],
        e10Service('2000', ['id' => $revised->rows->last()->id]),
    ]], $revised);
    app(SendPurchaseOrder::class)->printed($this->bookkeeper, $this->company, $revised->fresh());

    expect(StockReceipt::query()->findOrFail($receipt->id)->lines()->count())->toBe(1);
});

it('refuses to approve what was saved after the approver opened the page', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this, '1000');

    $this->actingAs($this->approver);
    $page = Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])->assertSee('Attestera ordern');

    // The creator changes the price while the approver looks at the page.
    app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '9000']]], $order);

    $page->call('approve');
    $page->assertHasErrors();

    expect($order->fresh()->isApproved())->toBeFalse()
        ->and(ApprovalDecision::query()->count())->toBe(0)
        ->and(fn () => app(ApprovePurchaseOrder::class)($this->approver, $this->company, $order->id.'.1'))->toThrow(ValidationException::class, 'har ändrats sedan du öppnade sidan');

    // The page shows the new round, and approving then works.
    $page->call('$refresh')->call('approve')->assertHasNoErrors();
    expect($order->fresh()->isApproved())->toBeTrue();
});

it('raises the approval round on every save, with or without a request', function () {
    $order = e10Order($this->bookkeeper, $this);
    expect($order->approval_round)->toBe(1);

    $saved = app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $this->screw->id, 'quantity' => '2', 'price' => '1000']]], $order);

    expect($saved->approval_round)->toBe(2)
        ->and(AuditLog::query()->where('action', 'inventory.purchase_order_approval_reset')->count())->toBe(0);
});

it('keeps whoever last edited an order out of approving and sending it, as well as its creator', function () {
    e10Policy($this);
    $order = e10Order($this->bookkeeper, $this);
    $edited = app(SavePurchaseOrder::class)($this->admin, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '1100']]], $order);

    expect($edited->prepared_by)->toBe($this->admin->id)
        ->and($edited->created_by)->toBe($this->bookkeeper->id)
        ->and(fn () => app(ApprovePurchaseOrder::class)($this->admin, $this->company, PurchaseOrderApprovals::subjectId($edited->fresh())))->toThrow(ValidationException::class);

    app(ApprovePurchaseOrder::class)($this->approver, $this->company, PurchaseOrderApprovals::subjectId($edited->fresh()));
    expect($edited->fresh()->isApproved())->toBeTrue();
});

it('keeps the last editor from sending an order above the threshold', function () {
    e10Limit($this);
    $order = e10Order($this->bookkeeper, $this, '1000');
    app(SavePurchaseOrder::class)($this->admin, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $order->rows()->sole()->id, 'article_id' => $this->screw->id, 'quantity' => '1', 'price' => '1100']]], $order);

    expect(fn () => app(SendPurchaseOrder::class)->printed($this->admin, $this->company, $order->fresh()))->toThrow(ValidationException::class, 'skapade eller ändrade');

    app(SendPurchaseOrder::class)->printed($this->approver, $this->company, $order->fresh());
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('accepts only expense and fixed-asset accounts on a row without an article', function () {
    expect(fn () => Purchasing::order($this->bookkeeper, $this->company, $this->supplier, [e10Service('2000', ['account' => '1930'])]))
        ->toThrow(ValidationException::class, 'kostnadskonto');
});
