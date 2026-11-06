<?php

declare(strict_types=1);

use App\Actions\Payables\ApplyPaymentResponses;
use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\ImportPaymentResponseFile;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SettlePaymentBatch;
use App\Domain\Overview\Todos\BankTodos;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\PaymentResponseStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Payables\PaymentBatches;
use App\Livewire\Payables\PaymentResponses;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PaymentResponse;
use App\Models\PaymentResponseImport;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Banking\Actions\FetchBankData;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Banking;
use Tests\Support\Bookkeeping;

/*
 * The return path for payment files (ADR-014): the bank's status report
 * (pain.002) and debit notification or statement (camt.054, camt.053) are
 * matched to the payments NONOX wrote, rejected payments are freed with the
 * bank's reason, and booked debits are paid in series E through
 * PaySupplierInvoice. The same answer never books twice.
 */
beforeEach(function () {
    Storage::fake('local');
    FakeBankDataProvider::reset();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);

    $this->wholesaler = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    $this->power = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Elbolaget AB', 'plusgiro' => '4123451', 'payment_terms_days' => 10, 'default_account' => 5010]);
    $this->food = brInvoice($this->owner, $this->company, $this->wholesaler, '1620', 'F-1');
    $this->bill = brInvoice($this->owner, $this->company, $this->power, '500,50', 'EL-12');
    $this->batch = app(CreatePaymentBatch::class)($this->owner, $this->company, [$this->food->id, $this->bill->id], CarbonImmutable::parse('2026-03-12'), true);
    app(ApprovePaymentBatch::class)($this->owner, $this->batch);
    $this->batch = $this->batch->fresh();
});

afterEach(fn () => FakeBankDataProvider::reset());

function brInvoice(User $user, Company $company, Supplier $supplier, string $total, string $number): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_number' => $number,
        'invoice_date' => '2026-03-05',
        'total' => $total,
        'rows' => [['account' => '', 'amount' => $total]],
    ]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

function brItem(SupplierPaymentBatch $batch, SupplierInvoice $invoice): SupplierPaymentBatchItem
{
    return SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->where('supplier_invoice_id', $invoice->id)->sole();
}

/**
 * A status report for a payment file.
 *
 * @param  array<string, array{0: string, 1?: string, 2?: string}>  $statuses  end-to-end id => [status, reason code, text]
 */
function brPain002(SupplierPaymentBatch $batch, array $statuses, string $messageId = 'STATUS-1'): string
{
    $transactions = '';

    foreach ($statuses as $endToEndId => $status) {
        $reason = isset($status[1]) ? "<StsRsnInf><Rsn><Cd>{$status[1]}</Cd></Rsn><AddtlInf>".($status[2] ?? '').'</AddtlInf></StsRsnInf>' : '';
        $transactions .= "<TxInfAndSts><OrgnlEndToEndId>{$endToEndId}</OrgnlEndToEndId><TxSts>{$status[0]}</TxSts>{$reason}</TxInfAndSts>";
    }

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03"><CstmrPmtStsRpt>
        <GrpHdr><MsgId>{$messageId}</MsgId><CreDtTm>2026-03-11T08:00:00</CreDtTm></GrpHdr>
        <OrgnlGrpInfAndSts><OrgnlMsgId>{$batch->message_id}</OrgnlMsgId><OrgnlMsgNmId>pain.001.001.03</OrgnlMsgNmId><GrpSts>PART</GrpSts></OrgnlGrpInfAndSts>
        <OrgnlPmtInfAndSts><OrgnlPmtInfId>{$batch->message_id}</OrgnlPmtInfId>{$transactions}</OrgnlPmtInfAndSts>
        </CstmrPmtStsRpt></Document>
        XML;
}

/**
 * A debit notification with one booked debit per payment.
 *
 * @param  list<array{reference: string, amount: string, date?: string, e2e?: ?string, account?: string, message_id?: string}>  $debits
 */
