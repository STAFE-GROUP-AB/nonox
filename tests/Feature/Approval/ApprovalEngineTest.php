<?php

declare(strict_types=1);

use App\Actions\Approval\ManageApprovalDelegations;
use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Actions\Approval\SaveApprovalPolicy;
use App\Actions\Approval\SetDimensionOwner;
use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\SettlePaymentBatch;
use App\Domain\Approval\ApprovalDimension;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\StepConditions;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 gap 8, E2: a company's own approval policies. Steps in order with
 * conditions, approvers and counts; the subject's own effect only after the
 * last step; rejection back to the preparer; substitutes "på uppdrag av";
 * segregation that a policy cannot switch off; a frozen policy copy per
 * request; append-only decisions.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->supplier = ApprovalFixtures::supplier($this->owner, $this->company);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'approver');
    $this->manager = Bookkeeping::member($this->company, 'approver');
    $this->auditor = Bookkeeping::member($this->company, 'auditor');
    $this->company = $this->company->fresh();
});

function e2Approve(User $user, mixed $invoice): mixed
{
    return app(ApproveSupplierInvoice::class)($user, $invoice->fresh());
}

it('needs every step, in order, before the invoice is approved', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
        ['min_amount' => '50 000', 'approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ], name: 'Stora inköp');
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, '60000');

    expect(fn () => e2Approve($this->owner, $invoice))->toThrow(AuthorizationException::class);

    expect(e2Approve($this->manager, $invoice)->status)->toBe(SupplierInvoiceStatus::Registered)
        ->and(ApprovalRequest::query()->sole()->current_step)->toBe(2)
        ->and(AuditLog::query()->where('action', 'supplier_invoice.approved')->exists())->toBeFalse();

    $approved = e2Approve($this->owner, $invoice);
    $request = ApprovalRequest::query()->sole();

    expect($approved->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($approved->approved_by)->toBe($this->owner->id)
        ->and($request->status)->toBe(ApprovalRequestStatus::Approved)
        ->and($request->snapshot()->count())->toBe(2)
        ->and($request->snapshot()->name)->toBe('Stora inköp')
        ->and(ApprovalDecision::query()->orderBy('id')->pluck('user_id')->all())->toBe([$this->manager->id, $this->owner->id])
        ->and(AuditLog::query()->where('action', 'supplier_invoice.approved')->sole()->user_id)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'approval.decided')->count())->toBe(2);
});

it('skips steps whose conditions do not apply, and falls back to the default policy when none applies', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['max_amount' => '10 000', 'approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
        ['min_amount' => '50 000', 'approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ]);
    ApprovalFixtures::policy($this->owner, $this->company, [['account_from' => '5000', 'account_to' => '5999', 'approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]], name: 'Lokaler', priority: 20);

    $small = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, '5000', number: 'S');
    $middle = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, '20000', number: 'M');

    expect(e2Approve($this->manager, $small)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(ApprovalRequest::query()->sole()->snapshot()->count())->toBe(1);

    // 20 000 kr on account 4010: neither policy has a step for it, so today's single attest applies.
    expect(fn () => e2Approve($this->auditor, $middle))->toThrow(AuthorizationException::class)
        ->and(e2Approve($this->approver, $middle)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(ApprovalRequest::query()->count())->toBe(1);
});

it('picks the first matching policy by priority', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->approver->id]]], name: 'Andra', priority: 20);
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]], name: 'Första', priority: 5);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    expect(fn () => e2Approve($this->approver, $invoice))->toThrow(AuthorizationException::class)
        ->and(e2Approve($this->manager, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(ApprovalRequest::query()->sole()->snapshot()->name)->toBe('Första');
});

