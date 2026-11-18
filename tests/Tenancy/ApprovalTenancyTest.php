<?php

declare(strict_types=1);

use App\Actions\Approval\DeleteApprovalPolicy;
use App\Actions\Approval\ManageApprovalDelegations;
use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Actions\Approval\SaveApprovalPolicy;
use App\Actions\Approval\SetDimensionOwner;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Domain\Approval\ApprovalDimension;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Approval\ApprovalPolicies;
use App\Models\ApprovalDecision;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\CostCenter;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the approval engine (ADR-081 gap 8): another
 * company's policies, steps, requests, decisions, substitutes, dimension
 * owners and subjects answer 404 from every action and the page, and one
 * company's policy never decides another company's approval.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();

    // Adding a member switches their company, which forgets the current one: done before the context is set.
    $this->memberB = Bookkeeping::member($this->b, 'approver');
    $this->b = $this->b->fresh();

    [$this->policyB, $this->invoiceB, $this->delegationB, $this->centerB] = CurrentCompany::run($this->b, function (): array {
        $member = $this->memberB;
        $supplier = ApprovalFixtures::supplier($this->ownerB, $this->b);
        $policy = ApprovalFixtures::policy($this->ownerB, $this->b->fresh(), [['approver_kind' => 'USERS', 'approver_user_ids' => [$member->id]]]);
        $invoice = ApprovalFixtures::invoice($this->ownerB, $this->b, $supplier);
        app(RejectApproval::class)($member, $this->b->fresh(), ApprovalSubjectType::SupplierInvoice, $invoice->id, 'Hemligt skäl');
        $delegation = app(ManageApprovalDelegations::class)->add($member, $this->b->fresh(), ['user_id' => $member->id, 'substitute_user_id' => $this->ownerB->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']);

        return [$policy, $invoice, $delegation, CostCenter::create(['code' => '10', 'name' => 'Hemligt ställe'])];
    });

    CurrentCompany::set($this->a);
    $this->actingAs($this->ownerA);
    $this->supplierA = ApprovalFixtures::supplier($this->ownerA, $this->a);
});

it('answers 404 from every action for another company\'s rows and subjects', function () {
    $input = ['subject_type' => 'SUPPLIER_INVOICE', 'name' => 'Kapat', 'priority' => 1, 'is_active' => true, 'steps' => [['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve', 'required_count' => 1]]];

    expect(fn () => app(SaveApprovalPolicy::class)($this->ownerA, $this->a, $input, $this->policyB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteApprovalPolicy::class)($this->ownerA, $this->a, $this->policyB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ManageApprovalDelegations::class)->revoke($this->ownerA, $this->a, $this->delegationB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SetDimensionOwner::class)($this->ownerA, $this->a, ApprovalDimension::CostCenter, $this->centerB->id, $this->ownerA->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::SupplierInvoice, $this->invoiceB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ResubmitApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::SupplierInvoice, $this->invoiceB->id))->toThrow(ModelNotFoundException::class);

    CurrentCompany::run($this->b, function (): void {
        expect($this->policyB->fresh()->name)->toBe('Testflöde')
            ->and($this->delegationB->fresh()->revoked_at)->toBeNull()
            ->and($this->centerB->fresh()->owner_user_id)->toBeNull()
            ->and(ApprovalRequest::query()->count())->toBe(1);
    });
});

it('answers 404 from the page, also with tampered ids', function () {
    Livewire::test(ApprovalPolicies::class)->call('edit', $this->policyB->id)->assertNotFound();
    Livewire::test(ApprovalPolicies::class)->call('delete', $this->policyB->id)->assertNotFound();
    Livewire::test(ApprovalPolicies::class)->call('revokeDelegation', $this->delegationB->id)->assertNotFound();
    Livewire::test(ApprovalPolicies::class)->call('setOwner', 'COST_CENTER', $this->centerB->id, (string) $this->ownerA->id)->assertNotFound();

    Livewire::test(ApprovalPolicies::class)
        ->assertDontSee('Testflöde')
        ->assertDontSee('Hemligt ställe')
        ->assertDontSee($this->memberB->name);
});

it('refuses conditions and approvers from another company', function () {
    $save = fn (array $step) => app(SaveApprovalPolicy::class)($this->ownerA, $this->a, ['subject_type' => 'SUPPLIER_INVOICE', 'name' => 'Lånat', 'priority' => 1, 'steps' => [[...['approver_kind' => 'PERMISSION', 'approver_permission' => 'supplier-invoices:approve', 'required_count' => 1], ...$step]]]);

    expect(fn () => $save(['cost_center_id' => (string) $this->centerB->id]))->toThrow(ValidationException::class)
        ->and(fn () => $save(['approver_kind' => 'USERS', 'approver_user_ids' => [$this->memberB->id]]))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageApprovalDelegations::class)->add($this->ownerA, $this->a, ['user_id' => $this->ownerA->id, 'substitute_user_id' => $this->memberB->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12']))->toThrow(ValidationException::class)
        ->and(fn () => app(SetDimensionOwner::class)($this->ownerA, $this->a, ApprovalDimension::CostCenter, CostCenter::create(['code' => '10', 'name' => 'Eget'])->id, $this->memberB->id))->toThrow(ValidationException::class)
        ->and(ApprovalPolicy::query()->count())->toBe(0);
});

it('never lets one company\'s policy, requests or substitutes decide another company\'s approval', function () {
    $bookkeeper = Bookkeeping::member($this->a, 'bookkeeper');
    $invoice = ApprovalFixtures::invoice($bookkeeper, $this->a, $this->supplierA);

    // B's policy names B's approver; A has no policy, so A's own single attest applies.
    expect(fn () => app(ApproveSupplierInvoice::class)($this->memberB, $invoice))->toThrow(AuthorizationException::class)
        ->and(app(ApproveSupplierInvoice::class)($this->ownerA, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalDecision::query()->count())->toBe(0)
        ->and(ApprovalDelegation::query()->count())->toBe(0)
        ->and(ApprovalPolicy::query()->count())->toBe(0);
});
