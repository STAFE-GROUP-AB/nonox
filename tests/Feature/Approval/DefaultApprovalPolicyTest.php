<?php

declare(strict_types=1);

use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Payables\PaymentBatches;
use App\Livewire\Payables\SupplierInvoiceActions;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 gap 8, E2: a company that never edits an approval policy sees no
 * change. Supplier invoices and payment files keep today's single attest:
 * one approval by someone with the permission, the preparer kept out while
 * separate approval is on, the same refusals, the same audit entries and no
 * approval request rows. Neither an inactive policy nor an active one whose
 * steps do not apply changes that.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->supplier = ApprovalFixtures::supplier($this->owner, $this->company);
});

/** The policy setups that must all behave like having no policy. */
dataset('e2 default setups', [
    'no policy' => [null],
    'an inactive policy' => ['inactive'],
    'a policy whose steps do not apply' => ['not-applying'],
]);

function e2DefaultSetup(?string $setup, object $test, string $type): void
{
    if ($setup === 'inactive') {
        ApprovalFixtures::policy($test->owner, $test->company, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'required_count' => 1]], type: $type, active: false);
    }

    if ($setup === 'not-applying') {
        ApprovalFixtures::policy($test->owner, $test->company, [['min_amount' => '1 000 000', 'approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage']], type: $type);
    }
}

it('approves a supplier invoice exactly as before for every role, preparer and rule', function (?string $setup, string $role, bool $preparer, bool $separate, string $expected) {
    e2DefaultSetup($setup, $this, 'SUPPLIER_INVOICE');
    $member = Bookkeeping::member($this->company, $role);
    $preparerUser = $preparer ? $member : Bookkeeping::member($this->company, 'bookkeeper');
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $invoice = ApprovalFixtures::invoice($preparerUser, $this->company, $this->supplier);
    $this->company->forceFill(['separate_supplier_approval' => $separate])->save();
    $policyAllows = $member->can('approve', $invoice);
    $approve = fn () => app(ApproveSupplierInvoice::class)($member, $invoice);

    match ($expected) {
        'approved' => expect($approve()->status)->toBe(SupplierInvoiceStatus::Approved)
            ->and($invoice->fresh()->approved_by)->toBe($member->id)
            ->and($policyAllows)->toBeTrue()
            ->and(AuditLog::query()->where('action', 'supplier_invoice.approved')->where('subject_id', $invoice->id)->sole()->user_id)->toBe($member->id),
        'segregation' => expect($approve)->toThrow(ValidationException::class, 'Du registrerade fakturan och kan därför inte attestera den.')
            ->and($policyAllows)->toBeFalse(),
        'forbidden' => expect($approve)->toThrow(AuthorizationException::class)
            ->and($policyAllows)->toBeFalse(),
    };

    expect(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalDecision::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'approval.%')->count())->toBe(0);

    if ($expected !== 'approved') {
        expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Registered);
    }
})->with('e2 default setups')->with([
    'admin, someone else, rule on' => ['admin', false, true, 'approved'],
    'admin, preparer, rule on' => ['admin', true, true, 'segregation'],
    'admin, preparer, rule off' => ['admin', true, false, 'approved'],
    'approver, someone else, rule on' => ['approver', false, true, 'approved'],
    'accountant, preparer, rule on' => ['accountant', true, true, 'segregation'],
    'accountant, preparer, rule off' => ['accountant', true, false, 'forbidden'],
    'accountant, someone else' => ['accountant', false, true, 'forbidden'],
    'bookkeeper, someone else' => ['bookkeeper', false, true, 'forbidden'],
    'bookkeeper, preparer, rule on' => ['bookkeeper', true, true, 'segregation'],
    'auditor, someone else' => ['auditor', false, true, 'forbidden'],
]);

it('approves a payment file exactly as before for every role, maker and rule', function (?string $setup, string $role, bool $maker, bool $separate, string $expected) {
    e2DefaultSetup($setup, $this, 'PAYMENT_BATCH');
    $member = Bookkeeping::member($this->company, $role);
    $makerUser = $maker ? $member : Bookkeeping::member($this->company, 'bookkeeper');
    $this->company->forceFill(['separate_supplier_approval' => $separate])->save();
    $batch = ApprovalFixtures::batch($this->owner, $makerUser, $this->company, $this->supplier);
    $approve = fn () => app(ApprovePaymentBatch::class)($member, $batch);

    match ($expected) {
        'approved' => expect($approve()->approved_by)->toBe($member->id)
            ->and(AuditLog::query()->where('action', 'payment_batch.approved')->sole()->payload['sha256'])->toBe($batch->file_sha256),
        'segregation' => expect($approve)->toThrow(ValidationException::class, 'Du skapade betalfilen och kan därför inte godkänna den.'),
        'forbidden' => expect($approve)->toThrow(AuthorizationException::class),
    };

    expect(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalDecision::query()->count())->toBe(0);
})->with('e2 default setups')->with([
    'admin, someone else, rule on' => ['admin', false, true, 'approved'],
    'admin, maker, rule on' => ['admin', true, true, 'segregation'],
    'admin, maker, rule off' => ['admin', true, false, 'approved'],
    'approver, someone else' => ['approver', false, true, 'approved'],
    'accountant, maker' => ['accountant', true, true, 'forbidden'],
    'bookkeeper, someone else' => ['bookkeeper', false, true, 'forbidden'],
    'auditor, someone else' => ['auditor', false, true, 'forbidden'],
]);

it('shows the same attest card and payment file buttons as before', function (?string $setup) {
    e2DefaultSetup($setup, $this, 'SUPPLIER_INVOICE');
    e2DefaultSetup($setup, $this, 'PAYMENT_BATCH');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $invoice = ApprovalFixtures::invoice($bookkeeper, $this->company, $this->supplier);
    $batch = ApprovalFixtures::batch($this->owner, $bookkeeper, $this->company, $this->supplier, number: 'B-2');

    Livewire::test(SupplierInvoiceActions::class, ['invoice' => $invoice])
        ->assertSee('Attestera fakturan')
        ->assertDontSee('Attestflöde')
        ->call('approve')
        ->assertDispatched('toast');

    Livewire::actingAs($bookkeeper)->test(SupplierInvoiceActions::class, ['invoice' => ApprovalFixtures::invoice($bookkeeper, $this->company, $this->supplier, number: 'F-9')])
        ->assertDontSee('Attestera fakturan');

    Livewire::actingAs($this->owner)->test(PaymentBatches::class)
        ->assertSee('Godkänn betalningen')
        ->assertDontSee('Attestflöde')
        ->call('approve', $batch->id);

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($batch->fresh()->isApproved())->toBeTrue()
        ->and(ApprovalRequest::query()->count())->toBe(0);
})->with('e2 default setups');