it('counts distinct approvers when a step needs more than one', function () {
    $second = Bookkeeping::member($this->company, 'approver');
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve', 'required_count' => 2]]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    expect(e2Approve($this->approver, $invoice)->status)->toBe(SupplierInvoiceStatus::Registered)
        ->and(fn () => e2Approve($this->approver, $invoice))->toThrow(ValidationException::class, 'redan godkänt')
        ->and(e2Approve($second, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('keeps the preparer out of every step while the company requires separate approval, whatever the policy says', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'exclude_preparer' => false]]);
    $invoice = ApprovalFixtures::invoice($this->owner, $this->company, $this->supplier);

    expect($this->company->fresh()->separate_supplier_approval)->toBeTrue()
        ->and(fn () => e2Approve($this->owner, $invoice))->toThrow(ValidationException::class, 'förberedde underlaget')
        ->and($this->owner->can('approve', $invoice->fresh()))->toBeFalse();

    $this->company->forceFill(['separate_supplier_approval' => false])->save();

    expect(e2Approve($this->owner, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('lets a policy keep the preparer out although the company allows it', function () {
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'exclude_preparer' => true]]);
    $invoice = ApprovalFixtures::invoice($this->owner, $this->company, $this->supplier);

    expect(fn () => e2Approve($this->owner, $invoice))->toThrow(ValidationException::class, 'förberedde underlaget');
});

it('returns a rejected invoice to its preparer, who can send it to approval again', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    app(RejectApproval::class)($this->manager, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id, 'Fel belopp, kontrollera leveransen.');

    $rejected = ApprovalRequest::query()->sole();

    expect($rejected->status)->toBe(ApprovalRequestStatus::Rejected)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Registered)
        ->and(ApprovalDecision::query()->sole()->reason)->toBe('Fel belopp, kontrollera leveransen.')
        ->and(AuditLog::query()->where('action', 'approval.decided')->sole()->payload)->not->toHaveKey('reason')
        ->and(fn () => e2Approve($this->manager, $invoice))->toThrow(ValidationException::class, 'avvisades')
        ->and(fn () => app(ResubmitApproval::class)($this->approver, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id))->toThrow(AuthorizationException::class);

    app(ResubmitApproval::class)($this->bookkeeper, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id);

    expect(ApprovalRequest::query()->count())->toBe(2)
        ->and(e2Approve($this->manager, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($rejected->fresh()->status)->toBe(ApprovalRequestStatus::Rejected);
});

it('refuses a rejection under the default policy and a reason over 500 characters', function () {
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    expect(fn () => app(RejectApproval::class)($this->approver, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id))
        ->toThrow(ValidationException::class, 'standardattesten');

    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);

    expect(fn () => app(RejectApproval::class)($this->manager, $this->company, ApprovalSubjectType::SupplierInvoice, $invoice->id, str_repeat('x', 501)))
        ->toThrow(ValidationException::class, 'högst 500')
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('lets a substitute decide for a named approver while the delegation runs, recorded on behalf of', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);
    $substitute = Bookkeeping::member($this->company, 'approver');
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    $delegations = app(ManageApprovalDelegations::class);

    expect(fn () => e2Approve($substitute, $invoice))->toThrow(AuthorizationException::class)
        ->and(fn () => $delegations->add($this->bookkeeper, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $substitute->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']))
        ->toThrow(AuthorizationException::class);

    $later = $delegations->add($this->manager, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $substitute->id, 'starts_on' => '2026-03-15', 'ends_on' => '2026-03-20']);
    expect(fn () => e2Approve($substitute, $invoice))->toThrow(AuthorizationException::class);

    $delegations->revoke($this->manager, $this->company, $later->id);
    $delegations->add($this->manager, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $substitute->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12']);

    expect(e2Approve($substitute, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(ApprovalDecision::query()->sole()->only(['user_id', 'on_behalf_of']))->toBe(['user_id' => $substitute->id, 'on_behalf_of' => $this->manager->id])
        ->and(AuditLog::query()->where('action', 'approval.decided')->sole()->payload['on_behalf_of'])->toBe($this->manager->id);
});

it('never lets a substitute gain a permission', function (string $role) {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    $substitute = Bookkeeping::member($this->company, 'approver');
    $this->company = $this->company->fresh();
    $delegations = app(ManageApprovalDelegations::class);
    $weak = Bookkeeping::member($this->company, $role);
    $this->company = $this->company->fresh();

    // Someone without the right to attest cannot be chosen as substitute.
    expect(fn () => $delegations->add($this->manager, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $weak->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12']))
        ->toThrow(ValidationException::class, 'har rätt att attestera');

    // A substitute who loses the right afterwards is refused at decision time.
    $delegations->add($this->manager, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $substitute->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12']);
    $this->company->users()->updateExistingPivot($substitute->id, ['role' => $role]);

    expect(fn () => e2Approve($substitute->fresh(), $invoice))->toThrow(AuthorizationException::class)
        ->and(ApprovalDecision::query()->count())->toBe(0);
})->with(['revisor' => ['auditor'], 'bokförare' => ['bookkeeper']]);

it('refuses named people and owners without the subject\'s own approval permission, when saving and when deciding', function () {
    $center = CostCenter::create(['code' => '10', 'name' => 'Hamnen']);
    $saveUsers = fn (User $user, string $type) => app(SaveApprovalPolicy::class)($this->owner, $this->company->fresh(), [
        'subject_type' => $type, 'name' => 'Fel person', 'priority' => 10, 'is_active' => true,
        'steps' => [['approver_kind' => 'USERS', 'approver_user_ids' => [$user->id], 'required_count' => 1]],
    ]);

    expect(fn () => $saveUsers($this->auditor, 'SUPPLIER_INVOICE'))->toThrow(ValidationException::class, 'rätt att attestera')
        ->and(fn () => $saveUsers($this->bookkeeper, 'SUPPLIER_INVOICE'))->toThrow(ValidationException::class, 'rätt att attestera')
        ->and(fn () => $saveUsers($this->bookkeeper, 'PAYMENT_BATCH'))->toThrow(ValidationException::class, 'rätt att attestera')
        ->and(fn () => app(SetDimensionOwner::class)($this->owner, $this->company->fresh(), ApprovalDimension::CostCenter, $center->id, $this->auditor->id))->toThrow(ValidationException::class);

    // Named while an approver, then made auditor: refused at decision time.
    $saveUsers($this->manager, 'SUPPLIER_INVOICE');
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    $this->company->users()->updateExistingPivot($this->manager->id, ['role' => 'auditor']);

    expect(fn () => e2Approve($this->manager->fresh(), $invoice))->toThrow(AuthorizationException::class);

    // An owner set while approver, then made bookkeeper: the owner step falls back to the attest permission.
    $saveOwner = app(SaveApprovalPolicy::class)($this->owner, $this->company->fresh(), [
        'subject_type' => 'SUPPLIER_INVOICE', 'name' => 'Ägare', 'priority' => 1, 'is_active' => true,
        'steps' => [['approver_kind' => 'DIMENSION_OWNER', 'approver_dimension' => 'COST_CENTER', 'required_count' => 1]],
    ]);
    $approverOwner = Bookkeeping::member($this->company, 'approver');
    app(SetDimensionOwner::class)($this->owner, $this->company->fresh(), ApprovalDimension::CostCenter, $center->id, $approverOwner->id);
    $owned = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, row: ['cost_center' => '10'], number: 'O');
    $this->company->users()->updateExistingPivot($approverOwner->id, ['role' => 'bookkeeper']);

    expect($saveOwner->exists)->toBeTrue()
        ->and(fn () => e2Approve($approverOwner->fresh(), $owned))->toThrow(AuthorizationException::class)
        ->and(e2Approve($this->approver, $owned)->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('lets an owner step require only one approval', function () {
    expect(fn () => app(SaveApprovalPolicy::class)($this->owner, $this->company, [
        'subject_type' => 'SUPPLIER_INVOICE', 'name' => 'Två ägare', 'priority' => 10, 'is_active' => true,
        'steps' => [['approver_kind' => 'DIMENSION_OWNER', 'approver_dimension' => 'COST_CENTER', 'required_count' => 2]],
    ]))->toThrow(ValidationException::class, 'bara kräva ett godkännande');
});

it('lets the same person approve different steps of one request', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve'],
    ]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    e2Approve($this->manager, $invoice);

    expect(e2Approve($this->manager, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('lets a substitute only be revoked, once, in the database', function () {
    $delegation = app(ManageApprovalDelegations::class)->add($this->manager, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $this->approver->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12']);
    $table = fn () => DB::table('approval_delegations')->where('id', $delegation->id);

    expect(fn () => DB::transaction(fn () => $table()->update(['ends_on' => '2026-12-31'])))->toThrow(QueryException::class, 'only be revoked')
        ->and(fn () => DB::transaction(fn () => $table()->update(['revoked_at' => now()])))->toThrow(QueryException::class, 'only be revoked')
        ->and(fn () => DB::transaction(fn () => $table()->update(['revoked_at' => now(), 'revoked_by' => $this->manager->id, 'substitute_user_id' => $this->owner->id])))->toThrow(QueryException::class, 'only be revoked')
        ->and(fn () => DB::transaction(fn () => $table()->delete()))->toThrow(QueryException::class, 'cannot be deleted');

    DB::transaction(fn () => $table()->update(['revoked_at' => now(), 'revoked_by' => $this->manager->id]));

    expect(fn () => DB::transaction(fn () => $table()->update(['revoked_at' => now()->addDay(), 'revoked_by' => $this->owner->id])))->toThrow(QueryException::class, 'only be revoked')
        ->and($delegation->fresh()->revoked_by)->toBe($this->manager->id);
});

it('re-checks membership at decision time', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    $this->company->users()->detach($this->manager->id);

    expect(fn () => e2Approve($this->manager->fresh(), $invoice))->toThrow(AuthorizationException::class);
});

it('lets the owner of the cost centre approve, and falls back to the attest permission without one', function () {
    $hamnen = CostCenter::create(['code' => '10', 'name' => 'Hamnen']);
    CostCenter::create(['code' => '20', 'name' => 'Torget']);
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'DIMENSION_OWNER', 'approver_dimension' => 'COST_CENTER']]);
    app(SetDimensionOwner::class)($this->owner, $this->company, ApprovalDimension::CostCenter, $hamnen->id, $this->manager->id);

    $owned = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, row: ['cost_center' => '10'], number: 'H');
    $unowned = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier, row: ['cost_center' => '20'], number: 'T');

    expect(fn () => e2Approve($this->approver, $owned))->toThrow(AuthorizationException::class)
        ->and(e2Approve($this->manager, $owned)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(e2Approve($this->approver, $unowned)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(AuditLog::query()->where('action', 'dimension.owner_changed')->sole()->payload)->toEqual(['dimension' => 'COST_CENTER', 'owner_user_id' => $this->manager->id]);
});

it('keeps a running request on the policy copy it started with', function () {
    $policy = ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->approver->id]],
    ]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    e2Approve($this->manager, $invoice);

    app(SaveApprovalPolicy::class)($this->owner, $this->company, [
        'subject_type' => 'SUPPLIER_INVOICE', 'name' => 'Ändrat', 'priority' => 10, 'is_active' => true,
        'steps' => [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id], 'required_count' => 1]],
    ], $policy->id);

    $request = ApprovalRequest::query()->sole();

    expect($policy->fresh()->version)->toBe(2)
        ->and($request->snapshot()->version)->toBe(1)
        ->and($request->snapshot()->count())->toBe(2)
        ->and(fn () => e2Approve($this->manager, $invoice))->toThrow(AuthorizationException::class)
        ->and(e2Approve($this->approver, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('keeps decisions append-only and finished requests unchanged, in the model and in the database', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    e2Approve($this->manager, $invoice);
    $decision = ApprovalDecision::query()->sole();
    $request = ApprovalRequest::query()->sole();

    expect(fn () => $decision->forceFill(['decision' => 'REJECT'])->save())->toThrow(LogicException::class)
        ->and(fn () => $decision->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('approval_decisions')->where('id', $decision->id)->update(['decision' => 'REJECT'])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('approval_decisions')->where('id', $decision->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => $request->forceFill(['status' => 'PENDING'])->save())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'PENDING'])))->toThrow(QueryException::class, 'completed')
        ->and(fn () => DB::transaction(fn () => DB::table('approval_requests')->where('id', $request->id)->delete()))->toThrow(QueryException::class)
        ->and($decision->fresh()->decision->value)->toBe('APPROVE');
});

it('freezes the policy snapshot of a running request in the database', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->approver->id]],
    ]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    e2Approve($this->manager, $invoice);
    $request = ApprovalRequest::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('approval_requests')->where('id', $request->id)->update(['policy_snapshot' => '{"steps":[]}'])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('approval_requests')->where('id', $request->id)->update(['subject_id' => 'x'])))->toThrow(QueryException::class);
});