function brCamt054(array $debits, string $messageId = 'DEBIT-1'): string
{
    $entries = '';

    foreach ($debits as $debit) {
        $refs = isset($debit['e2e']) ? "<Refs><MsgId>{$debit['message_id']}</MsgId><PmtInfId>{$debit['message_id']}</PmtInfId><EndToEndId>{$debit['e2e']}</EndToEndId></Refs>" : '';
        $account = isset($debit['account']) ? "<RltdPties><CdtrAcct><Id><Othr><Id>{$debit['account']}</Id></Othr></Id></CdtrAcct></RltdPties>" : '';
        $date = $debit['date'] ?? '2026-03-12';
        $entries .= "<Ntry><Amt Ccy=\"SEK\">{$debit['amount']}</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>{$date}</Dt></BookgDt><AcctSvcrRef>{$debit['reference']}</AcctSvcrRef><NtryDtls><TxDtls>{$refs}<AmtDtls><TxAmt><Amt Ccy=\"SEK\">{$debit['amount']}</Amt></TxAmt></AmtDtls>{$account}</TxDtls></NtryDtls></Ntry>";
    }

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.02"><BkToCstmrDbtCdtNtfctn>
        <GrpHdr><MsgId>{$messageId}</MsgId><CreDtTm>2026-03-12T18:00:00</CreDtTm></GrpHdr>
        <Ntfctn><Id>N1</Id>{$entries}</Ntfctn>
        </BkToCstmrDbtCdtNtfctn></Document>
        XML;
}

/** @return array{import: PaymentResponseImport, created: int, skipped: int, ignored: int, applied: int, open: int} */
function brUpload(User $user, Company $company, string $xml, string $name = 'svar.xml'): array
{
    return app(ImportPaymentResponseFile::class)($user, $company, UploadedFile::fake()->createWithContent($name, $xml));
}

/** Sum of debits minus credits on a voucher; zero when it balances. */
function brBalance(Voucher $voucher): int
{
    return $voucher->rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore);
}

it('writes a unique end-to-end id for every payment, naming the file', function () {
    expect(brItem($this->batch, $this->food)->end_to_end_id)->toBe("LF{$this->food->number}-{$this->batch->number}")
        ->and(Storage::disk('local')->get($this->batch->file_path))->toContain("<EndToEndId>LF{$this->bill->number}-{$this->batch->number}</EndToEndId>");
});

