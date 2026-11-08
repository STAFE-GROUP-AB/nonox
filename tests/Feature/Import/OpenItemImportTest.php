<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Payables\PaySupplierInvoice;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Migration\Import\Actions\ConfirmRegisterImport;
use App\Migration\Import\RegisterKind;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\RegisterImport;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, S5: open reskontra. The invoices explain the opening balance on
 * 1510 and 2440; no voucher is booked.
 */
const OPEN_CUSTOMER_MAPPING = ['party_ref' => 'Kund', 'invoice_number' => 'Fakturanr', 'invoice_date' => 'Fakturadatum', 'due_date' => 'Forfallodatum', 'total' => 'Belopp', 'remaining' => 'Obetalt', 'vat' => 'Moms', 'ocr' => 'OCR'];
const OPEN_SUPPLIER_MAPPING = ['party_ref' => 'Leverantör', 'invoice_number' => 'Fakturanr', 'invoice_date' => 'Fakturadatum', 'due_date' => 'Forfallodatum', 'total' => 'Belopp', 'remaining' => 'Obetalt', 'vat' => 'Moms'];

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    // The books as the SIE import leaves them: 13 500,00 owed by customers, 5 000,00 owed to suppliers.
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1510 => 1_350_000, 3001 => -1_350_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-02', [5410 => 500_000, 2440 => -500_000]);
    RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    RegisterImports::run($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::fixture('leverantorer-spiris-lik.csv'), 'spiris');
});

function openItemArgs(RegisterKind $kind = RegisterKind::OpenCustomerInvoices, string $fixture = 'oppna-kundfakturor.csv', array $mapping = OPEN_CUSTOMER_MAPPING): array
{
    return [$kind, RegisterImports::fixture($fixture), 'nonox', ['mapping' => $mapping]];
}

it('creates the open customer invoices without a voucher when the sum equals 1510', function () {
    $vouchersBefore = CurrentCompany::run($this->company, fn () => Voucher::query()->count());

    $import = RegisterImports::run($this->owner, $this->company, ...openItemArgs());
    $invoices = CurrentCompany::run($this->company, fn () => Invoice::query()->orderBy('number')->get());

    expect($import->status)->toBe(RegisterImport::COMPLETED)
        ->and($import->report['reconciliation'])->toMatchArray(['file_ore' => 1_350_000, 'ledger_ore' => 1_350_000, 'equal' => true])
        ->and($invoices)->toHaveCount(2)
        ->and($invoices->pluck('external_ref')->all())->toBe(['5001', '5002'])
        ->and($invoices->pluck('import_source')->unique()->all())->toBe(['IMPORT_OPEN'])
        ->and($invoices->pluck('status')->unique()->all())->toBe([InvoiceStatus::Issued])
        ->and($invoices[1]->total_ore->ore)->toBe(200_000)
        ->and($invoices[1]->remaining()->ore)->toBe(100_000)
        ->and($invoices[1]->vat_ore->ore)->toBe(40_000)
        ->and($invoices->pluck('voucher_id')->filter()->all())->toBe([])
        ->and(CurrentCompany::run($this->company, fn () => Voucher::query()->count()))->toBe($vouchersBefore);
});

it('leaves the ledger and its hash chain exactly as it was', function () {
    $chain = fn () => CurrentCompany::run($this->company, fn () => Voucher::query()->orderBy('posted_at')->orderBy('id')->get(['id', 'hash', 'previous_hash'])->toArray());
    $rows = fn () => CurrentCompany::run($this->company, fn () => VoucherRow::query()->count());
    [$chainBefore, $rowsBefore] = [$chain(), $rows()];

    RegisterImports::run($this->owner, $this->company, ...openItemArgs());
    RegisterImports::run($this->owner, $this->company, ...openItemArgs(RegisterKind::OpenSupplierInvoices, 'oppna-leverantorsfakturor.csv', OPEN_SUPPLIER_MAPPING));

    expect($chain())->toBe($chainBefore)->and($rows())->toBe($rowsBefore);
});

it('blocks the confirmation when the sum differs from the ledger and names the difference', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-03', [1510 => 10_000, 3001 => -10_000]);

    $import = RegisterImports::run($this->owner, $this->company, ...openItemArgs());

    expect($import->status)->toBe(RegisterImport::ANALYSED)
        ->and($import->canBeConfirmed())->toBeFalse()
        ->and($import->report['reconciliation']['difference_ore'])->toBe(-10_000)
        ->and(implode(' ', $import->blockers()))->toContain('1510')->toContain('-100,00 kr')
        ->and(fn () => app(ConfirmRegisterImport::class)($this->owner, $import))->toThrow(AuthorizationException::class)
        ->and(CurrentCompany::run($this->company, fn () => Invoice::query()->count()))->toBe(0);
});

it('compares the sums again when confirming and writes nothing if the books changed', function () {
    $import = RegisterImports::upload($this->owner, $this->company, ...array_slice(openItemArgs(), 0, 3));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => OPEN_CUSTOMER_MAPPING]);
    expect($import->canBeConfirmed())->toBeTrue();

    Bookkeeping::postRows($this->owner, $this->company, '2026-03-04', [1510 => 5_000, 3001 => -5_000]);
    app(ConfirmRegisterImport::class)($this->owner, $import);
    $import = CurrentCompany::run($this->company, fn () => RegisterImport::query()->findOrFail($import->id));

    expect($import->status)->toBe(RegisterImport::FAILED)
        ->and($import->error)->toContain('stämmer inte längre')
        ->and(CurrentCompany::run($this->company, fn () => Invoice::query()->count()))->toBe(0);
});