it('runs payment files through a policy, and a cancelled file leaves its queue', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'payments:approve'],
        ['min_amount' => '500', 'approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ], type: 'PAYMENT_BATCH');
    $batch = ApprovalFixtures::batch($this->owner, $this->bookkeeper, $this->company, $this->supplier);

    expect(app(ApprovePaymentBatch::class)($this->approver, $batch)->isApproved())->toBeFalse()
        ->and(fn () => app(ApprovePaymentBatch::class)($this->bookkeeper, $batch->fresh()))->toThrow(AuthorizationException::class);

    $approved = app(ApprovePaymentBatch::class)($this->owner, $batch->fresh());

    expect($approved->approved_by)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'payment_batch.approved')->sole()->payload['sha256'])->toBe($batch->file_sha256);

    $other = ApprovalFixtures::batch($this->owner, $this->bookkeeper, $this->company, $this->supplier, number: 'B-2');
    app(ApprovePaymentBatch::class)($this->approver, $other);
    app(SettlePaymentBatch::class)->cancel($this->bookkeeper, $other->fresh());

    expect(ApprovalRequest::query()->where('subject_id', $other->id)->sole()->status)->toBe(ApprovalRequestStatus::Withdrawn);
});

it('keeps the maker of a payment file out while separate approval is on, even when the policy names them', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    $this->company = $this->company->fresh();
    ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$admin->id], 'exclude_preparer' => false]], type: 'PAYMENT_BATCH');
    $batch = ApprovalFixtures::batch($this->owner, $admin, $this->company, $this->supplier);

    expect(fn () => app(ApprovePaymentBatch::class)($admin, $batch))->toThrow(ValidationException::class, 'förberedde underlaget')
        ->and($batch->fresh()->isApproved())->toBeFalse();
});

