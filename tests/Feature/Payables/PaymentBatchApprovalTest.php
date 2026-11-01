<?php

declare(strict_types=1);

use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SettlePaymentBatch;
use App\Domain\Payables\PaymentBatchStatus;
use App\Livewire\Payables\PaymentBatches;
use App\Models\AuditLog;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §4: a payment file is approved (payments:approve) before it can
 * reach the bank, and not by the person who made it while the company
 * requires separate approval.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    // The owner registers and attests the invoices alone; tests switch the rule on for the payment files.
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);

    $this->invoice = function (string $number) use ($supplier): SupplierInvoice {
        $separate = $this->company->fresh()->separate_supplier_approval;
        $this->company->forceFill(['separate_supplier_approval' => false])->save();

        $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-03-05',
            'total' => '1000',
            'rows' => [['account' => '', 'amount' => '1000']],
        ]);
        $invoice = app(ApproveSupplierInvoice::class)($this->owner, app(RegisterSupplierInvoice::class)($this->owner, $draft))->fresh();

        $this->company->forceFill(['separate_supplier_approval' => $separate])->save();

        return $invoice;
    };

    $this->batch = fn (User $user, string $number = 'F-1'): SupplierPaymentBatch => app(CreatePaymentBatch::class)($user, $this->company, [($this->invoice)($number)->id], CarbonImmutable::parse('2026-03-12'));
});

it('lets someone else approve the file while separate approval is on, and audits it', function () {
    $this->company->forceFill(['separate_supplier_approval' => true])->save();
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $approver = Bookkeeping::member($this->company, 'approver');
    $batch = ($this->batch)($bookkeeper);

    expect($batch->isApproved())->toBeFalse()
        ->and($batch->statusLabel())->toBe('Väntar på godkännande');

    $approved = app(ApprovePaymentBatch::class)($approver, $batch);
    $audit = AuditLog::query()->where('action', 'payment_batch.approved')->sole();

    expect($approved->approved_by)->toBe($approver->id)
        ->and($approved->fresh()->approved_at)->not->toBeNull()
        ->and($approved->fresh()->statusLabel())->toBe('Godkänd, väntar på banken')
        ->and($approved->fresh()->status)->toBe(PaymentBatchStatus::Created)
        ->and($audit->user_id)->toBe($approver->id)
        ->and($audit->subject_id)->toBe($batch->id)
        ->and($audit->payload['sha256'])->toBe($batch->file_sha256)
        ->and(fn () => app(ApprovePaymentBatch::class)($this->owner, $batch->fresh()))->toThrow(ValidationException::class, 'redan godkänd');
});

it('refuses the person who made the file while separate approval is on', function () {
    $this->company->forceFill(['separate_supplier_approval' => true])->save();
    $batch = ($this->batch)($this->owner);

    expect(fn () => app(ApprovePaymentBatch::class)($this->owner, $batch))
        ->toThrow(ValidationException::class, 'Du skapade betalfilen')
        ->and($batch->fresh()->isApproved())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'payment_batch.approved')->exists())->toBeFalse();
});

it('lets the person who made the file approve it when the company has turned the rule off', function () {
    $batch = ($this->batch)($this->owner);

    expect(app(ApprovePaymentBatch::class)($this->owner, $batch)->approved_by)->toBe($this->owner->id);
});

it('lets only roles with payments:approve approve a file', function (string $role, bool $allowed) {
    $this->company->forceFill(['separate_supplier_approval' => true])->save();
    $batch = ($this->batch)($this->owner);
    $member = Bookkeeping::member($this->company, $role);

    $allowed
        ? expect(app(ApprovePaymentBatch::class)($member, $batch)->approved_by)->toBe($member->id)
        : expect(fn () => app(ApprovePaymentBatch::class)($member, $batch))->toThrow(AuthorizationException::class);
})->with([
    'administratör' => ['admin', true],
    'attestant' => ['approver', true],
    'redovisningskonsult' => ['accountant', false],
    'bokförare' => ['bookkeeper', false],
    'revisor' => ['auditor', false],
]);

