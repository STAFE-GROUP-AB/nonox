<?php

declare(strict_types=1);

use App\Actions\Approval\ManageApprovalDelegations;
use App\Actions\Payables\ApprovePaymentBatch;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Approval\ApprovalPolicies;
use App\Livewire\Payables\PaymentBatches;
use App\Livewire\Payables\SupplierInvoiceActions;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\CostCenter;
use App\Models\Project;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 gap 8, E2: Attestflöden, the approval card on a supplier invoice
 * and the payment files list, with Swedish toasts and a fixed number of
 * queries however much there is to show.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->supplier = ApprovalFixtures::supplier($this->owner, $this->company);
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->manager = Bookkeeping::member($this->company, 'approver');
    $this->substitute = Bookkeeping::member($this->company, 'approver');
    $this->company = $this->company->fresh();
});

/** Queries for one render of a Livewire component. */
function e2Queries(callable $render): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $render();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('opens Attestflöden in Swedish for every member, from the menu', function () {
    $this->get(route('approvals.policies'))
        ->assertOk()
        ->assertSee('Attestflöden')
        ->assertSee('Standardattest')
        ->assertSee('Nytt flöde för leverantörsfakturor');

    expect(route('approvals.policies', absolute: false))->toBe('/attestfloden');

    $this->actingAs($this->bookkeeper)->get(route('approvals.policies'))
        ->assertOk()
        ->assertSee('Ersättare')
        ->assertDontSee('Nytt flöde för leverantörsfakturor');
});

it('creates, changes and removes a policy with toasts, only with approvals:manage', function () {
    $page = Livewire::test(ApprovalPolicies::class)
        ->call('create', 'SUPPLIER_INVOICE')
        ->set('form.name', 'Över 50 000 kr')
        ->set('form.steps.0.min_amount', '50 000')
        ->set('form.steps.0.approver_kind', 'USERS')
        ->set('form.steps.0.approver_user_ids.'.$this->manager->id, true)
        ->call('addStep')
        ->set('form.steps.1.approver_permission', 'approvals:manage')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $policy = ApprovalPolicy::query()->with('steps')->sole();

    expect($policy->name)->toBe('Över 50 000 kr')
        ->and($policy->steps)->toHaveCount(2)
        ->and($policy->steps[0]->approver_user_ids)->toBe([$this->manager->id])
        ->and($policy->steps[0]->conditions)->toBe(['min_ore' => 5_000_000]);

    $page->call('edit', $policy->id)
        ->assertSet('form.steps.0.min_amount', Money::ofOre(5_000_000)->format(withCurrency: false))
        ->call('removeStep', 1)
        ->call('save')
        ->assertDispatched('toast');

    expect($policy->fresh()->version)->toBe(2)
        ->and($policy->steps()->count())->toBe(1);

    $page->call('delete', $policy->id)->assertDispatched('toast');
    expect(ApprovalPolicy::query()->count())->toBe(0);

    Livewire::actingAs($this->bookkeeper)->test(ApprovalPolicies::class)->call('create', 'SUPPLIER_INVOICE')->assertForbidden();
});

it('shows validation errors on the step that is wrong', function () {
    Livewire::test(ApprovalPolicies::class)
        ->call('create', 'SUPPLIER_INVOICE')
        ->set('form.name', 'Fel')
        ->set('form.steps.0.min_amount', 'mycket')
        ->call('save')
        ->assertHasErrors('steps.0.min_amount');

    expect(ApprovalPolicy::query()->count())->toBe(0);
});

it('lets a member add and revoke their own substitute from the page', function () {
    Livewire::actingAs($this->bookkeeper)->test(ApprovalPolicies::class)
        ->set('delegation.substitute_user_id', (string) $this->manager->id)
        ->set('delegation.ends_on', '2026-03-20')
        ->call('addDelegation')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('till och med 2026-03-20')
        ->call('revokeDelegation', ApprovalDelegation::query()->sole()->id)
        ->assertDispatched('toast');

    expect(ApprovalDelegation::query()->sole()->revoked_at)->not->toBeNull();
});

it('sets the owner of a cost centre from the page', function () {
    $center = CostCenter::create(['code' => '10', 'name' => 'Hamnen']);

    Livewire::test(ApprovalPolicies::class)
        ->call('setOwner', 'COST_CENTER', $center->id, (string) $this->manager->id)
        ->assertDispatched('toast');

    expect($center->fresh()->owner_user_id)->toBe($this->manager->id);
});