it('marks a rejected payment with the bank\'s reason in Swedish and frees the invoice, then books the debit for the other', function () {
    $food = brItem($this->batch, $this->food);
    $bill = brItem($this->batch, $this->bill);

    $status = brUpload($this->owner, $this->company, brPain002($this->batch, [
        $food->end_to_end_id => ['ACCP'],
        $bill->end_to_end_id => ['RJCT', 'AC03', 'Beneficiary account number is incorrect'],
    ]), 'status.xml');

    expect($status['created'])->toBe(2)
        ->and($status['applied'])->toBe(2)
        ->and($food->fresh()->bank_status)->toBe('ACCEPTED')
        ->and($bill->fresh()->isRejected())->toBeTrue()
        ->and($bill->fresh()->rejectionReason())->toBe('Mottagarens konto är fel. (AC03: Beneficiary account number is incorrect)')
        ->and($this->bill->fresh()->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($this->batch->fresh()->status)->toBe(PaymentBatchStatus::Created)
        ->and(AuditLog::query()->where('action', 'payment_batch.payment_rejected')->sole()->payload['reason_code'])->toBe('AC03')
        ->and(collect(app(BankTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->pluck('key')->all())->toContain('payment-rejected');

    // The rejected invoice can go in a new file; the accepted one cannot.
    Livewire::test(PaymentBatches::class)->assertViewHas('payable', fn ($payable) => $payable->pluck('id')->all() === [$this->bill->id]);

    $debit = brUpload($this->owner, $this->company, brCamt054([['reference' => 'BOOK-1', 'amount' => '1620.00', 'e2e' => $food->end_to_end_id, 'message_id' => $this->batch->message_id]]), 'debit.xml');
    $payment = SupplierPayment::query()->sole();
    $voucher = $payment->voucher()->with('rows')->firstOrFail();

    expect($debit['applied'])->toBe(1)
        ->and($this->food->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($payment->paid_on->toDateString())->toBe('2026-03-12')
        ->and($voucher->reference())->toStartWith('E')
        ->and(brBalance($voucher))->toBe(0)
        ->and($food->fresh()->supplier_payment_id)->toBe($payment->id)
        ->and($this->batch->fresh()->status)->toBe(PaymentBatchStatus::Confirmed)
        ->and(AuditLog::query()->where('action', 'payment_batch.confirmed')->sole()->payload)->toMatchArray(['via' => 'bank_response', 'paid' => 1, 'rejected' => 1])
        ->and(AuditLog::query()->where('action', 'payment_response_file.imported')->count())->toBe(2);
});

it('never books twice: the same file is refused, the same debit in another file is skipped', function () {
    $food = brItem($this->batch, $this->food);
    $xml = brCamt054([['reference' => 'BOOK-1', 'amount' => '1620.00', 'e2e' => $food->end_to_end_id, 'message_id' => $this->batch->message_id]]);

    brUpload($this->owner, $this->company, $xml);

    expect(fn () => brUpload($this->owner, $this->company, $xml))->toThrow(ValidationException::class, 'redan inläst');

    $again = brUpload($this->owner, $this->company, brCamt054([['reference' => 'BOOK-1', 'amount' => '1620.00', 'e2e' => $food->end_to_end_id, 'message_id' => $this->batch->message_id]], 'DEBIT-2'));

    // The same payment under another bank reference (a statement after the notification) is recognised as paid.
    $statement = brUpload($this->owner, $this->company, brCamt054([['reference' => 'STMT-9', 'amount' => '1620.00', 'e2e' => $food->end_to_end_id, 'message_id' => $this->batch->message_id]], 'DEBIT-3'));

    expect($again['skipped'])->toBe(1)
        ->and($again['created'])->toBe(0)
        ->and($statement['created'])->toBe(1)
        ->and(PaymentResponse::query()->where('reference', 'camt:STMT-9')->sole()->status)->toBe(PaymentResponseStatus::Duplicate)
        ->and(SupplierPayment::query()->count())->toBe(1)
        ->and(Voucher::query()->whereHas('series', fn ($query) => $query->where('code', 'E'))->count())->toBe(1);
});

it('rejects every payment when the bank rejects the whole file, and cancels the file', function () {
    $xml = <<<XML
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03"><CstmrPmtStsRpt>
        <GrpHdr><MsgId>S-9</MsgId><CreDtTm>2026-03-11T08:00:00</CreDtTm></GrpHdr>
        <OrgnlGrpInfAndSts><OrgnlMsgId>{$this->batch->message_id}</OrgnlMsgId><GrpSts>RJCT</GrpSts><StsRsnInf><Rsn><Cd>FF01</Cd></Rsn></StsRsnInf></OrgnlGrpInfAndSts>
        </CstmrPmtStsRpt></Document>
        XML;

    $result = brUpload($this->owner, $this->company, $xml);
    $response = PaymentResponse::query()->sole();

    expect($result['applied'])->toBe(1)
        ->and($response->batch_id)->toBe($this->batch->id)
        ->and($response->batch_item_id)->toBeNull()
        ->and($this->batch->items()->get()->every(fn (SupplierPaymentBatchItem $item): bool => $item->isRejected()))->toBeTrue()
        ->and($this->batch->fresh()->status)->toBe(PaymentBatchStatus::Cancelled)
        ->and(brItem($this->batch, $this->food)->rejectionReason())->toBe('Filen har fel format. (FF01)');

    // Both invoices can be paid in a new file.
    $next = app(CreatePaymentBatch::class)($this->owner, $this->company, [$this->food->id, $this->bill->id], CarbonImmutable::parse('2026-03-13'), true);
    expect($next->payment_count)->toBe(2);
});

it('suggests a debit without references by amount, date and bankgiro, and books it only when a person confirms', function () {
    $result = brUpload($this->owner, $this->company, brCamt054([
        ['reference' => 'NOREF-1', 'amount' => '1620.00', 'account' => '5050-1055'],
        ['reference' => 'CARD-1', 'amount' => '129.00'],
    ]));
    $suggestion = PaymentResponse::query()->sole();

    expect($result['created'])->toBe(1)
        ->and($result['ignored'])->toBe(1)
        ->and($suggestion->status)->toBe(PaymentResponseStatus::Suggested)
        ->and($suggestion->match)->toBe(PaymentResponse::BY_AMOUNT)
        ->and($suggestion->batch_item_id)->toBe(brItem($this->batch, $this->food)->id)
        ->and(SupplierPayment::query()->count())->toBe(0);

    Livewire::test(PaymentResponses::class)
        ->assertSee('Behöver bekräftas')
        ->assertSee('Förslag efter belopp, datum och mottagare')
        ->call('confirm', $suggestion->id)
        ->assertDispatched('toast', message: 'Svaret från banken är bekräftat och bokfört.')
        ->assertDispatched('payment-responses-updated');

    expect($suggestion->fresh()->status)->toBe(PaymentResponseStatus::Applied)
        ->and($suggestion->fresh()->resolved_by)->toBe($this->owner->id)
        ->and($this->food->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and(AuditLog::query()->where('action', 'payment_response.confirmed')->exists())->toBeTrue()
        ->and(fn () => app(ApplyPaymentResponses::class)->confirm($this->owner, $suggestion->fresh()))->toThrow(ValidationException::class, 'redan hanterat');
});

it('does not suggest when the bankgiro differs or two payments fit', function () {
    $other = brInvoice($this->owner, $this->company, $this->wholesaler, '1620', 'F-2');
    app(CreatePaymentBatch::class)($this->owner, $this->company, [$other->id], CarbonImmutable::parse('2026-03-12'), true);

    $result = brUpload($this->owner, $this->company, brCamt054([
        ['reference' => 'NOREF-1', 'amount' => '1620.00', 'account' => '5050-1055'],
        ['reference' => 'NOREF-2', 'amount' => '500.50', 'account' => '9999999'],
    ]));

    expect($result['created'])->toBe(0)
        ->and($result['ignored'])->toBe(2);
});

it('holds a debit for a file that is not approved until it is, and lets a person dismiss an answer', function () {
    $invoice = brInvoice($this->owner, $this->company, $this->wholesaler, '300', 'F-3');
    $unapproved = app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12'), true);
    $item = brItem($unapproved, $invoice);

    brUpload($this->owner, $this->company, brCamt054([['reference' => 'BOOK-7', 'amount' => '300.00', 'e2e' => $item->end_to_end_id, 'message_id' => $unapproved->message_id]]));
    $held = PaymentResponse::query()->sole();

    expect($held->status)->toBe(PaymentResponseStatus::Suggested)
        ->and($held->noteText())->toBe('Betalfilen är inte godkänd i NONOX. Godkänn den först.')
        ->and(fn () => app(ApplyPaymentResponses::class)->confirm($this->owner, $held))->toThrow(ValidationException::class, 'inte godkänd');

    app(ApprovePaymentBatch::class)($this->owner, $unapproved);
    app(ApplyPaymentResponses::class)->confirm($this->owner, $held->fresh());

    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($unapproved->fresh()->status)->toBe(PaymentBatchStatus::Confirmed);

    brUpload($this->owner, $this->company, brCamt054([['reference' => 'ODD-1', 'amount' => '999.00', 'e2e' => brItem($this->batch, $this->bill)->end_to_end_id, 'message_id' => $this->batch->message_id]], 'DEBIT-9'));
    $odd = PaymentResponse::query()->where('reference', 'camt:ODD-1')->sole();

    expect($odd->status)->toBe(PaymentResponseStatus::Unmatched)
        ->and($odd->note)->toBe('amount_differs')
        ->and($this->bill->fresh()->status)->toBe(SupplierInvoiceStatus::Approved);

    Livewire::test(PaymentResponses::class)
        ->call('dismiss', $odd->id)
        ->assertDispatched('toast', message: 'Svaret från banken är avfärdat. Inget är bokfört.');

    expect($odd->fresh()->status)->toBe(PaymentResponseStatus::Dismissed)
        ->and(AuditLog::query()->where('action', 'payment_response.dismissed')->exists())->toBeTrue();
});

it('leaves a returned payment to a person, and a rejection after booking too', function () {
    $food = brItem($this->batch, $this->food);
    brUpload($this->owner, $this->company, brCamt054([['reference' => 'BOOK-1', 'amount' => '1620.00', 'e2e' => $food->end_to_end_id, 'message_id' => $this->batch->message_id]]));

    $return = <<<XML
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.02"><BkToCstmrDbtCdtNtfctn>
        <GrpHdr><MsgId>R-1</MsgId></GrpHdr>
        <Ntfctn><Ntry><Amt Ccy="SEK">1620.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><RvslInd>true</RvslInd><Sts>BOOK</Sts><BookgDt><Dt>2026-03-14</Dt></BookgDt><AcctSvcrRef>RET-1</AcctSvcrRef>
        <NtryDtls><TxDtls><Refs><EndToEndId>{$food->end_to_end_id}</EndToEndId></Refs><RtrInf><Rsn><Cd>AC04</Cd></Rsn></RtrInf></TxDtls></NtryDtls></Ntry></Ntfctn>
        </BkToCstmrDbtCdtNtfctn></Document>
        XML;
    brUpload($this->owner, $this->company, $return);
    brUpload($this->owner, $this->company, brPain002($this->batch, [$food->end_to_end_id => ['RJCT', 'AM04']], 'STATUS-LATE'));

    $returned = PaymentResponse::query()->where('kind', 'RETURNED')->sole();
    $late = PaymentResponse::query()->where('kind', 'REJECTED')->sole();

    expect($returned->status)->toBe(PaymentResponseStatus::Unmatched)
        ->and($returned->note)->toBe('returned')
        ->and($returned->reason())->toBe('Mottagarens konto är avslutat. (AC04)')
        ->and($late->status)->toBe(PaymentResponseStatus::Unmatched)
        ->and($late->note)->toBe('already_paid')
        ->and($food->fresh()->isPaid())->toBeTrue()
        ->and(SupplierPayment::query()->count())->toBe(1)
        ->and(collect(app(BankTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->firstWhere('key', 'payment-responses')?->title)->toBe('2 svar från banken om betalfiler att hantera');
});

it('books under kontantmetoden exactly as a manual payment does, with the costs on the payment', function () {
    $this->company->forceFill(['accounting_method' => 'KONTANTMETODEN'])->save();
    $invoice = brInvoice($this->owner, $this->company, $this->wholesaler, '1250', 'K-1');
    $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12'), true);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = brItem($batch, $invoice);

    brUpload($this->owner, $this->company, brCamt054([['reference' => 'K-BOOK', 'amount' => '1250.00', 'e2e' => $item->end_to_end_id, 'message_id' => $batch->message_id]]));

    $voucher = $invoice->fresh()->payments()->sole()->voucher()->with('rows.account')->firstOrFail();

    expect($voucher->reference())->toStartWith('E')
        ->and(brBalance($voucher))->toBe(0)
        ->and($voucher->rows->pluck('account.number')->all())->toContain(4010)
        ->and($voucher->rows->pluck('account.number')->all())->toContain(1930);
});

it('lets the manual confirmation book only the payments the bank has not answered, and refuses to cancel a partly paid file', function () {
    $food = brItem($this->batch, $this->food);
    brUpload($this->owner, $this->company, brCamt054([['reference' => 'BOOK-1', 'amount' => '1620.00', 'e2e' => $food->end_to_end_id, 'message_id' => $this->batch->message_id]]));

    expect(fn () => app(SettlePaymentBatch::class)->cancel($this->owner, $this->batch->fresh()))->toThrow(ValidationException::class, 'redan betalat en del');

    app(SettlePaymentBatch::class)->confirm($this->owner, $this->batch->fresh());

    expect(SupplierPayment::query()->count())->toBe(2)
        ->and($this->bill->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($this->batch->fresh()->status)->toBe(PaymentBatchStatus::Confirmed);
});

it('suggests a payment out fetched by NONOX Bank and books it when confirmed', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    Banking::mapped($this->owner, $this->company, $connection);
    FakeBankDataProvider::$transactions['1'] = [
        Banking::transaction('2026-03-12', -162_000, id: 'OUT-1', counterparty: 'Grossisten AB', reference: brItem($this->batch, $this->food)->end_to_end_id),
        Banking::transaction('2026-03-12', -4_900, id: 'CARD-1', counterparty: 'Kaffebaren'),
    ];

    app(FetchBankData::class)($this->company, $connection->fresh());
    $suggestion = PaymentResponse::query()->sole();

    expect($suggestion->status)->toBe(PaymentResponseStatus::Suggested)
        ->and($suggestion->match)->toBe(PaymentResponse::BY_REFERENCE)
        ->and($suggestion->bank_feed_transaction_id)->not->toBeNull()
        ->and(SupplierPayment::query()->count())->toBe(0);

    app(FetchBankData::class)($this->company, $connection->fresh());
    expect(PaymentResponse::query()->count())->toBe(1);

    app(ApplyPaymentResponses::class)->confirm($this->owner, $suggestion);

    expect($this->food->fresh()->status)->toBe(SupplierInvoiceStatus::Paid);
});

it('reads the file and shows the result on the payment files page, with toasts', function () {
    $file = UploadedFile::fake()->createWithContent('status.xml', brPain002($this->batch, [
        brItem($this->batch, $this->bill)->end_to_end_id => ['RJCT', 'AC03', 'Beneficiary account number is incorrect'],
    ]));

    Livewire::test(PaymentResponses::class)
        ->set('file', $file)
        ->assertDispatched('toast', message: 'Filen är inläst: 1 klara automatiskt, 0 att hantera.')
        ->assertSee('Avvisad av banken')
        ->assertSee('Mottagarens konto är fel.')
        ->assertSee('Statusrapport (pain.002)');

    $this->get(route('payables.batches.index'))->assertOk()
        ->assertSee('Läs in bankens svar')
        ->assertSee('Enligt bankens svar: 0 betalda, 1 avvisade, 1 väntar.');

    Livewire::test(PaymentResponses::class)
        ->set('file', UploadedFile::fake()->createWithContent('fel.xml', '<Invoice/>'))
        ->assertHasErrors('file');
});

it('lets only those who register payments read answers and confirm', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $xml = brPain002($this->batch, [brItem($this->batch, $this->bill)->end_to_end_id => ['RJCT', 'AC03']]);

    expect(fn () => brUpload($auditor, $this->company, $xml))->toThrow(AuthorizationException::class);

    brUpload($this->owner, $this->company, brCamt054([['reference' => 'X', 'amount' => '9.00', 'e2e' => 'LF999-1', 'message_id' => 'NONOX-999-UNKNOWN']]));
    $response = PaymentResponse::query()->sole();

    expect(fn () => app(ApplyPaymentResponses::class)->dismiss($auditor, $response))->toThrow(AuthorizationException::class);

    $this->actingAs($auditor);
    Livewire::test(PaymentResponses::class)->assertOk()->assertDontSee('Läs in bankens svar')->assertDontSee('Avfärda');
});

it('keeps answers as the bank gave them: the database refuses to change or remove them', function () {
    brUpload($this->owner, $this->company, brPain002($this->batch, [brItem($this->batch, $this->bill)->end_to_end_id => ['RJCT', 'AC03']]));
    $response = PaymentResponse::query()->sole();
    $item = brItem($this->batch, $this->bill);
    $import = PaymentResponseImport::query()->sole();

    expect(fn () => $response->forceFill(['status' => PaymentResponseStatus::Dismissed])->save())->toThrow(LogicException::class)
        ->and(fn () => $response->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payment_responses')->where('id', $response->id)->update(['amount_ore' => 1])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('payment_responses')->where('id', $response->id)->update(['status' => 'DISMISSED'])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('payment_responses')->where('id', $response->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batch_items')->where('id', $item->id)->update(['bank_status' => 'ACCEPTED'])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('payment_response_imports')->where('id', $import->id)->update(['file_name' => 'x'])))->toThrow(QueryException::class)
        ->and(fn () => $import->delete())->toThrow(LogicException::class);
});

it('never lets one company\'s file touch another company\'s payments', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $otherBatch = CurrentCompany::run($other, function () use ($otherOwner, $other): SupplierPaymentBatch {
        $other->forceFill(['separate_supplier_approval' => false])->save();
        app(SavePaymentBankSettings::class)($otherOwner, $other, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '1']);
        $supplier = app(SaveSupplier::class)($otherOwner, $other, ['name' => 'Annan AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
        $invoice = brInvoice($otherOwner, $other, $supplier, '1620', 'B-1');
        $batch = app(CreatePaymentBatch::class)($otherOwner, $other, [$invoice->id], CarbonImmutable::parse('2026-03-12'), true);
        app(ApprovePaymentBatch::class)($otherOwner, $batch);

        return $batch->fresh();
    });
    $otherItem = CurrentCompany::run($other, fn () => $otherBatch->items()->sole());

    // Company A reads a debit and a rejection naming company B's payment and file.
    brUpload($this->owner, $this->company, brCamt054([['reference' => 'B-BOOK', 'amount' => '1620.00', 'e2e' => $otherItem->end_to_end_id, 'message_id' => $otherBatch->message_id]]));
    brUpload($this->owner, $this->company, brPain002($otherBatch, [$otherItem->end_to_end_id => ['RJCT', 'AC03']], 'STATUS-B'));

    $own = PaymentResponse::query()->get();

    expect($own->pluck('batch_id')->filter()->all())->not->toContain($otherBatch->id)
        ->and($own->pluck('batch_item_id')->filter()->all())->not->toContain($otherItem->id)
        ->and(CurrentCompany::run($other, fn () => $otherItem->fresh()))->supplier_payment_id->toBeNull()
        ->and(CurrentCompany::run($other, fn () => $otherItem->fresh()))->bank_status->toBeNull()
        ->and(CurrentCompany::run($other, fn () => SupplierPayment::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($other, fn () => PaymentResponse::query()->count()))->toBe(0);

    // Company B's answers are invisible and unreachable from A.
    CurrentCompany::run($other, fn () => brUpload($otherOwner, $other, brPain002($otherBatch, [$otherItem->end_to_end_id => ['ACCP']], 'STATUS-B2')));
    $foreign = CurrentCompany::run($other, fn () => PaymentResponse::query()->sole());

    expect(PaymentResponse::query()->find($foreign->id))->toBeNull()
        ->and(fn () => PaymentResponse::query()->findOrFail($foreign->id))->toThrow(ModelNotFoundException::class);

    Livewire::test(PaymentResponses::class)->call('dismiss', $foreign->id)->assertStatus(404);
});
