<?php

declare(strict_types=1);

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Livewire\Approval\ApprovalPolicies;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\ConvertPurchaseRequisitions;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderLifecycle;
use App\Modules\Inventory\Actions\Purchasing\PurchaseRequisitionLifecycle;
use App\Modules\Inventory\Actions\Purchasing\RejectPurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\ResubmitPurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseRequisition;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Livewire\Purchasing\RequisitionConvertPage;
use App\Modules\Inventory\Livewire\Purchasing\RequisitionEditor;
use App\Modules\Inventory\Livewire\Purchasing\RequisitionPage;
use App\Modules\Inventory\Livewire\Purchasing\RequisitionsPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use App\Modules\ModuleManager;
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
 * ADR-081 E9: purchase requisitions. A requester asks, the request goes through
 * the approval engine (default flow, or a company's own policy), and a buyer
 * turns approved rows into rows of a purchase order, keeping the link. Nothing
 * here books anything.
 */

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->supplier = Purchasing::supplier($this->owner, $this->company);
    $this->other = Purchasing::supplier($this->owner, $this->company, 'Skruvgrossisten AB', 'order@skruv.example');
    $this->screw = Purchasing::screw($this->owner, $this->company, $this->supplier, ['default_location_id' => $this->places['bin2']->id]);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->bookkeeper2 = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'approver');
    $this->approver2 = Bookkeeping::member($this->company, 'approver');
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
    $this->auditor = Bookkeeping::member($this->company, 'auditor');
    $this->company = $this->company->fresh();
    $this->company->forceFill(['separate_supplier_approval' => true])->save();
    $this->company = $this->company->fresh();
});

/** @return array<string, mixed> a row for a stock article: two packs of screws */
function e9Screws(array $extra = []): array
{
    return ['article_id' => test()->screw->id, 'quantity' => '2', 'price' => '', ...$extra];
}

/** @return array<string, mixed> a row in free text on an expense account */
function e9Service(array $extra = []): array
{
    return ['description' => 'Hyra av lift, 2 dagar', 'quantity' => '2', 'price' => '1000', 'account' => '4010', ...$extra];
}

/** @param  list<array<string, mixed>>  $rows */
function e9Requisition(User $by, object $test, array $rows = [], array $extra = []): PurchaseRequisition
{
    return app(SavePurchaseRequisition::class)($by, $test->company, ['needed_by' => '2026-09-01', 'reason' => 'Behövs till bygget', 'rows' => $rows === [] ? [e9Screws()] : $rows, ...$extra]);
}

function e9Submitted(User $by, object $test, array $rows = []): PurchaseRequisition
{
    $requisition = e9Requisition($by, $test, $rows);

    return app(PurchaseRequisitionLifecycle::class)->submit($by, $test->company, $requisition);
}

function e9Approved(object $test, array $rows = [], ?User $by = null): PurchaseRequisition
{
    $requisition = e9Submitted($by ?? $test->bookkeeper, $test, $rows);

    return app(ApprovePurchaseRequisition::class)($test->approver, $test->company, PurchaseRequisitionApprovals::subjectId($requisition));
}

