<?php

declare(strict_types=1);

use App\Actions\Group\CreatePairedDraft;
use App\Actions\Group\DeliverIntercompanyInvoice;
use App\Actions\Group\RemoveGroupMember;
use App\Actions\Group\SetIntercompanyReceipt;
use App\Actions\Group\SetPairReference;
use App\Domain\Group\IntercompanyDeliveryStatus;
use App\Livewire\Group\IntercompanyDeliveries;
use App\Livewire\Group\IntercompanyDeliveryPanel;
use App\Livewire\Group\PairedDraftPanel;
use App\Models\AuditLog;
use App\Models\CompanyHolding;
use App\Models\IntercompanyDelivery;
use App\Models\IntercompanyPair;
use App\Models\IntercompanyReceiptSetting;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\E27Group;

/*
 * AGENTS.md §13.2 for the one cross-company write of ADR-081 (principle 2,
 * gap 1, E27): an intra-group invoice delivered from company A into the
 * inbox of group company B, and paired drafts.
 *
 * - The sender cannot read the delivered message, its status or B's
 *   supplier invoice: B's ids answer 404 in A, and A's delivery row holds no
 *   id of B.
 * - Delivery without B's setting, with the setting switched off, to a
 *   company outside the group, after the membership ended, to a number no
 *   NONOX company has, or while B's inbox is off, all answer exactly alike:
 *   as if B did not exist.
 * - Another company's receipt setting, delivery, pair, invoice and voucher
 *   ids answer 404.
 * - A paired draft carries none of A's ids into B.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $this->set = E27Group::make();
    $this->invoice = $this->set->invoice();
    CurrentCompany::set($this->set->a);
    $this->set->sara->switchCompany($this->set->a);
    $this->actingAs($this->set->sara->fresh());
});

/** What the sender sees of one attempt: the stored row (without ids and times) and the audit entry's keys and status. */
function e27SenderView(IntercompanyDelivery $delivery): array
{
    $log = AuditLog::query()->where('subject_type', $delivery->getMorphClass())->where('subject_id', (string) $delivery->id)->sole();

    return [
        'status' => $delivery->status,
        'group' => $delivery->corporate_group_id,
        'delivered_at' => $delivery->delivered_at,
        'action' => $log->action,
        'payload_keys' => array_keys($log->payload),
        'payload_status' => $log->payload['status'],
    ];
}

it('never lets the sender read the delivered message, its status or the receiver\'s supplier invoice', function () {
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    $message = CurrentCompany::run($this->set->b, fn (): InboxMessage => InboxMessage::query()->sole());
    $supplierInvoice = CurrentCompany::run($this->set->b, fn (): SupplierInvoice => app(ApproveInboxMessage::class)($this->set->bea, $message->fresh(), (array) $message->fresh()->proposal, ApproveInboxMessage::DRAFT, warningsConfirmed: true)->supplierInvoice()->firstOrFail());

    // Olle is administrator in B too; working in A he still reads nothing of B through A.
    expect(InboxMessage::query()->find($message->id))->toBeNull()
        ->and(SupplierInvoice::query()->find($supplierInvoice->id))->toBeNull()
        ->and(Schema::getColumnListing('intercompany_deliveries'))->toEqualCanonicalizing(['id', 'company_id', 'invoice_id', 'recipient_org_number', 'corporate_group_id', 'status', 'delivered_at', 'delivered_by', 'xml_path', 'xml_sha256', 'created_at', 'updated_at']);

    $this->get(route('inbox.original', ['message' => $message->id]))->assertNotFound();
    $this->get(route('payables.invoices.show', $supplierInvoice))->assertNotFound();

    // The sender's page and panel say "Levererad" and nothing of B's handling.
    Livewire::test(IntercompanyDeliveryPanel::class, ['invoiceId' => $this->invoice->id])
        ->assertSee(__('Levererad'))
        ->assertDontSee($supplierInvoice->id)
        ->assertDontSee($message->id)
        ->assertDontSee(__('Utkast skapat'));
    Livewire::test(IntercompanyDeliveries::class)->assertDontSee($message->id)->assertDontSee($supplierInvoice->id);
});