it('refuses to download or confirm a file before it is approved, but lets it be cancelled', function () {
    $batch = ($this->batch)($this->owner);

    $this->get(route('payables.batches.file', $batch))->assertRedirect(route('payables.batches.index'));
    expect(Toast::pending()[0]['message'])->toBe('Betalfil 1 är inte godkänd och kan inte laddas ned. Någon med rätt att godkänna betalningar behöver godkänna den först.')
        ->and(Toast::pending()[0]['type'])->toBe('error')
        ->and(fn () => app(SettlePaymentBatch::class)->confirm($this->owner, $batch))->toThrow(ValidationException::class, 'inte godkänd')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', $batch->id)->update(['status' => 'CONFIRMED'])))->toThrow(QueryException::class, 'not approved')
        ->and($batch->fresh()->status)->toBe(PaymentBatchStatus::Created);

    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $this->get(route('payables.batches.file', $batch))->assertOk()->assertDownload('betalfil-1-2026-03-12.xml');

    $unapproved = ($this->batch)($this->owner, 'F-2');
    app(SettlePaymentBatch::class)->cancel($this->owner, $unapproved);

    expect($unapproved->fresh()->status)->toBe(PaymentBatchStatus::Cancelled)
        ->and(fn () => app(ApprovePaymentBatch::class)($this->owner, $unapproved->fresh()))->toThrow(ValidationException::class, 'redan hanterad');
    $this->get(route('payables.batches.file', $unapproved))->assertRedirect(route('payables.batches.index'));
});

it('freezes the file and its approval once approved', function () {
    $other = User::factory()->create();
    $batch = ($this->batch)($this->owner);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $batch = $batch->fresh();

    expect(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', $batch->id)->update(['approved_by' => $other->id])))->toThrow(QueryException::class, 'approval cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', $batch->id)->update(['approved_at' => null, 'approved_by' => null])))->toThrow(QueryException::class, 'approval cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', $batch->id)->update(['total_ore' => 1])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batch_items')->where('batch_id', $batch->id)->update(['amount_ore' => 1])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => $batch->forceFill(['approved_by' => $other->id])->save())->toThrow(LogicException::class, 'already approved')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', ($this->batch)($this->owner, 'F-2')->id)->update(['approved_at' => now()])))->toThrow(QueryException::class, 'approval cannot be changed');

    app(SettlePaymentBatch::class)->confirm($this->owner, $batch);

    expect($batch->fresh()->status)->toBe(PaymentBatchStatus::Confirmed)
        ->and($batch->fresh()->approved_by)->toBe($this->owner->id);
});

it('keeps another company\'s files out of reach', function () {
    $batch = ($this->batch)($this->owner);
    [$stranger, $strangerCompany] = Bookkeeping::company();
    $strangerApprover = Bookkeeping::member($strangerCompany, 'approver');

    expect(fn () => app(ApprovePaymentBatch::class)($stranger, $batch))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApprovePaymentBatch::class)($strangerApprover, $batch))->toThrow(AuthorizationException::class);

    CurrentCompany::set($strangerCompany);
    $this->actingAs($stranger);
    Livewire::test(PaymentBatches::class)->call('approve', $batch->id)->assertNotFound();

    expect($batch->fresh()->isApproved())->toBeFalse();
});

describe('in the browser', function () {
    it('shows the approval step and toasts its outcome', function () {
        $this->company->forceFill(['separate_supplier_approval' => true])->save();
        $batch = ($this->batch)($this->owner);
        $approver = Bookkeeping::member($this->company, 'approver');

        Livewire::test(PaymentBatches::class)
            ->assertSee('Väntar på godkännande')
            ->assertSee('Du skapade filen. Någon annan behöver godkänna den.')
            ->assertDontSee('Godkänn betalningen')
            ->assertDontSee('Banken har betalat')
            ->assertDontSee('Ladda ned fil')
            ->call('approve', $batch->id)
            ->assertDispatched('toast', message: 'Du skapade betalfilen och kan därför inte godkänna den. Någon annan med rätt att godkänna betalningar behöver göra det.', type: 'error')
            ->call('confirm', $batch->id)
            ->assertDispatched('toast', message: 'Betalfilen är inte godkänd. Någon med rätt att godkänna betalningar behöver godkänna den innan den kan markeras som betald.', type: 'error');

        $this->actingAs($approver);
        Livewire::test(PaymentBatches::class)
            ->assertSee('Godkänn betalningen')
            ->assertSee('1 betalningar på sammanlagt')
            ->call('approve', $batch->id)
            ->assertDispatched('toast', message: 'Betalfil 1 är godkänd. Ladda ned den och läs in den i internetbanken.')
            ->assertSee('Godkänd, väntar på banken')
            ->assertSee("Godkänd av {$approver->name}")
            ->assertSee('Ladda ned fil')
            ->assertDontSee('Banken har betalat');

        $this->actingAs($this->owner);
        Livewire::test(PaymentBatches::class)
            ->assertSee('Banken har betalat')
            ->assertDontSee('Godkänn betalningen');
    });
});