function e9Policy(object $test, array $steps = [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send']], string $name = 'Anmodansflöde'): void
{
    ApprovalFixtures::policy($test->owner, $test->company, $steps, type: 'PURCHASE_REQUISITION', name: $name);
}

function e9Subject(PurchaseRequisition $requisition): string
{
    return PurchaseRequisitionApprovals::subjectId($requisition->fresh());
}

it('lets a requester write a draft with a stock article and a free text row, numbered in its own series', function () {
    $first = e9Requisition($this->bookkeeper, $this, [e9Screws(), e9Service()]);
    $second = e9Requisition($this->bookkeeper, $this);

    expect($first->status)->toBe(PurchaseRequisitionStatus::Draft)
        ->and($first->number)->toBe(1)
        ->and($second->number)->toBe(2)
        ->and($first->requested_by)->toBe($this->bookkeeper->id)
        ->and($first->rows)->toHaveCount(2)
        ->and($first->rows[0]->article_id)->toBe($this->screw->id)
        ->and($first->rows[0]->quantity_milli)->toBe(2000)
        ->and($first->rows[0]->description)->toBe($this->screw->name)
        ->and($first->rows[1]->article_id)->toBeNull()
        ->and($first->rows[1]->estimated_price_ore)->toBe(100000)
        ->and($first->estimatedOre())->toBe(200000)
        ->and($first->hasUnpricedRows())->toBeTrue()
        ->and(Voucher::query()->count())->toBe(0);
});

it('refuses what cannot be saved, with a message under the row', function (string $field, array $row, string $message) {
    expect(fn () => e9Requisition($this->bookkeeper, $this, [$row]))->toThrow(ValidationException::class, $message);
})->with([
    'free text without an account' => ['rows.0.account', ['description' => 'Kaffe', 'quantity' => '1', 'price' => '10'], 'Ange vilket konto'],
    'no description and no article' => ['rows.0.description', ['quantity' => '1', 'account' => '4010'], 'Beskriv vad du behöver'],
    'zero quantity' => ['rows.0.quantity', ['description' => 'Kaffe', 'quantity' => '0', 'account' => '4010'], 'större än noll'],
    'unreadable quantity' => ['rows.0.quantity', ['description' => 'Kaffe', 'quantity' => 'abc', 'account' => '4010'], 'Antalet går inte att läsa'],
    'negative price' => ['rows.0.price', ['description' => 'Kaffe', 'quantity' => '1', 'account' => '4010', 'price' => '-5'], 'inte vara negativt'],
    'a revenue account' => ['rows.0.account', ['description' => 'Kaffe', 'quantity' => '1', 'account' => '3001'], 'kostnadskonto'],
    'an unknown account' => ['rows.0.account', ['description' => 'Kaffe', 'quantity' => '1', 'account' => '4999'], 'finns inte i kontoplanen'],
    'an unknown cost centre' => ['rows.0.cost_center', ['description' => 'Kaffe', 'quantity' => '1', 'account' => '4010', 'cost_center' => 'NOPE'], 'Kostnadsstället NOPE'],
]);

it('refuses no rows, a bad date, a row with a unit the article cannot be bought in, and an unknown supplier', function () {
    $save = app(SavePurchaseRequisition::class);
    $meter = Purchasing::unitId($this->company, 'm');

    expect(fn () => $save($this->bookkeeper, $this->company, ['rows' => []]))->toThrow(ValidationException::class, 'minst en rad')
        ->and(fn () => $save($this->bookkeeper, $this->company, ['needed_by' => '1/9', 'rows' => [e9Screws()]]))->toThrow(ValidationException::class, 'ÅÅÅÅ-MM-DD')
        ->and(fn () => e9Requisition($this->bookkeeper, $this, [e9Screws(['unit_id' => $meter])]))->toThrow(ValidationException::class, 'går inte att köpa i')
        ->and(fn () => e9Requisition($this->bookkeeper, $this, [e9Screws(['supplier_id' => 999999])]))->toThrow(ValidationException::class, 'Leverantören finns inte')
        ->and(PurchaseRequisition::query()->count())->toBe(0);
});

it('needs the create permission, and Lager on', function () {
    expect(fn () => e9Requisition($this->auditor, $this))->toThrow(AuthorizationException::class)
        ->and(fn () => e9Requisition($this->accountant, $this))->toThrow(AuthorizationException::class)
        ->and(fn () => e9Requisition($this->approver, $this))->toThrow(AuthorizationException::class);

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, true);

    expect(fn () => e9Requisition($this->bookkeeper, $this))->toThrow(Exception::class);
});

it('keeps free text out of the audit log', function () {
    e9Requisition($this->bookkeeper, $this, [e9Service(['description' => 'Hemlig beskrivning XYZ'])], ['reason' => 'Hemligt skäl ABC']);

    $payload = AuditLog::query()->where('action', 'inventory.purchase_requisition_saved')->sole()->payload;

    expect(json_encode($payload))->not->toContain('XYZ')->not->toContain('ABC')
        ->and($payload)->toHaveKeys(['number', 'rows', 'round']);
});

it('sends a draft to approval, and an approver approves it in the default flow', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);

    expect($requisition->status)->toBe(PurchaseRequisitionStatus::Submitted);

    $approved = app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition));

    expect($approved->status)->toBe(PurchaseRequisitionStatus::Approved)
        ->and($approved->approved_by)->toBe($this->approver->id)
        ->and($approved->approval_request_id)->toBeNull()
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalDecision::query()->count())->toBe(0);
});