it('renders Attestflöden in a fixed number of queries', function () {
    $short = function (): void {
        ApprovalFixtures::policy($this->owner, $this->company, [['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]]]);
        app(ManageApprovalDelegations::class)->add($this->owner, $this->company, ['user_id' => $this->manager->id, 'substitute_user_id' => $this->substitute->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']);
        CostCenter::create(['code' => '10', 'name' => 'Hamnen']);
    };
    $short();
    Livewire::test(ApprovalPolicies::class);
    $first = e2Queries(fn () => Livewire::test(ApprovalPolicies::class));

    foreach (range(1, 6) as $i) {
        $member = Bookkeeping::member($this->company, 'approver');
        ApprovalFixtures::policy($this->owner, $this->company->fresh(), [
            ['approver_kind' => 'USERS', 'approver_user_ids' => [$member->id]],
            $i % 2 === 0 ? ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]] : ['approver_kind' => 'DIMENSION_OWNER', 'approver_dimension' => 'PROJECT'],
            ['min_amount' => (string) ($i * 1000), 'approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
        ], type: $i % 2 === 0 ? 'PAYMENT_BATCH' : 'SUPPLIER_INVOICE', name: "Flöde {$i}", priority: $i);
        app(ManageApprovalDelegations::class)->add($this->owner, $this->company->fresh(), ['user_id' => $member->id, 'substitute_user_id' => $this->substitute->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']);
        CostCenter::create(['code' => "C{$i}", 'name' => "Ställe {$i}", 'owner_user_id' => null]);
        Project::create(['code' => "P{$i}", 'name' => "Projekt {$i}"]);
    }

    $second = e2Queries(fn () => Livewire::test(ApprovalPolicies::class));

    expect($second)->toBe($first);
});

it('shows the approval flow on an invoice and lets the approver reject it back to the preparer', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->manager->id]],
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ], name: 'Två steg');
    $invoice = ApprovalFixtures::invoice($this->bookkeeper, $this->company, $this->supplier);

    Livewire::actingAs($this->manager)->test(SupplierInvoiceActions::class, ['invoice' => $invoice])
        ->assertSee('Attestflöde')
        ->assertSee('Följer Två steg (version 1).')
        ->assertSee('Godkänn steg 1 av 2')
        ->call('approve')
        ->assertDispatched('toast')
        ->assertSee('Pågår, 0 av 1 godkända');

    Livewire::actingAs($this->owner)->test(SupplierInvoiceActions::class, ['invoice' => $invoice->fresh()])
        ->assertSee('Godkänn steg 2 av 2')
        ->set('rejectReason', 'Saknar följesedel.')
        ->call('reject')
        ->assertDispatched('toast');

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequestStatus::Rejected);

    Livewire::actingAs($this->bookkeeper)->test(SupplierInvoiceActions::class, ['invoice' => $invoice->fresh()])
        ->assertSee('Avvisad i attesten.')
        ->assertSee('Skäl: Saknar följesedel.')
        ->call('resubmit')
        ->assertDispatched('toast');

    expect(ApprovalRequest::query()->count())->toBe(2)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Registered);
});

it('lists payment files with their approval flow in a fixed number of queries', function () {
    ApprovalFixtures::policy($this->owner, $this->company, [
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'payments:approve'],
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$this->owner->id]],
    ], type: 'PAYMENT_BATCH', name: 'Betalningar i två steg');
    $approver = Bookkeeping::member($this->company, 'approver');
    $first = ApprovalFixtures::batch($this->owner, $this->bookkeeper, $this->company, $this->supplier, number: 'B-1');
    app(ApprovePaymentBatch::class)($approver, $first);

    Livewire::test(PaymentBatches::class);
    $short = e2Queries(fn () => Livewire::test(PaymentBatches::class));

    foreach (range(2, 6) as $i) {
        $batch = ApprovalFixtures::batch($this->owner, $this->bookkeeper, $this->company, $this->supplier, number: "B-{$i}");
        app(ApprovePaymentBatch::class)($approver, $batch);
    }

    $long = e2Queries(fn () => Livewire::test(PaymentBatches::class)
        ->assertSee('Attestflöde Betalningar i två steg: steg 2 av 2, 0 av 1 godkända.')
        ->assertSee('Godkänn betalningen'));

    expect($long)->toBe($short);
});