it('validates policies and lets only approvals:manage save them', function () {
    $save = fn (User $user, array $step, string $type = 'SUPPLIER_INVOICE') => app(SaveApprovalPolicy::class)($user, $this->company, [
        'subject_type' => $type, 'name' => 'Test', 'priority' => 10, 'is_active' => true, 'steps' => [[...['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve', 'required_count' => 1], ...$step]],
    ]);

    expect(fn () => $save($this->bookkeeper, []))->toThrow(AuthorizationException::class)
        ->and(fn () => $save($this->approver, []))->toThrow(AuthorizationException::class)
        ->and(fn () => $save($this->owner, ['approver_permission' => 'vouchers:post']))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, ['approver_kind' => 'USERS', 'approver_user_ids' => [User::factory()->create()->id]]))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id], 'required_count' => 2]))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, ['min_amount' => '100', 'max_amount' => '50']))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, ['account_from' => '99']))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, ['approver_kind' => 'DIMENSION_OWNER', 'approver_dimension' => 'COST_CENTER'], 'PAYMENT_BATCH'))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, ['account_from' => '4000'], 'PAYMENT_BATCH'))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveApprovalPolicy::class)($this->owner, $this->company, ['subject_type' => 'PURCHASE_REQUISITION', 'name' => 'x', 'priority' => 1, 'steps' => [['approver_kind' => 'PERMISSION', 'required_count' => 1]]]))->toThrow(ValidationException::class);

    // A fresh company: Jetstream keeps the members it read on the instance.
    $admin = Bookkeeping::member($this->company, 'admin');
    $this->company = $this->company->fresh();
    $policy = $save($admin, ['min_amount' => '1 000,50']);

    expect($policy->steps->sole()->conditions)->toBe(['min_ore' => 100_050])
        ->and(AuditLog::query()->where('action', 'approval_policy.saved')->sole()->payload)->toEqual(['subject_type' => 'SUPPLIER_INVOICE', 'version' => 1, 'steps' => 1, 'is_active' => true]);
});