it('lets only those who may send purchase orders approve in the default flow', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);
    $subject = e9Subject($requisition);

    foreach ([$this->bookkeeper2, $this->accountant, $this->auditor] as $user) {
        expect(fn () => app(ApprovePurchaseRequisition::class)($user, $this->company, $subject))->toThrow(AuthorizationException::class);
    }

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted);
});

it('keeps the writer and the last editor out of the approval while separate approval is on', function () {
    // An administrator writes it and may approve in general, but not this one.
    $mine = e9Submitted($this->admin, $this);

    expect(fn () => app(ApprovePurchaseRequisition::class)($this->admin, $this->company, e9Subject($mine)))->toThrow(ValidationException::class, 'kan därför inte attestera');

    // Someone else edits a draft (a buyer's right) and so becomes a preparer.
    $theirs = e9Requisition($this->bookkeeper, $this);
    app(SavePurchaseRequisition::class)($this->admin, $this->company, ['rows' => [e9Screws()]], $theirs);
    app(PurchaseRequisitionLifecycle::class)->submit($this->bookkeeper, $this->company, $theirs->fresh());

    expect($theirs->fresh()->prepared_by)->toBe($this->admin->id)
        ->and(fn () => app(ApprovePurchaseRequisition::class)($this->admin, $this->company, e9Subject($theirs)))->toThrow(ValidationException::class, 'skrev eller ändrade')
        ->and(app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($theirs))->status)->toBe(PurchaseRequisitionStatus::Approved);

    // With the setting off, the writer may approve their own.
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $own = e9Submitted($this->admin, $this);

    expect(app(ApprovePurchaseRequisition::class)($this->admin, $this->company->fresh(), e9Subject($own))->status)->toBe(PurchaseRequisitionStatus::Approved);
});

it('refuses an approval of what the approver did not see: taken back and changed after the page was opened', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);
    $seen = e9Subject($requisition);

    // The requester takes it back, changes the quantity and sends it again.
    app(PurchaseRequisitionLifecycle::class)->takeBack($this->bookkeeper, $this->company, $requisition->fresh());
    app(SavePurchaseRequisition::class)($this->bookkeeper, $this->company, ['rows' => [e9Screws(['quantity' => '2000'])]], $requisition->fresh());
    app(PurchaseRequisitionLifecycle::class)->submit($this->bookkeeper, $this->company, $requisition->fresh());

    expect(fn () => app(ApprovePurchaseRequisition::class)($this->approver, $this->company, $seen))->toThrow(ValidationException::class, 'har ändrats sedan du öppnade sidan')
        ->and(fn () => app(RejectPurchaseRequisition::class)($this->approver, $this->company, $seen))->toThrow(ValidationException::class, 'har ändrats')
        ->and($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted);

    // The page that shows the new round approves it.
    expect(app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition))->status)->toBe(PurchaseRequisitionStatus::Approved);
});

it('binds the page\'s approval to the round it showed', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);

    $page = Livewire::actingAs($this->approver)->test(RequisitionPage::class, ['requisitionId' => $requisition->id])
        ->assertSee('Attestera anmodan');

    // Taken back behind the open page's back: the stale page cannot approve it.
    app(PurchaseRequisitionLifecycle::class)->takeBack($this->bookkeeper, $this->company, $requisition->fresh());
    app(PurchaseRequisitionLifecycle::class)->submit($this->bookkeeper, $this->company, $requisition->fresh());
    $page->call('approve');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted);

    Livewire::actingAs($this->approver)->test(RequisitionPage::class, ['requisitionId' => $requisition->id])->call('approve');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Approved);
});