it('is offered only to companies on the invoice method', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();

    $import = RegisterImports::run($this->owner, $this->company, ...openItemArgs());

    expect($import->canBeConfirmed())->toBeFalse()
        ->and(implode(' ', $import->blockers()))->toContain('fakturametoden');
});

it('needs the books to be read in first', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::run($company, fn () => FiscalYear::query()->delete());

    $import = RegisterImports::run($owner, $company, ...openItemArgs());

    expect($import->canBeConfirmed())->toBeFalse()->and(implode(' ', $import->blockers()))->toContain('SIE');
});

it('rejects rows in other currencies, unknown customers and unreadable amounts', function () {
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR;Valuta\n1001;1;2026-01-01;2026-02-01;100,00;100,00;20,00;;EUR\n9999;2;2026-01-01;2026-02-01;100,00;100,00;20,00;;SEK\n1001;3;2026-01-01;2026-02-01;100,00;150,00;20,00;;SEK\n1001;4;2026-01-01;2026-02-01;ett;100,00;20,00;;SEK\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => [...OPEN_CUSTOMER_MAPPING, 'currency' => 'Valuta']]);

    expect($import->report['counts'])->toMatchArray(['read' => 4, 'error' => 4])
        ->and(collect($import->report['rows'])->pluck('errors')->flatten()->implode(' '))->toContain('EUR')->toContain('finns inte i kundregistret');
});

it('is idempotent: a second run creates nothing and the sums still add up', function () {
    RegisterImports::run($this->owner, $this->company, ...openItemArgs());
    $csv = file_get_contents(base_path('tests/Fixtures/import/oppna-kundfakturor.csv'))."\n";
    $again = RegisterImports::run($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv, 'igen.csv'), 'nonox', ['mapping' => OPEN_CUSTOMER_MAPPING]);

    expect($again->status)->toBe(RegisterImport::COMPLETED)
        ->and($again->result)->toMatchArray(['created' => 0, 'skipped' => 2])
        ->and(CurrentCompany::run($this->company, fn () => Invoice::query()->count()))->toBe(2);
});

it('does not let an imported invoice be changed or deleted', function () {
    RegisterImports::run($this->owner, $this->company, ...openItemArgs());
    $invoice = CurrentCompany::run($this->company, fn () => Invoice::query()->firstOrFail());

    expect(fn () => CurrentCompany::run($this->company, fn () => $invoice->update(['due_date' => '2027-01-01'])))->toThrow(LogicException::class)
        ->and(fn () => CurrentCompany::run($this->company, fn () => $invoice->delete()))->toThrow(LogicException::class);
});

it('books a payment of an imported customer invoice against 1510', function () {
    RegisterImports::run($this->owner, $this->company, ...openItemArgs());
    $invoice = CurrentCompany::run($this->company, fn () => Invoice::query()->orderBy('number')->firstOrFail());

    $payment = CurrentCompany::run($this->company, fn () => app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-07-01'), Money::ofOre(1_250_000)));
    $rows = CurrentCompany::run($this->company, fn () => VoucherRow::query()->where('voucher_id', $payment->voucher_id)->with('account')->get());

    expect($rows->firstWhere('account.number', 1930)->debit_ore->ore)->toBe(1_250_000)
        ->and($rows->firstWhere('account.number', 1510)->credit_ore->ore)->toBe(1_250_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('imports open supplier invoices against 2440 and books their payment against 2440', function () {
    $import = RegisterImports::run($this->owner, $this->company, ...openItemArgs(RegisterKind::OpenSupplierInvoices, 'oppna-leverantorsfakturor.csv', OPEN_SUPPLIER_MAPPING));
    $invoice = CurrentCompany::run($this->company, fn () => SupplierInvoice::query()->sole());

    expect($import->status)->toBe(RegisterImport::COMPLETED)
        ->and($invoice->invoice_number)->toBe('L-77')
        ->and($invoice->remaining()->ore)->toBe(500_000)
        ->and($invoice->voucher_id)->toBeNull();

    $payment = CurrentCompany::run($this->company, fn () => app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-07-01'), Money::ofOre(500_000)));
    $rows = CurrentCompany::run($this->company, fn () => VoucherRow::query()->where('voucher_id', $payment->voucher_id)->with('account')->get());

    expect($rows->firstWhere('account.number', 2440)->debit_ore->ore)->toBe(500_000)
        ->and($rows->firstWhere('account.number', 1930)->credit_ore->ore)->toBe(500_000);
});

it('refuses a supplier list whose sum differs from 2440', function () {
    $csv = "Leverantör;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms\n2001;L-1;2026-09-03;2026-10-03;4 000,00;4 000,00;800,00\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenSupplierInvoices, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => OPEN_SUPPLIER_MAPPING]);

    expect($import->canBeConfirmed())->toBeFalse()
        ->and($import->report['reconciliation']['difference_ore'])->toBe(-100_000)
        ->and(CurrentCompany::run($this->company, fn () => Supplier::query()->count()))->toBe(2);
});
