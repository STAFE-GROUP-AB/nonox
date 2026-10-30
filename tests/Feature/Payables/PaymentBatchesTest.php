<?php

declare(strict_types=1);

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SettlePaymentBatch;
use App\Domain\Payables\PaymentBank;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Payables\PaymentBatches;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use App\Support\Pain001\CreditTransferFile;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->company->forceFill(['separate_supplier_approval' => false])->save();

    $this->wholesaler = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    $this->power = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Elbolaget AB', 'plusgiro' => '4123451', 'payment_terms_days' => 10, 'default_account' => 5010]);
});

function approvedSupplierInvoice(User $user, Company $company, Supplier $supplier, string $total, ?string $ocr = null, string $number = 'F-1'): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_number' => $number,
        'ocr' => $ocr,
        'invoice_date' => '2026-03-05',
        'total' => $total,
        'rows' => [['account' => '', 'amount' => $total]],
    ]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

function useHandelsbanken(User $user, Company $company): void
{
    app(SavePaymentBankSettings::class)($user, $company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
}

it('saves the bank for payment files and checks the IBAN', function () {
    expect(fn () => app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'NORDEA', 'iban' => 'SE12', 'bank_agreement_id' => 'X1']))
        ->toThrow(ValidationException::class, 'SE följt av 22 siffror');

    useHandelsbanken($this->owner, $this->company);

    expect($this->company->fresh()->payment_bank)->toBe(PaymentBank::Handelsbanken)
        ->and($this->company->fresh()->iban)->toBe('SE4550000000058398257466')
        ->and($this->company->fresh()->bic)->toBe('HANDSESS')
        ->and(fn () => app(SavePaymentBankSettings::class)(Bookkeeping::member($this->company, 'bookkeeper'), $this->company, ['payment_bank' => 'NORDEA', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => 'X']))
        ->toThrow(AuthorizationException::class);
});

it('makes a pain.001 file with OCR for bankgiro and a message for plusgiro', function () {
    useHandelsbanken($this->owner, $this->company);
    $food = approvedSupplierInvoice($this->owner, $this->company, $this->wholesaler, '1620,00', '4455667');
    $power = approvedSupplierInvoice($this->owner, $this->company, $this->power, '500,50', number: 'EL-12');

    $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, [$food->id, $power->id], CarbonImmutable::parse('2026-03-12'));
    $xml = Storage::disk('local')->get($batch->file_path);
    $doc = new DOMDocument;
    $doc->loadXML($xml);
    $path = new DOMXPath($doc);
    $path->registerNamespace('p', CreditTransferFile::NAMESPACE);
    $text = fn (string $query): array => array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($path->query($query)));

    expect($batch->number)->toBe(1)
        ->and($batch->payment_count)->toBe(2)
        ->and($batch->total_ore->ore)->toBe(212_050)
        ->and(hash('sha256', $xml))->toBe($batch->file_sha256)
        ->and($text('//p:GrpHdr/p:CtrlSum'))->toBe(['2120.50'])
        ->and($text('//p:InitgPty/p:Id/p:OrgId/p:Othr/p:Id'))->toBe(['5566778899'])
        ->and($text('//p:InitgPty//p:SchmeNm/p:Cd'))->toBe(['BANK'])
        ->and($text('//p:DbtrAcct/p:Id/p:IBAN'))->toBe(['SE4550000000058398257466'])
        ->and($text('//p:ReqdExctnDt'))->toBe(['2026-03-12'])
        ->and($text('//p:CdtTrfTxInf/p:PmtId/p:EndToEndId'))->toBe(["LF{$power->number}", "LF{$food->number}"])
        ->and($text('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'))->toBe(['500.50', '1620.00'])
        ->and($text('//p:CdtrAcct/p:Id/p:Othr/p:Id'))->toBe(['4123451', '50501055'])
        ->and($text('//p:CdtrAgt//p:MmbId'))->toBe(['9500', '9900'])
        ->and($text('//p:CdtrRefInf/p:Ref'))->toBe(['4455667'])
        ->and($text('//p:RmtInf/p:Ustrd'))->toBe(['Faktura EL-12']);
});

it('pays to the bankgiro the supplier had when the invoice was registered', function () {
    useHandelsbanken($this->owner, $this->company);
    $invoice = approvedSupplierInvoice($this->owner, $this->company, $this->wholesaler, '100');
    app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5402-9681', 'payment_terms_days' => 30], $this->wholesaler);

    $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12'));

    expect(Storage::disk('local')->get($batch->file_path))->toContain('<Id>50501055</Id>')->not->toContain('54029681');
});