it('rejects in the default flow, tells the requester why, and lets them send it again unchanged', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);

    app(RejectPurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition), 'För dyrt');
    $rejected = $requisition->fresh();

    expect($rejected->status)->toBe(PurchaseRequisitionStatus::Rejected)
        ->and($rejected->reject_reason)->toBe('För dyrt')
        ->and($rejected->rejected_by)->toBe($this->approver->id)
        ->and(AuditLog::query()->where('action', 'inventory.purchase_requisition_rejected')->sole()->payload)->not->toHaveKey('reason');

    Livewire::actingAs($this->bookkeeper)->test(RequisitionPage::class, ['requisitionId' => $requisition->id])->assertSee('För dyrt')->assertSee('Skicka till attest igen');

    // Only whoever wrote it, or a buyer, sends it again.
    expect(fn () => app(ResubmitPurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition)))->toThrow(AuthorizationException::class);

    app(ResubmitPurchaseRequisition::class)($this->bookkeeper, $this->company, e9Subject($requisition));

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted)
        ->and($requisition->fresh()->reject_reason)->toBeNull();
});

it('lets the requester change a rejected requisition, which starts a new round as a draft', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);
    app(RejectPurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition));

    $changed = app(SavePurchaseRequisition::class)($this->bookkeeper, $this->company, ['rows' => [e9Screws(['quantity' => '3'])]], $requisition->fresh());

    expect($changed->status)->toBe(PurchaseRequisitionStatus::Draft)
        ->and($changed->approval_round)->toBe(2)
        ->and($changed->reject_reason)->toBeNull()
        ->and($changed->rows->sole()->quantity_milli)->toBe(3000);
});

it('refuses a change to a requisition that waits for approval or is approved, and from someone else', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);

    expect(fn () => app(SavePurchaseRequisition::class)($this->bookkeeper, $this->company, ['rows' => [e9Screws()]], $requisition->fresh()))->toThrow(ValidationException::class, 'väntar på attest')
        ->and(fn () => app(SavePurchaseRequisition::class)($this->accountant, $this->company, ['rows' => [e9Screws()]], $requisition->fresh()))->toThrow(AuthorizationException::class);

    app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition));

    expect(fn () => app(SavePurchaseRequisition::class)($this->bookkeeper, $this->company, ['rows' => [e9Screws()]], $requisition->fresh()))->toThrow(ValidationException::class, 'redan avgjord');
});

it('follows a company\'s own policy with two steps: both approve before it is approved', function () {
    e9Policy($this, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send'],
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ]);
    $requisition = e9Submitted($this->bookkeeper, $this);
    $subject = e9Subject($requisition);

    $afterFirst = app(ApprovePurchaseRequisition::class)($this->approver, $this->company, $subject);

    expect($afterFirst->status)->toBe(PurchaseRequisitionStatus::Submitted)
        ->and(ApprovalRequest::query()->sole()->current_step)->toBe(2)
        // The step needs an owner or administrator.
        ->and(fn () => app(ApprovePurchaseRequisition::class)($this->approver2, $this->company, $subject))->toThrow(AuthorizationException::class);

    $done = app(ApprovePurchaseRequisition::class)($this->admin, $this->company, $subject);

    expect($done->status)->toBe(PurchaseRequisitionStatus::Approved)
        ->and($done->approval_request_id)->toBe(ApprovalRequest::query()->sole()->id)
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Approved)
        ->and(ApprovalDecision::query()->count())->toBe(2);
});

it('applies a policy condition on the estimated amount, and falls back to the default flow below it', function () {
    e9Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'min_amount' => '5000']]);

    $small = e9Submitted($this->bookkeeper, $this, [e9Service(['price' => '100'])]);
    $big = e9Submitted($this->bookkeeper, $this, [e9Service(['price' => '9000'])]);

    // Below the condition the policy does not apply: an approver approves with no request rows.
    expect(app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($small))->status)->toBe(PurchaseRequisitionStatus::Approved)
        ->and(ApprovalRequest::query()->count())->toBe(0)
        // Above it, an approver is not enough.
        ->and(fn () => app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($big)))->toThrow(AuthorizationException::class)
        ->and(app(ApprovePurchaseRequisition::class)($this->admin, $this->company, e9Subject($big))->status)->toBe(PurchaseRequisitionStatus::Approved)
        ->and(ApprovalRequest::query()->count())->toBe(1);
});