it('answers every refusal alike, as if the receiver did not exist, and writes nothing into it', function () {
    $refusals = [];
    $attempt = function (string $label) use (&$refusals): void {
        $invoice = $this->set->invoice((string) (200 + count($refusals)));
        $delivery = app(DeliverIntercompanyInvoice::class)($this->set->sara, $invoice);
        $refusals[$label] = e27SenderView($delivery);
    };

    // 1. The receiver has not switched receipt on (another sender number only).
    $setting = CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->sole());
    app(SetIntercompanyReceipt::class)->disable($this->set->bea, $this->set->b, $setting->id);
    $attempt('switched off');

    CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->delete());
    $attempt('no setting');

    // 2. Receipt on, but the inbox is off.
    app(SetIntercompanyReceipt::class)->enable($this->set->bea, $this->set->b, (string) $this->set->a->org_number);
    app(ModuleManager::class)->disable(InboxModule::KEY, $this->set->b, $this->set->bea, confirmed: true);
    $attempt('inbox off');
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->set->b, $this->set->bea);

    // The receiver is archived, then a test company.
    $this->set->b->forceFill(['archived_at' => now()])->save();
    $attempt('receiver archived');
    $this->set->b->forceFill(['archived_at' => null, 'is_test_company' => true])->save();
    $attempt('receiver is a test company');
    $this->set->b->forceFill(['is_test_company' => false])->save();

    // 3. The holding that grounds B's membership was disposed of: the membership has ended.
    CurrentCompany::run($this->set->a, fn () => CompanyHolding::query()->whereKey($this->set->holding->id)->update(['disposed_on' => '2026-03-01']));
    $attempt('membership ended');
    CurrentCompany::run($this->set->a, fn () => CompanyHolding::query()->whereKey($this->set->holding->id)->update(['disposed_on' => null]));

    // 4. B left the group altogether.
    app(RemoveGroupMember::class)($this->set->olle->fresh(), $this->set->group->id, $this->set->b->id);
    $attempt('outside the group');

    // 5. The marked number belongs to no company on the installation.
    CurrentCompany::run($this->set->a, fn () => DB::table('intercompany_counterparties')->where('company_id', $this->set->a->id)->update(['counterparty_org_number' => E27Group::orgNumber('199')]));
    $this->set->customer->forceFill(['org_number' => E27Group::orgNumber('199')])->save();
    $attempt('no such company');

    $views = array_values($refusals);

    expect($views)->toHaveCount(8)
        ->and(array_unique(array_map(fn (array $view): string => serialize($view), $views)))->toHaveCount(1)
        ->and($views[0]['status'])->toBe(IntercompanyDeliveryStatus::Refused)
        ->and($views[0]['group'])->toBeNull()
        ->and(CurrentCompany::run($this->set->b, fn (): int => InboxMessage::query()->count()))->toBe(0)
        ->and(DB::table('inbox_messages')->where('company_id', $this->set->b->id)->exists())->toBeFalse();
});

it('tells the sender the same Swedish sentence for every refusal', function () {
    CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->delete());

    Livewire::test(IntercompanyDeliveryPanel::class, ['invoiceId' => $this->invoice->id])
        ->call('deliver')
        ->assertDispatched('toast', message: DeliverIntercompanyInvoice::refusedMessage(), type: 'error')
        ->assertSee(__('Inte levererad'));
});

it('answers 404 for another company\'s receipt setting, delivery, pair, invoice and voucher ids', function () {
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    $bSetting = CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->sole());
    $aDelivery = IntercompanyDelivery::query()->sole();
    $bVoucher = CurrentCompany::run($this->set->b, fn (): Voucher => Bookkeeping::post($this->set->bea, $this->set->b));
    $bPair = CurrentCompany::run($this->set->b, fn () => IntercompanyPair::create(['voucher_id' => $bVoucher->id, 'counterparty_org_number' => $this->set->a->org_number, 'kind' => 'LOAN_PAYABLE', 'created_by' => $this->set->bea->id]));
    $bInvoice = CurrentCompany::run($this->set->b, fn () => DB::table('invoices')->where('company_id', $this->set->b->id)->value('id'));

    $this->actingAs($this->set->olle);
    $this->set->olle->switchCompany($this->set->a);

    expect(fn () => app(SetIntercompanyReceipt::class)->disable($this->set->olle, $this->set->a, $bSetting->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SetPairReference::class)($this->set->olle, $this->set->a, $bPair->id, 'A1'))->toThrow(ModelNotFoundException::class)
        ->and(CurrentCompany::run($this->set->b, fn () => IntercompanyDelivery::query()->find($aDelivery->id)))->toBeNull()
        ->and(IntercompanyPair::query()->find($bPair->id))->toBeNull();

    Livewire::test(PairedDraftPanel::class, ['voucherId' => $bVoucher->id])->assertNotFound();
    Livewire::test(IntercompanyDeliveries::class)->call('disableReceipt', $bSetting->id)->assertNotFound();

    if ($bInvoice !== null) {
        Livewire::test(IntercompanyDeliveryPanel::class, ['invoiceId' => $bInvoice])->assertNotFound();
    }

    expect($bSetting->fresh()->enabled)->toBeTrue();
});

