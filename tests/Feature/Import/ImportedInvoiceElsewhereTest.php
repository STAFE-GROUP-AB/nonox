<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SendReminder;
use App\Actions\Invoicing\WriteOffInvoice;
use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\ImportPaymentResponseFile;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Domain\Invoicing\IncomingPaymentMatcher;
use App\Domain\Overview\Todos\CompanyTasks;
use App\Livewire\Invoicing\IncomingPayments;
use App\Migration\Import\OpenItemReconciliation;
use App\Migration\Import\RegisterKind;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatchItem;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Support\Camt054\BankCredit;
use App\Support\Money\Money;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Uid\Uuid;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, second review round: an imported invoice has no NONOX number, so
 * everything that showed the number must show the old one, and payment files
 * must still give every payment its own end-to-end id.
 */
const ELSEWHERE_MAPPING = ['party_ref' => 'Kund', 'invoice_number' => 'Fakturanr', 'invoice_date' => 'Fakturadatum', 'due_date' => 'Forfallodatum', 'total' => 'Belopp', 'remaining' => 'Obetalt', 'vat' => 'Moms', 'ocr' => 'OCR'];

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1510 => 1_350_000, 3001 => -1_350_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-02', [5410 => 500_000, 2440 => -500_000]);
    RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    RegisterImports::run($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::fixture('leverantorer-spiris-lik.csv'), 'spiris');
});

function elsewhereCustomers(object $test): void
{
    RegisterImports::run($test->owner, $test->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents("Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;123456789\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00;987654321\n", 'k.csv'), 'nonox', ['mapping' => ELSEWHERE_MAPPING]);
}

function elsewhereSuppliers(object $test): void
{
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms\n2001;L-1;2026-09-03;2026-10-03;3 000,00;3 000,00;600,00\n2002;L-2;2026-09-03;2026-10-03;2 000,00;2 000,00;400,00\n";
    RegisterImports::run($test->owner, $test->company, RegisterKind::OpenSupplierInvoices, RegisterImports::contents($csv, 's.csv'), 'nonox', ['mapping' => array_diff_key(ELSEWHERE_MAPPING, ['ocr' => 1])]);
}

it('gives two imported supplier invoices in one payment file their own end-to-end ids, blocks and answers', function () {
    elsewhereSuppliers($this);
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
    $invoices = CurrentCompany::run($this->company, fn () => SupplierInvoice::query()->orderBy('invoice_number')->get());

    $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, $invoices->pluck('id')->all(), CarbonImmutable::parse('2026-11-12'), true);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $batch = $batch->fresh();
    $items = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->get();
    $ids = $items->pluck('end_to_end_id');
    $file = Storage::disk('local')->get($batch->file_path);

    expect($items)->toHaveCount(2)
        ->and($ids->unique())->toHaveCount(2)
        ->and($ids->every(fn (string $id): bool => strlen($id) <= 35 && str_starts_with($id, 'LFI')))->toBeTrue()
        ->and($items->pluck('payment_info_id')->every(fn ($id) => filled($id)))->toBeTrue()
        ->and(substr_count($file, '<EndToEndId>'))->toBe(2)
        ->and($file)->toContain("<EndToEndId>{$ids[0]}</EndToEndId>")->toContain("<EndToEndId>{$ids[1]}</EndToEndId>");

    $first = $items->firstWhere('supplier_invoice_id', $invoices[0]->id);
    $second = $items->firstWhere('supplier_invoice_id', $invoices[1]->id);

    $status = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03"><CstmrPmtStsRpt>
        <GrpHdr><MsgId>S1</MsgId><CreDtTm>2026-11-11T08:00:00</CreDtTm></GrpHdr>
        <OrgnlGrpInfAndSts><OrgnlMsgId>{$batch->message_id}</OrgnlMsgId><OrgnlMsgNmId>pain.001.001.03</OrgnlMsgNmId><GrpSts>PART</GrpSts></OrgnlGrpInfAndSts>
        <OrgnlPmtInfAndSts><OrgnlPmtInfId>{$batch->message_id}</OrgnlPmtInfId>
        <TxInfAndSts><OrgnlEndToEndId>{$first->end_to_end_id}</OrgnlEndToEndId><TxSts>ACCP</TxSts></TxInfAndSts>
        <TxInfAndSts><OrgnlEndToEndId>{$second->end_to_end_id}</OrgnlEndToEndId><TxSts>RJCT</TxSts><StsRsnInf><Rsn><Cd>AC03</Cd></Rsn><AddtlInf>Beneficiary account number is incorrect</AddtlInf></StsRsnInf></TxInfAndSts>
        </OrgnlPmtInfAndSts></CstmrPmtStsRpt></Document>
        XML;
    app(ImportPaymentResponseFile::class)($this->owner, $this->company, UploadedFile::fake()->createWithContent('status.xml', $status));

    expect($first->fresh()->bank_status)->toBe('ACCEPTED')
        ->and($second->fresh()->isRejected())->toBeTrue();

    $debit = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.02"><BkToCstmrDbtCdtNtfctn>
        <GrpHdr><MsgId>D1</MsgId><CreDtTm>2026-11-12T18:00:00</CreDtTm></GrpHdr>
        <Ntfctn><Id>N1</Id><Ntry><Amt Ccy="SEK">3000.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-11-12</Dt></BookgDt><AcctSvcrRef>B1</AcctSvcrRef><NtryDtls><TxDtls><Refs><MsgId>{$batch->message_id}</MsgId><PmtInfId>{$batch->message_id}</PmtInfId><EndToEndId>{$first->end_to_end_id}</EndToEndId></Refs><AmtDtls><TxAmt><Amt Ccy="SEK">3000.00</Amt></TxAmt></AmtDtls></TxDtls></NtryDtls></Ntry></Ntfctn>
        </BkToCstmrDbtCdtNtfctn></Document>
        XML;
    app(ImportPaymentResponseFile::class)($this->owner, $this->company, UploadedFile::fake()->createWithContent('debit.xml', $debit));

    $paid = CurrentCompany::run($this->company, fn () => SupplierInvoice::query()->where('status', 'PAID')->get());
    expect($paid)->toHaveCount(1)->and($paid[0]->invoice_number)->toBe('L-1');
});