it('lets an amount condition match a requisition with an unpriced row, and changes nothing without a policy', function () {
    e9Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'min_amount' => '5000']]);

    // 100 kr priced: below the limit, so the default flow. The same with one row lacking a price: the amount is unknown, so the policy applies.
    $priced = e9Submitted($this->bookkeeper, $this, [e9Service(['price' => '100'])]);
    $unpriced = e9Submitted($this->bookkeeper, $this, [e9Service(['price' => '100']), e9Screws()]);

    expect(app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($priced))->status)->toBe(PurchaseRequisitionStatus::Approved)
        ->and(fn () => app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($unpriced)))->toThrow(AuthorizationException::class)
        ->and(app(ApprovePurchaseRequisition::class)($this->admin, $this->company, e9Subject($unpriced))->status)->toBe(PurchaseRequisitionStatus::Approved);
});

it('never lets an upper amount limit match a requisition with an unpriced row', function () {
    e9Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'max_amount' => '500']]);

    $priced = e9Submitted($this->bookkeeper, $this, [e9Service(['price' => '100'])]);
    $unpriced = e9Submitted($this->bookkeeper, $this, [e9Screws()]);

    // Priced and under the cap: the policy applies and needs an administrator. Unpriced: it does not apply, the default flow decides.
    expect(fn () => app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($priced)))->toThrow(AuthorizationException::class)
        ->and(app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($unpriced))->status)->toBe(PurchaseRequisitionStatus::Approved);
});

it('lets stock clerks ask for goods and find the requisitions under Lager', function () {
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $requisition = e9Requisition($clerk, $this);

    expect($requisition->requested_by)->toBe($clerk->id);
    $this->actingAs($clerk)->get(route('inventory.requisitions'))->assertOk()->assertSee('Inköpsanmodan');
    $this->actingAs($clerk)->get(route('inventory.requisitions.show', $requisition->id))->assertOk();
    $this->actingAs($clerk)->get(route('inventory.requisitions.convert'))->assertForbidden();
    expect(fn () => app(ApprovePurchaseRequisition::class)($clerk, $this->company, e9Subject(e9Submitted($clerk, $this))))->toThrow(AuthorizationException::class);
});

it('rejects through a policy and sends it again with a new request, the rejected one staying in the history', function () {
    e9Policy($this);
    $requisition = e9Submitted($this->bookkeeper, $this);
    $subject = e9Subject($requisition);

    app(RejectApproval::class)($this->approver, $this->company, ApprovalSubjectType::PurchaseRequisition, $subject, 'Fel leverantör');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Rejected)
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Rejected)
        ->and(ApprovalDecision::query()->sole()->reason)->toBe('Fel leverantör');

    app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::PurchaseRequisition, $subject);

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted)
        ->and(ApprovalRequest::query()->count())->toBe(2)
        ->and(ApprovalRequest::query()->where('status', ApprovalRequestStatus::Pending->value)->count())->toBe(1);

    app(ApprovePurchaseRequisition::class)($this->approver2, $this->company, $subject);

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Approved);
});

it('lets owners and administrators reject whatever the step says, and nobody else without the right', function () {
    e9Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage']]);
    $requisition = e9Submitted($this->bookkeeper, $this);

    expect(fn () => app(RejectPurchaseRequisition::class)($this->bookkeeper2, $this->company, e9Subject($requisition)))->toThrow(AuthorizationException::class);

    app(RejectPurchaseRequisition::class)($this->admin, $this->company, e9Subject($requisition), 'Nej');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Rejected);
});