it('refuses stored conditions it does not understand', function () {
    expect(fn () => StepConditions::fromArray(['min_ore' => 1, 'colour' => 'red']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => StepConditions::fromArray(['min_ore' => '100']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => StepConditions::fromArray(['cost_center_ids' => [0]]))->toThrow(InvalidArgumentException::class)
        ->and(StepConditions::fromArray(['min_ore' => 100, 'cost_center_ids' => [3, 3]])->toArray())->toBe(['min_ore' => 100, 'cost_center_ids' => [3]]);
});

it('lets every member set their own substitute but only approvals:manage set someone else\'s', function () {
    $delegations = app(ManageApprovalDelegations::class);

    expect(fn () => $delegations->add($this->bookkeeper, $this->company, ['user_id' => $this->bookkeeper->id, 'substitute_user_id' => $this->bookkeeper->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-11']))->toThrow(ValidationException::class)
        ->and(fn () => $delegations->add($this->bookkeeper, $this->company, ['user_id' => $this->bookkeeper->id, 'substitute_user_id' => User::factory()->create()->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-11']))->toThrow(ValidationException::class)
        ->and(fn () => $delegations->add($this->bookkeeper, $this->company, ['user_id' => $this->bookkeeper->id, 'substitute_user_id' => $this->approver->id, 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-05']))->toThrow(ValidationException::class, 'passerat')
        ->and(fn () => $delegations->add($this->bookkeeper, $this->company, ['user_id' => $this->bookkeeper->id, 'substitute_user_id' => $this->approver->id, 'starts_on' => '2026-03-10', 'ends_on' => '2027-06-01']))->toThrow(ValidationException::class, 'högst ett år');

    $own = $delegations->add($this->bookkeeper, $this->company, ['user_id' => $this->bookkeeper->id, 'substitute_user_id' => $this->approver->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']);
    $forOther = $delegations->add($this->owner, $this->company, ['user_id' => $this->approver->id, 'substitute_user_id' => $this->manager->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']);

    expect(fn () => $delegations->revoke($this->bookkeeper, $this->company, $forOther->id))->toThrow(AuthorizationException::class);

    $delegations->revoke($this->bookkeeper, $this->company, $own->id);

    expect($own->fresh()->revoked_at)->not->toBeNull()
        ->and($own->fresh()->revoked_by)->toBe($this->bookkeeper->id)
        ->and(AuditLog::query()->where('action', 'approval_delegation.added')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'approval_delegation.revoked')->count())->toBe(1);
});

it('lets an owner or administrator reject at any step, so a request whose approver left is never stuck', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve'],
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
    ]);
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);
    e2Approve($this->approver, $invoice);
    $this->company->users()->detach($this->manager->id);
    $company = $this->company->fresh();

    expect(fn () => app(RejectApproval::class)($this->approver, $company, ApprovalSubjectType::SupplierInvoice, $invoice->id))->toThrow(AuthorizationException::class)
        ->and(fn () => e2Approve($this->owner, $invoice))->toThrow(AuthorizationException::class);

    app(RejectApproval::class)($this->owner, $company, ApprovalSubjectType::SupplierInvoice, $invoice->id, 'Attestanten har slutat.');

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Rejected)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Registered);
});