it('gives imported invoices different end-to-end ids even when their ids share a millisecond prefix', function () {
    $counter = 0;
    Str::createUuidsUsing(function () use (&$counter) {
        return Uuid::fromString(sprintf('01234567-89ab-7cde-8f01-%012x', ++$counter));
    });

    try {
        elsewhereSuppliers($this);
        $this->company->forceFill(['separate_supplier_approval' => false])->save();
        app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
        $invoices = CurrentCompany::run($this->company, fn () => SupplierInvoice::query()->orderBy('invoice_number')->get());
        $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, $invoices->pluck('id')->all(), CarbonImmutable::parse('2026-11-12'), true);
    } finally {
        Str::createUuidsNormally();
    }

    $prefixes = $invoices->map(fn (SupplierInvoice $invoice): string => substr(str_replace('-', '', (string) $invoice->id), 0, 20))->unique();
    $ids = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->pluck('end_to_end_id');

    expect($prefixes)->toHaveCount(1)
        ->and($ids->unique())->toHaveCount(2)
        ->and($ids->sort()->values()->all())->toBe(["LFI{$batch->number}-1", "LFI{$batch->number}-2"]);
});

it('shows an imported invoice by its old number on the incoming payments page', function () {
    elsewhereCustomers($this);
    $invoice = CurrentCompany::run($this->company, fn () => Invoice::query()->where('external_ref', '5001')->firstOrFail());
    CurrentCompany::run($this->company, fn () => BankTransaction::create(['reference' => 'REF-1', 'booking_date' => '2026-11-09', 'amount_ore' => 1_250_000, 'ocr' => '123456789', 'invoice_id' => $invoice->id]));

    Livewire::test(IncomingPayments::class)->assertSee('Faktura 5001 (tidigare program)')->assertDontSee('Utkast till faktura');
});