it('withdraws the open request when a policy requisition is taken back, and starts a new round', function () {
    e9Policy($this, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send'], ['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage']]);
    $requisition = e9Submitted($this->bookkeeper, $this);
    $other = e9Submitted($this->bookkeeper, $this);

    app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition));

    expect(ApprovalRequest::query()->where('status', ApprovalRequestStatus::Pending->value)->count())->toBe(1);

    app(PurchaseRequisitionLifecycle::class)->takeBack($this->bookkeeper, $this->company, $requisition->fresh());

    expect($requisition->fresh()->approval_round)->toBe(2)
        ->and($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Draft)
        ->and(ApprovalRequest::query()->where('status', ApprovalRequestStatus::Pending->value)->count())->toBe(0)
        ->and(ApprovalRequest::query()->where('status', ApprovalRequestStatus::Withdrawn->value)->count())->toBe(1)
        ->and($other->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted);
});

it('cancels a requisition and ends its open approval, but not one that has been ordered from', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);

    expect(fn () => app(PurchaseRequisitionLifecycle::class)->cancel($this->accountant, $this->company, $requisition))->toThrow(AuthorizationException::class);

    $cancelled = app(PurchaseRequisitionLifecycle::class)->cancel($this->bookkeeper, $this->company, $requisition);

    expect($cancelled->status)->toBe(PurchaseRequisitionStatus::Cancelled)
        ->and(fn () => app(ApprovePurchaseRequisition::class)($this->approver, $this->company, e9Subject($requisition)))->toThrow(ValidationException::class, 'väntar inte på attest');

    $approved = e9Approved($this);
    app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, $approved->rows()->pluck('id')->all(), $this->supplier->id);

    expect(fn () => app(PurchaseRequisitionLifecycle::class)->cancel($this->bookkeeper, $this->company, $approved->fresh()))->toThrow(ValidationException::class, 'redan omvandlad');
});

it('converts approved rows of several requisitions into one draft order to one supplier, keeping the links', function () {
    $one = e9Approved($this, [e9Screws(), e9Service()]);
    $two = e9Approved($this, [e9Screws(['quantity' => '5'])], $this->bookkeeper2);
    $rowIds = [...$one->rows()->pluck('id')->all(), ...$two->rows()->pluck('id')->all()];

    $order = app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, $rowIds, $this->supplier->id);

    expect($order->source)->toBe(PurchaseOrderSource::Requisition)
        ->and($order->status->value)->toBe('DRAFT')
        ->and($order->supplier_id)->toBe($this->supplier->id)
        ->and($order->rows)->toHaveCount(3)
        ->and($order->rows[0]->article_id)->toBe($this->screw->id)
        ->and($order->rows[1]->article_id)->toBeNull()
        ->and($order->rows[1]->account_id)->not->toBeNull()
        ->and($order->rows[2]->quantity_milli)->toBe(1000 * 200 * 5)
        ->and($order->rows[0]->expected_on?->toDateString())->toBe('2026-09-01')
        ->and($order->our_reference)->toBe($this->bookkeeper->name);

    $links = PurchaseRequisitionRow::query()->orderBy('purchase_requisition_id')->orderBy('position')->pluck('purchase_order_row_id')->all();

    expect($links)->toBe($order->rows->pluck('id')->all())
        ->and($one->fresh()->status)->toBe(PurchaseRequisitionStatus::Converted)
        ->and($two->fresh()->status)->toBe(PurchaseRequisitionStatus::Converted)
        ->and(AuditLog::query()->where('action', 'inventory.purchase_requisitions_converted')->sole()->payload['rows'])->toBe(3)
        ->and(Voucher::query()->count())->toBe(0);
});

it('converts only some rows and leaves the requisition approved with the rest to order', function () {
    $requisition = e9Approved($this, [e9Screws(), e9Service()]);
    $first = $requisition->rows()->orderBy('position')->first();

    app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$first->id], $this->supplier->id);

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Approved);

    app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$requisition->rows()->reorder('position', 'desc')->first()->id], $this->supplier->id);

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Converted)
        ->and(PurchaseOrder::query()->count())->toBe(2);
});

