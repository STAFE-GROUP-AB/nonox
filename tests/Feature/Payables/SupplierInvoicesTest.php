<?php

declare(strict_types=1);

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\DeleteSupplierInvoiceDraft;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SetSupplierApprovalRule;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Payables\SupplierInvoiceActions;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Livewire\Payables\SupplierInvoiceList;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Files\InlineFile;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
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
    $this->bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($this->owner);
    CostCenter::create(['code' => '10', 'name' => 'Restaurang Hamnen']);

    $this->supplier = app(SaveSupplier::class)($this->owner, $this->company, [
        'name' => 'Grossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30, 'default_account' => 4010,
    ]);
});

/** Food at 12 % and drinks at 25 % from the wholesaler: 1 000 + 400 kr excl. VAT. */
function groceryInvoice(User $user, Company $company, Supplier $supplier, ?UploadedFile $file = null): SupplierInvoice
{
    return app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'F-778',
        'ocr' => '4455663',
        'invoice_date' => '2026-03-05',
        'total' => '1 620,00',
        'vat' => '220,00',
        'rows' => [
            ['account' => '', 'amount' => '1000', 'description' => 'Livsmedel', 'cost_center' => '10'],
            ['account' => '4010', 'amount' => '400,00', 'description' => 'Drycker'],
        ],
    ], file: $file);
}

/** @return array<int, int> */
function supplierVoucherLines(Voucher $voucher): array
{
    $lines = [];

    foreach ($voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

it('checks bankgiro numbers and numbers suppliers', function () {
    expect($this->supplier->number)->toBe(1)
        ->and($this->supplier->bankgiro)->toBe('5050-1055')
        ->and($this->supplier->paymentDetails())->toBe('Bankgiro 5050-1055')
        ->and(fn () => app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Fel AB', 'bankgiro' => '5050-1056', 'payment_terms_days' => 30]))
        ->toThrow(ValidationException::class, 'Bankgironumret stämmer inte');
});

it('keeps a draft only when the rows and VAT add up to the total', function () {
    $draft = groceryInvoice($this->owner, $this->company, $this->supplier);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->due_date->toDateString())->toBe('2026-04-04')
        ->and($draft->rows)->toHaveCount(2)
        ->and($draft->rows[0]->account->number)->toBe(4010);

    expect(fn () => app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $this->supplier->id, 'invoice_date' => '2026-03-05', 'total' => '1000', 'vat' => '200',
        'rows' => [['account' => '4010', 'amount' => '700']],
    ]))->toThrow(ValidationException::class, 'Skillnaden är '.Money::ofOre(10_000)->format());
});