it('shows the old number on the payment batch list, in the overdue tasks, and in the data subject export', function () {
    elsewhereCustomers($this);
    elsewhereSuppliers($this);
    $supplierInvoice = CurrentCompany::run($this->company, fn () => SupplierInvoice::query()->orderBy('invoice_number')->firstOrFail());

    expect(file_get_contents(base_path('resources/views/livewire/payables/payment-batches.blade.php')))->toContain('isImported()');

    $tasks = collect(app(CompanyTasks::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->pluck('title')->implode(' | ');
    expect($tasks)->toContain('Faktura 5001 (tidigare program) är förfallen');

    $customer = CurrentCompany::run($this->company, fn () => Customer::query()->where('external_ref', '1001')->firstOrFail());
    $export = DataSubjectExport::customer($customer);
    expect($export['fakturor'][0])->toMatchArray(['nummer' => null, 'nummer_i_tidigare_program' => '5001'])
        ->and($supplierInvoice->title())->toContain('(tidigare program)');
});

it('writes the old number to the audit entries of payment, write-off and reminder', function () {
    elsewhereCustomers($this);
    $invoice = CurrentCompany::run($this->company, fn () => Invoice::query()->where('external_ref', '5002')->firstOrFail());

    CurrentCompany::run($this->company, fn () => app(SendReminder::class)($this->owner, $invoice, 'a@example.test'));
    CurrentCompany::run($this->company, fn () => app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-11-09'), Money::ofOre(50_000)));
    CurrentCompany::run($this->company, fn () => app(WriteOffInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-11-09'), 'Konkurs'));

    $entries = AuditLog::query()->whereIn('action', ['invoice.reminder_sent', 'invoice.payment_registered', 'invoice.written_off'])->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->every(fn (AuditLog $entry): bool => ($entry->payload['external_ref'] ?? null) === '5002'))->toBeTrue();
});

it('carries external_ref in the API presenter and the OpenAPI schema', function () {
    elsewhereCustomers($this);
    $invoice = CurrentCompany::run($this->company, fn () => Invoice::query()->where('external_ref', '5001')->firstOrFail());

    expect(ApiPresenter::invoice($invoice))->toMatchArray(['number' => null, 'external_ref' => '5001'])
        ->and(file_get_contents(base_path('docs/api/openapi.yaml')))->toContain('external_ref:')
        ->and(file_get_contents(base_path('docs/api/CHANGELOG.md')))->toContain('external_ref på fakturor');
});

it('keeps imported invoices out of the pick lists', function () {
    elsewhereCustomers($this);

    expect(file_get_contents(base_path('app/Modules/Inventory/Livewire/PickListsPage.php')))->toContain("whereNull('import_source')");
});

it('reads an overpaid invoice as a credit and refuses extra accounts outside the receivable family', function () {
    // 12 500,00 owed, and a customer who overpaid 20,00: the file sums to 12 480,00.
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;1\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00;2\n1002;5003;2026-09-20;2026-10-20;500,00;-20,00;100,00;3\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ELSEWHERE_MAPPING, 'extra_accounts' => '1511, 1930, 2440, 1510, 1600']);

    expect($import->options['extra_accounts'])->toBe([1511])
        ->and($import->report['counts']['error'])->toBe(0)
        ->and($import->report['counts']['create'])->toBe(3);
});

it('creates an overpaid invoice as a credit for what was overpaid', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-08', [3001 => 11_000, 1510 => -11_000]);
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;1\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00;2\n1002;5003;2026-09-20;2026-10-20;500,00;-110,00;100,00;3\n";
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv), 'nonox', ['mapping' => ELSEWHERE_MAPPING]);
    $credit = CurrentCompany::run($this->company, fn () => Invoice::query()->where('external_ref', '5003')->firstOrFail());

    expect($import->status)->toBe('COMPLETED')
        ->and($credit->kind)->toBe(Invoice::CREDIT)
        ->and($credit->total_ore->ore)->toBe(-11_000)
        ->and($credit->remaining()->ore)->toBe(-11_000);
});

it('subtracts the ROT or RUT part that sits on 1513 from what is open in NONOX', function () {
    CurrentCompany::run($this->company, function () {
        $customer = Customer::query()->firstOrFail();
        (new Invoice)->forceFill(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'number' => 1, 'status' => 'ISSUED', 'invoice_date' => '2026-03-06', 'due_date' => '2026-04-06', 'total_ore' => 100_000, 'net_ore' => 80_000, 'vat_ore' => 20_000, 'tax_reduction_ore' => 30_000, 'issued_at' => now()])->save();

        expect(OpenItemReconciliation::openInNonox(RegisterKind::OpenCustomerInvoices))->toBe(70_000);
    });
});

it('reads the old number in a payment message only when no open NONOX invoice has that number', function () {
    elsewhereCustomers($this);
    CurrentCompany::run($this->company, function () {
        $customer = Customer::query()->firstOrFail();
        (new Invoice)->forceFill(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'number' => 5001, 'status' => 'ISSUED', 'invoice_date' => '2026-03-06', 'due_date' => '2026-04-06', 'total_ore' => 1_000, 'net_ore' => 1_000, 'issued_at' => now()])->save();
    });
    $credit = new BankCredit('r', CarbonImmutable::parse('2026-11-09'), 1_250_000, null, 'Faktura 5001', 'Kund');

    expect(CurrentCompany::run($this->company, fn () => (new IncomingPaymentMatcher)->match($credit)))->toBeNull();
});

it('says in the manual that search finds old numbers only when they are all digits', function () {
    expect(file_get_contents(base_path('docs/manual/sv/flytta/las-in-register.md')))->toContain('bara när det består av siffror');
});