it('refuses a row that names another supplier, a row already ordered, and a requisition that is not approved', function () {
    $requisition = e9Approved($this, [e9Screws(['supplier_id' => $this->other->id])]);
    $row = $requisition->rows()->sole();

    expect(fn () => app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$row->id], $this->supplier->id))->toThrow(ValidationException::class, 'annan leverantör');

    app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$row->id], $this->other->id);

    expect(fn () => app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$row->id], $this->other->id))->toThrow(ValidationException::class, 'inte attesterad');

    $unapproved = e9Submitted($this->bookkeeper, $this);

    expect(fn () => app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$unapproved->rows()->sole()->id], $this->supplier->id))->toThrow(ValidationException::class, 'inte attesterad')
        ->and(fn () => app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [], $this->supplier->id))->toThrow(ValidationException::class, 'minst en rad');
});

it('needs the buyer permission to convert', function () {
    $requisition = e9Approved($this);

    foreach ([$this->approver, $this->accountant, $this->auditor] as $user) {
        expect(fn () => app(ConvertPurchaseRequisitions::class)($user, $this->company, $requisition->rows()->pluck('id')->all(), $this->supplier->id))->toThrow(AuthorizationException::class);
    }

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('lets the buyer fill in the price of a free text row when converting', function () {
    $requisition = e9Approved($this, [e9Service(['price' => ''])]);
    $row = $requisition->rows()->sole();

    expect(fn () => app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$row->id], $this->supplier->id))->toThrow(ValidationException::class, 'Ange à-pris');

    $order = app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, [$row->id], $this->supplier->id, [$row->id => ['price' => '450,00', 'account' => '5010']]);

    expect($order->rows->sole()->price_ore)->toBe(45000)
        ->and($order->rows->sole()->account->number)->toBe(5010);
});

it('releases a requisition row when its order row is removed from the draft order, and when the order is cancelled', function () {
    $one = e9Approved($this, [e9Screws(), e9Service()]);
    $order = app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, $one->rows()->pluck('id')->all(), $this->supplier->id);

    expect($one->fresh()->status)->toBe(PurchaseRequisitionStatus::Converted);

    // The buyer drops the service row from the draft order.
    $kept = $order->rows[0];
    app(SavePurchaseOrder::class)($this->bookkeeper, $this->company, [
        'supplier_id' => $this->supplier->id,
        'rows' => [['id' => $kept->id, 'article_id' => $this->screw->id, 'quantity' => '2', 'unit_id' => $kept->unit_id, 'price' => '']],
    ], $order);

    $rows = $one->rows()->orderBy('position')->get();

    expect($one->fresh()->status)->toBe(PurchaseRequisitionStatus::Approved)
        ->and($rows[0]->purchase_order_row_id)->toBe($kept->id)
        ->and($rows[1]->purchase_order_row_id)->toBeNull();

    // Cancelling the order frees the rest.
    app(PurchaseOrderLifecycle::class)->cancel($this->bookkeeper, $this->company, $order->fresh());

    expect($one->rows()->whereNotNull('purchase_order_row_id')->count())->toBe(0)
        ->and($one->fresh()->status)->toBe(PurchaseRequisitionStatus::Approved);

    // And they can be ordered again.
    $again = app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, $one->rows()->pluck('id')->all(), $this->supplier->id);

    expect($again->id)->not->toBe($order->id)
        ->and($one->fresh()->status)->toBe(PurchaseRequisitionStatus::Converted);
});

it('lets the order follow its own approval and sending after the conversion', function () {
    $requisition = e9Approved($this);
    $order = app(ConvertPurchaseRequisitions::class)($this->bookkeeper, $this->company, $requisition->rows()->pluck('id')->all(), $this->supplier->id);

    expect($order->approval_request_id)->toBeNull()
        ->and($order->status->value)->toBe('DRAFT')
        ->and(AuditLog::query()->where('action', 'inventory.purchase_order_saved')->count())->toBe(1);
});