it('refuses invoices that are not approved, already in a file, or have no giro number', function () {
    expect(fn () => app(CreatePaymentBatch::class)($this->owner, $this->company, [], CarbonImmutable::parse('2026-03-12')))->toThrow(ValidationException::class, 'Fyll i bank');

    useHandelsbanken($this->owner, $this->company);
    $invoice = approvedSupplierInvoice($this->owner, $this->company, $this->wholesaler, '100');
    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, ['supplier_id' => $this->wholesaler->id, 'invoice_date' => '2026-03-05', 'total' => '50', 'rows' => [['account' => '', 'amount' => '50']]]);
    $noGiro = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Kontanta AB', 'payment_terms_days' => 30, 'default_account' => 4010]);
    $cashOnly = approvedSupplierInvoice($this->owner, $this->company, $noGiro, '75');

    app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12'));

    expect(fn () => app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id, $draft->id, $cashOnly->id], CarbonImmutable::parse('2026-03-12')))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(CreatePaymentBatch::class)($this->owner, $this->company, [$cashOnly->id], CarbonImmutable::parse('2026-03-09')))
        ->toThrow(ValidationException::class, 'passerad');

    try {
        app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id, $draft->id, $cashOnly->id], CarbonImmutable::parse('2026-03-12'));
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['batch']))
            ->toContain('finns redan i en betalfil')
            ->toContain('inte attesterad')
            ->toContain('inget bankgiro eller plusgiro');
    }
});

it('books every payment when the bank has paid, and frees the invoices when cancelled', function () {
    useHandelsbanken($this->owner, $this->company);
    $food = approvedSupplierInvoice($this->owner, $this->company, $this->wholesaler, '1620');
    $power = approvedSupplierInvoice($this->owner, $this->company, $this->power, '500');

    $first = app(CreatePaymentBatch::class)($this->owner, $this->company, [$power->id], CarbonImmutable::parse('2026-03-12'));
    app(SettlePaymentBatch::class)->cancel($this->owner, $first);

    $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, [$food->id, $power->id], CarbonImmutable::parse('2026-03-12'));
    app(SettlePaymentBatch::class)->confirm($this->owner, $batch);

    expect($first->fresh()->status)->toBe(PaymentBatchStatus::Cancelled)
        ->and($batch->fresh()->status)->toBe(PaymentBatchStatus::Confirmed)
        ->and($food->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($power->fresh()->payments()->sole()->paid_on->toDateString())->toBe('2026-03-12')
        ->and($power->fresh()->payments()->sole()->voucher->reference())->toStartWith('E')
        ->and($batch->items()->whereNull('supplier_payment_id')->count())->toBe(0)
        ->and(fn () => app(SettlePaymentBatch::class)->confirm($this->owner, $batch->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', $batch->id)->update(['total_ore' => 1])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_payment_batches')->where('id', $first->id)->update(['status' => 'CONFIRMED'])))->toThrow(QueryException::class);
});

describe('in the browser', function () {
    it('sets up the bank, makes a file, downloads it and confirms it, with toasts', function () {
        $invoice = approvedSupplierInvoice($this->owner, $this->company, $this->wholesaler, '1620', '4455667');

        $component = Livewire::test(PaymentBatches::class)
            ->assertSet('editingBank', true)
            ->set('bank', ['payment_bank' => 'NORDEA', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => 'AGR-1'])
            ->call('saveBank')
            ->assertDispatched('toast', message: 'Bankuppgifterna för betalfiler är sparade.');

        $component = Livewire::test(PaymentBatches::class)
            ->assertSet('selected', [])
            ->set('selected', [$invoice->id])
            ->set('executionDate', '2026-03-12')
            ->call('create')
            ->assertDispatched('toast', message: 'Betalfil 1 är skapad med 1 betalningar. Ladda ned den och läs in den i internetbanken.');

        $batch = SupplierPaymentBatch::query()->sole();
        $this->get(route('payables.batches.file', $batch))->assertOk()->assertDownload('betalfil-1-2026-03-12.xml');
        expect(Storage::disk('local')->get($batch->file_path))->toContain('<Cd>CUST</Cd>')->toContain('<BIC>BGABSESS</BIC>');

        $component->call('confirm', $batch->id)->assertDispatched('toast', message: 'Betalfil 1 är markerad som betald. Betalningarna är bokförda.');
        $this->get(route('payables.batches.index'))->assertOk()->assertSee('Betalfil 1');

        [$stranger] = Bookkeeping::company();
        $this->actingAs($stranger)->get(route('payables.batches.file', $batch))->assertNotFound();
    });
});
