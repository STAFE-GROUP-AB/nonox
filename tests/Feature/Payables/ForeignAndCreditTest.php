<?php

declare(strict_types=1);

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\OffsetSupplierCredit;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Vat\VatBoxes;
use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $this->supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Leverantören AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
});

/** @param  list<array<string, string>>  $rows */
function supplierDocument(User $user, Company $company, Supplier $supplier, string $total, array $rows, string $treatment = 'DOMESTIC', bool $credit = false, string $vat = '0'): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => '2026-03-05',
        'total' => $total,
        'vat' => $vat,
        'credit' => $credit,
        'vat_treatment' => $treatment,
        'rows' => $rows,
    ]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

/** @return array<int, int> */
function linesOf(Voucher $voucher): array
{
    $lines = [];

    foreach ($voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

it('books a software subscription from outside the EU with VAT the buyer accounts for', function () {
    $invoice = supplierDocument($this->owner, $this->company, $this->supplier, '1000', [['amount' => '1000', 'vat_rate' => '2500', 'description' => 'Kassasystem, månadsabonnemang']], 'NON_EU_SERVICES', vat: '999');

    expect($invoice->vat_ore->ore)->toBe(0)
        ->and(linesOf($invoice->voucher))->toBe([2440 => -100_000, 2614 => -25_000, 2645 => 25_000, 4531 => 100_000])
        ->and(Account::query()->where('number', 4531)->value('name'))->toBe('Inköp av tjänster från ett land utanför EU, 25 % moms')
        ->and(VatBoxes::boxFor(4531))->toBe(22)
        ->and(VatBoxes::boxFor(2614))->toBe(30)
        ->and(VatBoxes::boxFor(2645))->toBe(48);
});

it('books goods from another EU country at 12 and 25 percent on the box 20 accounts', function () {
    $invoice = supplierDocument($this->owner, $this->company, $this->supplier, '1500', [
        ['amount' => '1000', 'vat_rate' => '1200', 'description' => 'Ost'],
        ['amount' => '500', 'vat_rate' => '2500', 'description' => 'Vin'],
    ], 'EU_GOODS');

    expect(linesOf($invoice->voucher))->toBe([2440 => -150_000, 2614 => -12_500, 2624 => -12_000, 2645 => 24_500, 4515 => 50_000, 4516 => 100_000]);
});

it('books Swedish reverse charge on 4425 with input VAT on 2647', function () {
    $invoice = supplierDocument($this->owner, $this->company, $this->supplier, '20000', [['amount' => '20000', 'vat_rate' => '2500', 'description' => 'Ombyggnad kök']], 'SE_REVERSE_SERVICES');

    expect(linesOf($invoice->voucher))->toBe([2440 => -2_000_000, 2614 => -500_000, 2647 => 500_000, 4425 => 2_000_000])
        ->and(VatBoxes::boxFor(4425))->toBe(24);
});

it('reverses a credit note, offsets it against an invoice and pays the rest by file', function () {
    $invoice = supplierDocument($this->owner, $this->company, $this->supplier, '1250', [['amount' => '1000']], vat: '250');
    $credit = supplierDocument($this->owner, $this->company, $this->supplier, '250', [['amount' => '200']], credit: true, vat: '50');

    expect($credit->title())->toBe("Kreditnota {$credit->number}")
        ->and($credit->total_ore->ore)->toBe(-25_000)
        ->and(linesOf($credit->voucher))->toBe([2440 => 25_000, 2641 => -5_000, 4010 => -20_000]);

    $amount = app(OffsetSupplierCredit::class)($this->owner, $credit, $invoice, CarbonImmutable::parse('2026-03-12'));
    $voucher = $invoice->fresh()->payments()->sole()->voucher;

    expect($amount)->toBe(25_000)
        ->and($credit->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($invoice->fresh()->remaining()->ore)->toBe(100_000)
        ->and($voucher->reference())->toStartWith('E')
        ->and(linesOf($voucher))->toBe([2440 => 0]);

    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'SEB', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => 'ABCDEFGHIJ']);
    $batch = app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12'));
    expect($batch->total_ore->ore)->toBe(100_000);
});

it('books money back on a credit note and refuses it in a payment file', function () {
    $credit = supplierDocument($this->owner, $this->company, $this->supplier, '125', [['amount' => '100']], credit: true, vat: '25');

    expect(fn () => app(PaySupplierInvoice::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-12'), Money::parse('125')))
        ->toThrow(ValidationException::class, 'större än noll');

    $payment = app(PaySupplierInvoice::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-12'), Money::parse('-125'));
    expect(linesOf($payment->voucher))->toBe([1930 => 12_500, 2440 => -12_500])
        ->and($credit->fresh()->status)->toBe(SupplierInvoiceStatus::Paid);

    $another = supplierDocument($this->owner, $this->company, $this->supplier, '50', [['amount' => '40']], credit: true, vat: '10');
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'NORDEA', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => 'A1']);
    expect(fn () => app(CreatePaymentBatch::class)($this->owner, $this->company, [$another->id], CarbonImmutable::parse('2026-03-12')))
        ->toThrow(ValidationException::class);
});

it('books costs and buyer VAT at payment under kontantmetoden', function () {
    $this->company->forceFill(['accounting_method' => 'KONTANTMETODEN'])->save();
    $invoice = supplierDocument($this->owner, $this->company, $this->supplier, '1000', [['amount' => '1000', 'vat_rate' => '2500']], 'EU_SERVICES');

    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-12'), Money::parse('1000'));

    expect($invoice->voucher_id)->toBeNull()
        ->and(linesOf($payment->voucher))->toBe([1930 => -100_000, 2614 => -25_000, 2645 => 25_000, 4535 => 100_000]);
});