it('shows a requester their requisition and the policy page offers the flow for requisitions only while Lager is on', function () {
    $requisition = e9Submitted($this->bookkeeper, $this);

    Livewire::actingAs($this->bookkeeper)->test(RequisitionsPage::class)->assertSee((string) $requisition->number)->assertSee('Väntar på attest');
    $this->actingAs($this->bookkeeper)->get(route('inventory.requisitions'))->assertOk();
    $this->actingAs($this->bookkeeper)->get(route('inventory.requisitions.show', $requisition->id))->assertOk()->assertSee('Ta tillbaka');
    $this->actingAs($this->bookkeeper)->get(route('inventory.requisitions.create'))->assertOk();
    $this->actingAs($this->auditor)->get(route('inventory.requisitions.create'))->assertForbidden();
    $this->actingAs($this->auditor)->get(route('inventory.requisitions.show', $requisition->id))->assertOk();
    $this->actingAs($this->bookkeeper)->get(route('inventory.requisitions.convert'))->assertOk();
    $this->actingAs($this->approver)->get(route('inventory.requisitions.convert'))->assertForbidden();

    Livewire::actingAs($this->owner)->test(ApprovalPolicies::class)->assertSee('Inköpsanmodan')->assertSee('Nytt flöde för inköpsanmodan');

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, true);

    Livewire::actingAs($this->owner)->test(ApprovalPolicies::class)->assertDontSee('Nytt flöde för inköpsanmodan');
    $this->actingAs($this->bookkeeper)->get(route('inventory.requisitions'))->assertNotFound();
});

it('saves and sends from the editor and the page', function () {
    $this->actingAs($this->bookkeeper);

    Livewire::test(RequisitionEditor::class)
        ->set('form.needed_by', '2026-09-15')
        ->set('form.reason', 'Till bygget')
        ->set('form.rows.0.article_id', (string) $this->screw->id)
        ->set('form.rows.0.quantity', '2')
        ->call('save')
        ->assertHasNoErrors();

    $requisition = PurchaseRequisition::query()->sole();

    expect($requisition->needed_by->toDateString())->toBe('2026-09-15')
        ->and($requisition->rows->sole()->article_id)->toBe($this->screw->id);

    Livewire::test(RequisitionPage::class, ['requisitionId' => $requisition->id])->call('submit');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Submitted);

    Livewire::actingAs($this->approver)->test(RequisitionPage::class, ['requisitionId' => $requisition->id])->call('reject');
    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Rejected);

    Livewire::actingAs($this->bookkeeper)->test(RequisitionEditor::class, ['requisitionId' => $requisition->id])->assertSet('form.rows.0.quantity', '2')->call('save');
    expect($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Draft);
});

it('converts from the buyer page', function () {
    $requisition = e9Approved($this, [e9Screws(), e9Service()]);

    Livewire::actingAs($this->bookkeeper)->test(RequisitionConvertPage::class)
        ->assertSee('Hyra av lift')
        ->set('supplier', (string) $this->supplier->id)
        ->call('selectAll')
        ->call('convert')
        ->assertHasNoErrors();

    expect(PurchaseOrder::query()->sole()->rows)->toHaveCount(2)
        ->and($requisition->fresh()->status)->toBe(PurchaseRequisitionStatus::Converted);
});

it('runs a fixed number of queries on the list, a requisition and the buyer page whatever the number of rows', function () {
    $measure = function (string $see) {
        $last = PurchaseRequisition::query()->latest('id')->first();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->bookkeeper)->test(RequisitionsPage::class)->assertSee((string) $last->number);
        Livewire::actingAs($this->bookkeeper)->test(RequisitionPage::class, ['requisitionId' => $last->id])->assertSee($see);
        Livewire::actingAs($this->bookkeeper)->test(RequisitionConvertPage::class)->assertSee($see);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    e9Approved($this, [e9Screws(), e9Service(['description' => 'Liten sak'])]);
    // The first render loads the user's company and module settings once, whatever the data.
    $measure('Liten sak');
    $small = $measure('Liten sak');

    for ($i = 0; $i < 10; $i++) {
        e9Approved($this, [e9Screws(), e9Service(), e9Service(['description' => 'Frakt '.$i])]);
    }

    e9Approved($this, [e9Screws(), ...array_map(fn (int $i): array => e9Service(['description' => 'Stor sak '.$i]), range(1, 30))]);

    expect($measure('Stor sak 30'))->toBe($small);
});