it('refuses in the database a delivery of another company\'s invoice and a pair on another company\'s voucher', function () {
    $bVoucher = CurrentCompany::run($this->set->b, fn (): Voucher => Bookkeeping::post($this->set->bea, $this->set->b));

    expect(fn () => DB::transaction(fn () => DB::table('intercompany_pairs')->insert([
        'company_id' => $this->set->a->id, 'voucher_id' => $bVoucher->id, 'counterparty_org_number' => $this->set->b->org_number,
        'kind' => 'LOAN_RECEIVABLE', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, 'same company')
        ->and(fn () => DB::transaction(fn () => DB::table('intercompany_deliveries')->insert([
            'company_id' => $this->set->b->id, 'invoice_id' => $this->invoice->id, 'recipient_org_number' => $this->set->a->org_number,
            'status' => 'REFUSED', 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(Exception::class, 'same company');
});

it('writes the delivered message with the receiver\'s company id only, and runs its interpretation for the receiver', function () {
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    expect(DB::table('inbox_messages')->where('company_id', $this->set->a->id)->exists())->toBeFalse()
        ->and(DB::table('inbox_messages')->where('company_id', $this->set->b->id)->count())->toBe(1)
        // The UBL file, and the PDF the Peppol reader takes out of it.
        ->and(DB::table('inbox_attachments')->where('company_id', $this->set->b->id)->count())->toBe(2)
        ->and(DB::table('inbox_attachments')->where('company_id', $this->set->a->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('company_id', $this->set->a->id)->where('action', 'like', 'inbox.%')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('company_id', $this->set->b->id)->where('action', 'inbox.interpreted')->exists())->toBeTrue();
});

it('makes a paired draft from the user\'s rights in both companies, with none of the sender\'s ids', function () {
    $this->actingAs($this->set->olle);
    $this->set->olle->switchCompany($this->set->a);
    CurrentCompany::set($this->set->a);
    $this->set->account($this->set->a, 1660, 'Kortfristiga fordringar hos koncernföretag');
    $this->set->account($this->set->b, 2860, 'Kortfristiga skulder till koncernföretag');
    $loan = CurrentCompany::run($this->set->a, fn (): Voucher => Bookkeeping::postRows($this->set->olle, $this->set->a, '2026-03-05', [1660 => 50_000_00, 1930 => -50_000_00]));

    $proposal = app(CreatePairedDraft::class)->propose($this->set->olle, $loan);
    app(CreatePairedDraft::class)($this->set->olle, $loan, $this->set->b->id, [...$proposal, 'kind' => 'RECEIVABLE']);

    $draft = CurrentCompany::run($this->set->b, fn (): Voucher => Voucher::query()->where('status', 'DRAFT')->with('rows')->sole());
    $aAccountIds = DB::table('accounts')->where('company_id', $this->set->a->id)->pluck('id')->all();

    expect($draft->company_id)->toBe($this->set->b->id)
        ->and($draft->rows->every(fn (VoucherRow $row): bool => ! in_array($row->account_id, $aAccountIds, true)))->toBeTrue()
        ->and(DB::table('voucher_rows')->where('voucher_id', $draft->id)->where('company_id', '<>', $this->set->b->id)->exists())->toBeFalse()
        ->and(Schema::getColumnListing('intercompany_pairs'))->toEqualCanonicalizing(['id', 'company_id', 'voucher_id', 'counterparty_org_number', 'counterparty_voucher_ref', 'kind', 'created_by', 'created_at', 'updated_at']);

    // A user without vouchers:create in B gets no paired draft there.
    $accountant = Bookkeeping::member($this->set->a, 'accountant');
    $this->set->b->users()->attach($accountant, ['role' => 'auditor']);

    expect(CreatePairedDraft::counterparts($accountant->fresh(), $this->set->a))->toBeEmpty()
        ->and(fn () => app(CreatePairedDraft::class)($accountant->fresh(), $loan, $this->set->b->id, [...$proposal, 'kind' => 'RECEIVABLE']))->toThrow(ValidationException::class, 'Välj ett koncernföretag')
        ->and(CurrentCompany::run($this->set->b, fn (): int => Voucher::query()->where('status', 'DRAFT')->count()))->toBe(1);
});

it('never posts anything in the receiving company', function () {
    $posted = fn (): int => DB::table('vouchers')->where('company_id', $this->set->b->id)->where('status', 'POSTED')->count();
    $before = $posted();

    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    expect($posted())->toBe($before);
});