it('registers and books the invoice in series D with the invoice as underlag', function () {
    $pdf = UploadedFile::fake()->createWithContent('faktura-778.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $invoice = app(RegisterSupplierInvoice::class)($this->owner, groceryInvoice($this->owner, $this->company, $this->supplier, $pdf));

    expect($invoice->number)->toBe(1)
        ->and($invoice->status)->toBe(SupplierInvoiceStatus::Registered)
        ->and($invoice->supplier_snapshot['bankgiro'])->toBe('5050-1055')
        ->and($invoice->voucher->reference())->toBe('D1')
        ->and(supplierVoucherLines($invoice->voucher))->toBe([2440 => -162_000, 2641 => 22_000, 4010 => 140_000])
        ->and($invoice->voucher->rows->firstWhere('description', 'Livsmedel')->cost_center_id)->toBe(CostCenter::query()->value('id'))
        ->and($invoice->voucher->attachments()->sole()->original_name)->toBe('faktura-778.pdf');

    Storage::disk('local')->assertExists($invoice->file_path);
});

it('never changes a registered invoice', function () {
    $invoice = app(RegisterSupplierInvoice::class)($this->owner, groceryInvoice($this->owner, $this->company, $this->supplier));

    expect(fn () => $invoice->forceFill(['total_ore' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_invoices')->where('id', $invoice->id)->update(['total_ore' => 1])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('supplier_invoice_rows')->where('supplier_invoice_id', $invoice->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => app(SaveSupplierInvoice::class)($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'invoice_date' => '2026-03-05', 'total' => '1', 'rows' => [['account' => '4010', 'amount' => '1']]], $invoice))
        ->toThrow(AuthorizationException::class);
});

it('lets someone else approve, and only an approved invoice is paid', function () {
    $invoice = app(RegisterSupplierInvoice::class)($this->bookkeeper, groceryInvoice($this->bookkeeper, $this->company, $this->supplier));

    expect(fn () => app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('100')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveSupplierInvoice::class)($this->bookkeeper, $invoice))->toThrow(ValidationException::class, 'kan därför inte attestera');

    $approved = app(ApproveSupplierInvoice::class)($this->owner, $invoice);
    expect($approved->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($approved->approved_by)->toBe($this->owner->id);

    $first = app(PaySupplierInvoice::class)($this->owner, $approved, CarbonImmutable::parse('2026-03-20'), Money::parse('620'));
    expect($first->voucher->reference())->toBe('E1')
        ->and(supplierVoucherLines($first->voucher))->toBe([1930 => -62_000, 2440 => 62_000])
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved);

    app(PaySupplierInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-25'), Money::parse('1000'));
    expect($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and(fn () => app(PaySupplierInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-26'), Money::parse('1')))->toThrow(AuthorizationException::class);
});

it('lets a one-person company approve its own invoices once the rule is turned off', function () {
    $invoice = app(RegisterSupplierInvoice::class)($this->owner, groceryInvoice($this->owner, $this->company, $this->supplier));
    expect(fn () => app(ApproveSupplierInvoice::class)($this->owner, $invoice))->toThrow(ValidationException::class);

    app(SetSupplierApprovalRule::class)($this->owner, $this->company, false);

    expect(app(ApproveSupplierInvoice::class)($this->owner, $invoice->fresh())->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and(fn () => app(SetSupplierApprovalRule::class)($this->bookkeeper, $this->company, true))->toThrow(AuthorizationException::class);
});

it('books costs and VAT on payment under kontantmetoden', function () {
    $this->company->forceFill(['accounting_method' => 'KONTANTMETODEN', 'separate_supplier_approval' => false])->save();
    $pdf = UploadedFile::fake()->createWithContent('faktura.pdf', "%PDF-1.4\n%%EOF");

    $invoice = app(RegisterSupplierInvoice::class)($this->owner, groceryInvoice($this->owner, $this->company, $this->supplier, $pdf));
    expect($invoice->voucher_id)->toBeNull();

    app(ApproveSupplierInvoice::class)($this->owner, $invoice);
    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-20'), Money::parse('810'));

    expect(supplierVoucherLines($payment->voucher))->toBe([1930 => -81_000, 2641 => 11_000, 4010 => 70_000])
        ->and($payment->voucher->rows->firstWhere('description', 'Livsmedel')->cost_center_id)->not->toBeNull()
        ->and($payment->voucher->attachments()->count())->toBe(1);
});

it('lets an approver approve but not register, and removes drafts with their file', function () {
    $approver = Bookkeeping::member($this->company, 'approver');
    $pdf = UploadedFile::fake()->createWithContent('faktura.pdf', "%PDF-1.4\n%%EOF");
    $draft = groceryInvoice($this->owner, $this->company, $this->supplier, $pdf);

    expect(fn () => app(RegisterSupplierInvoice::class)($approver, $draft))->toThrow(AuthorizationException::class);

    app(DeleteSupplierInvoiceDraft::class)($this->owner, $draft);
    expect(SupplierInvoice::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($draft->file_path);

    $invoice = app(RegisterSupplierInvoice::class)($this->owner, groceryInvoice($this->owner, $this->company, $this->supplier));
    expect(app(ApproveSupplierInvoice::class)($approver, $invoice)->status)->toBe(SupplierInvoiceStatus::Approved);
});

it('refuses an invoice file that is not a PDF or an image', function () {
    $fake = UploadedFile::fake()->createWithContent('faktura.pdf', '<?php echo 1;');

    expect(fn () => groceryInvoice($this->owner, $this->company, $this->supplier, $fake))->toThrow(ValidationException::class, 'PDF eller en bild');
});

describe('in the browser', function () {
    it('registers an invoice with its file, then approves and pays it, with toasts', function () {
        $pdf = UploadedFile::fake()->createWithContent('grossist.pdf', "%PDF-1.4\n%%EOF");

        Livewire::test(SupplierInvoiceEditor::class)
            ->set('supplierId', (string) $this->supplier->id)
            ->set('invoiceNumber', 'F-900')
            ->set('invoiceDate', '2026-03-05')
            ->set('total', '1 250,00')
            ->set('vat', '250,00')
            ->set('rows', [['account' => '', 'amount' => '1000', 'description' => 'Varor', 'cost_center' => '10', 'project' => '']])
            ->set('file', $pdf)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $draft = SupplierInvoice::query()->sole();
        expect(session('toasts')[0]['message'])->toBe('Utkastet är sparat.')
            ->and($draft->file_name)->toBe('grossist.pdf');

        $this->get(route('payables.invoices.show', $draft))->assertRedirect(route('payables.invoices.edit', $draft));
        $this->get(route('payables.invoices.edit', $draft))->assertOk()->assertSee('Registrera fakturan');
        $this->get(route('payables.invoices.file', $draft))->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Security-Policy', InlineFile::CONTENT_SECURITY_POLICY)->assertHeader('X-Content-Type-Options', 'nosniff');

        Livewire::test(SupplierInvoiceEditor::class, ['invoice' => $draft])
            ->call('register')
            ->assertRedirect(route('payables.invoices.show', $draft));
        expect(session('toasts')[0]['message'])->toBe('Leverantörsfaktura 1 är registrerad och bokförd. Den väntar nu på attest.');

        $this->get(route('payables.invoices.show', $draft))->assertOk()->assertSee('Du registrerade fakturan');

        $this->actingAs($this->bookkeeper);
        Livewire::test(SupplierInvoiceActions::class, ['invoice' => $draft->fresh()])->assertDontSee('Attestera fakturan')->assertDontSee('Du registrerade fakturan');

        $this->actingAs($this->owner);
        $this->company->forceFill(['separate_supplier_approval' => false])->save();
        Livewire::test(SupplierInvoiceActions::class, ['invoice' => $draft->fresh()])
            ->call('approve')
            ->assertDispatched('toast', message: 'Leverantörsfaktura 1 är attesterad och kan betalas.')
            ->assertSet('amount', Money::ofOre(125_000)->format(withCurrency: false))
            ->set('paidOn', '2026-03-28')
            ->call('pay')
            ->assertDispatched('toast', message: 'Leverantörsfaktura 1 är betald.');

        expect($draft->fresh()->status)->toBe(SupplierInvoiceStatus::Paid);
    });

    it('lists invoices by status, shows suppliers, and hides other companies\' invoices', function () {
        $invoice = app(RegisterSupplierInvoice::class)($this->bookkeeper, groceryInvoice($this->bookkeeper, $this->company, $this->supplier));

        Livewire::test(SupplierInvoiceList::class)->set('filter', 'attest')->assertSee('Grossisten AB')->set('filter', 'betalda')->assertDontSee('Grossisten AB');
        $this->get(route('payables.invoices.index'))->assertOk()->assertSee('Att betala till leverantörer')->assertSee('1 faktura väntar på attest.');
        $this->get(route('payables.suppliers'))->assertOk()->assertSeeInOrder(['Grossisten AB', 'Bankgiro 5050-1055', 'Obetalt', Money::ofOre(162_000)->format()]);

        [$stranger] = Bookkeeping::company();
        $this->actingAs($stranger);
        $this->get(route('payables.invoices.show', $invoice))->assertNotFound();
        $this->get(route('payables.invoices.file', $invoice))->assertNotFound();
    });

    it('turns the separate approval rule off for a one-person company', function () {
        Livewire::test(SupplierInvoiceList::class)
            ->call('setSeparateApproval', false)
            ->assertDispatched('toast', message: 'Nu får den som registrerade fakturan också attestera den.');

        expect($this->company->fresh()->separate_supplier_approval)->toBeFalse();
    });

    it('lets an auditor read but not register', function () {
        $this->actingAs(Bookkeeping::member($this->company, 'auditor'));

        $this->get(route('payables.invoices.index'))->assertOk()->assertDontSee('Ny leverantörsfaktura');
        $this->get(route('payables.invoices.create'))->assertForbidden();
    });
});

it('refuses an OCR reference whose check digit is wrong', function () {
    $invoice = fn (string $ocr) => app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $this->supplier->id, 'invoice_date' => '2026-03-05', 'total' => '100', 'vat' => '0', 'ocr' => $ocr,
        'rows' => [['account' => '4010', 'amount' => '100']],
    ]);

    expect(fn () => $invoice('4455667'))->toThrow(ValidationException::class, 'OCR-numret stämmer inte')
        ->and($invoice('4455663')->ocr)->toBe('4455663')
        ->and($invoice('')->ocr)->toBeNull();
});
