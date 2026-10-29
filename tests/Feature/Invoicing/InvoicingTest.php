<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SendInvoice;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Ocr;
use App\Livewire\Invoicing\Articles;
use App\Livewire\Invoicing\Customers;
use App\Livewire\Invoicing\InvoiceActions;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\InvoiceList;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['bankgiro' => '5050-1055', 'vat_number' => 'SE556334368901', 'f_skatt' => true])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'ekonomi@kontorshuset.test',
        'address_line1' => 'Storgatan 1', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 20,
    ]);
});

/** A catering order: food at 12 % and drinks at 25 %, with öre to round away. */
function cateringDraft(User $user, Company $company, Customer $customer): Invoice
{
    return app(SaveDraftInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [
            ['description' => 'Lunchbuffé', 'quantity' => '12', 'price' => '119,00', 'vat_rate' => 1200],
            ['description' => 'Lättöl', 'quantity' => '12', 'price' => '24,90', 'vat_rate' => 2500, 'discount' => '10'],
        ],
    ]);
}

/** @return array<int, int> */
function voucherLines(Voucher $voucher): array
{
    return $voucher->load('rows.account')->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])
        ->sortKeys()
        ->all();
}

it('calculates rows, VAT per rate and rounding to whole kronor', function () {
    expect(InvoiceCalculator::rowNet(Money::parse('24,90'), '12', 1000)->ore)->toBe(26_892)
        ->and(InvoiceCalculator::totals([['net' => 142_800, 'vat_rate' => 1200], ['net' => 26_892, 'vat_rate' => 2500]]))->toBe([
            'net' => 169_692,
            'vat' => 23_859,
            'rounding' => 49,
            'total' => 193_600,
            'vat_by_rate' => [2500 => ['base' => 26_892, 'vat' => 6_723], 1200 => ['base' => 142_800, 'vat' => 17_136]],
        ])
        ->and(InvoiceCalculator::quantity('1,5'))->toBe('1.5')
        ->and(InvoiceCalculator::quantity('tre'))->toBeNull()
        ->and(InvoiceCalculator::discount('12,5'))->toBe(1250)
        ->and(InvoiceCalculator::discount('101'))->toBeNull();
});

it('makes OCR numbers with a check digit', function () {
    expect(Ocr::forInvoiceNumber(1))->toBe('18')
        ->and(Ocr::forInvoiceNumber(1234))->toBe('12344')
        ->and(Ocr::isValid('12344'))->toBeTrue()
        ->and(Ocr::isValid('12345'))->toBeFalse();
});

it('numbers customers and keeps personal numbers encrypted', function () {
    $private = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'personal_number' => '19811218-9876', 'payment_terms_days' => 10]);

    expect($this->customer->number)->toBe(1)
        ->and($this->customer->org_number)->toBe('556334-3689')
        ->and($private->number)->toBe(2)
        ->and($private->fresh()->personal_number)->toBe('19811218-9876')
        ->and(DB::table('customers')->where('id', $private->id)->value('personal_number'))->not->toContain('9876')
        ->and(fn () => app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Fel', 'org_number' => '5563343680', 'payment_terms_days' => 30]))
        ->toThrow(ValidationException::class, 'Organisationsnumret stämmer inte');
});

it('books articles on the revenue account for their VAT rate', function () {
    $food = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'LUNCH', 'name' => 'Lunch', 'unit' => 'st', 'price' => '119', 'vat_rate' => 1200]);

    expect($food->load('account')->account->number)->toBe(3002)
        ->and($food->price_ore->ore)->toBe(11_900);
});

it('keeps a draft with its totals and the customer\'s terms', function () {
    $draft = cateringDraft($this->owner, $this->company, $this->customer);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->number)->toBeNull()
        ->and($draft->due_date->toDateString())->toBe('2026-03-22')
        ->and($draft->total_ore->ore)->toBe(193_600)
        ->and($draft->rounding_ore->ore)->toBe(49)
        ->and(Voucher::count())->toBe(0);
});

it('issues an invoice with number, OCR and a voucher in series B', function () {
    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));

    expect($invoice->number)->toBe(1)
        ->and($invoice->ocr)->toBe('18')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->customer_snapshot['name'])->toBe('Kontorshuset AB')
        ->and($invoice->voucher->reference())->toBe('B1')
        ->and(voucherLines($invoice->voucher))->toBe([1510 => 193_600, 2611 => -6_723, 2621 => -17_136, 3001 => -26_892, 3002 => -142_800, 3740 => -49]);
});

it('never changes an issued invoice', function () {
    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));

    expect(fn () => $invoice->update(['notes' => 'Ändrad']))->toThrow(LogicException::class)
        ->and(fn () => $invoice->delete())->toThrow(LogicException::class)
        ->and(fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-02', 'rows' => [['description' => 'X', 'quantity' => '1', 'price' => '1', 'vat_rate' => 2500]]], $invoice))
        ->toThrow(AuthorizationException::class);

    foreach (['update invoices set total_ore = 1 where id = ?', 'delete from invoice_rows where invoice_id = ?'] as $sql) {
        $refused = false;

        try {
            DB::transaction(fn () => DB::statement($sql, [$invoice->id]));
        } catch (QueryException $exception) {
            $refused = str_contains($exception->getMessage(), 'cannot be');
        }

        expect($refused)->toBeTrue();
    }
});

it('registers part and full payments in series C', function () {
    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));

    $first = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::parse('1000'));
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(voucherLines($first->voucher))->toBe([1510 => -100_000, 1930 => 100_000]);

    app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-15'), Money::parse('936'));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->remaining()->ore)->toBe(0)
        ->and(fn () => app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-16'), Money::parse('1')))
        ->toThrow(AuthorizationException::class)
        ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([]);
});

it('refuses a payment larger than what remains', function () {
    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));

    expect(fn () => app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::parse('2000')))
        ->toThrow(ValidationException::class, 'större än det som återstår');
});

it('books revenue only when paid under kontantmetoden', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();

    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));
    expect($invoice->voucher_id)->toBeNull();

    $half = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::parse('968'));
    $lines = voucherLines($half->voucher);

    expect($lines[1930])->toBe(96_800)
        ->and(array_sum($lines))->toBe(0)
        // Half of each line, rounded; the öre left over goes to the largest line.
        ->and($lines[3002])->toBe(-71_399)
        ->and($lines[2611])->toBe(-3_362)
        ->and($lines)->not->toHaveKey(1510);
});

it('credits an unpaid invoice with a credit invoice that reverses it', function () {
    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));

    $draft = app(CreditInvoice::class)($this->owner, $invoice);
    expect($draft->isCredit())->toBeTrue()
        ->and($draft->total_ore->ore)->toBe(-193_600)
        ->and($draft->rows->pluck('quantity')->all())->toBe(['-12.000', '-12.000']);

    $credit = app(IssueInvoice::class)($this->owner, $draft);

    expect($credit->number)->toBe(2)
        ->and($credit->title())->toBe('Kreditfaktura 2')
        ->and($credit->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Credited)
        ->and(voucherLines($credit->voucher))->toBe([1510 => -193_600, 2611 => 6_723, 2621 => 17_136, 3001 => 26_892, 3002 => 142_800, 3740 => 49])
        ->and(fn () => app(CreditInvoice::class)($this->owner, $invoice->fresh()))->toThrow(AuthorizationException::class);
});

it('sends the invoice as a PDF', function () {
    Mail::fake();
    $invoice = app(IssueInvoice::class)($this->owner, cateringDraft($this->owner, $this->company, $this->customer));

    app(SendInvoice::class)($this->owner, $invoice, 'ekonomi@kontorshuset.test');

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo('ekonomi@kontorshuset.test')
        && $mail->attachments()[0]->as === 'Faktura-1.pdf');

    expect($invoice->fresh()->sent_to)->toBe('ekonomi@kontorshuset.test')
        ->and(InvoicePdf::render($invoice))->toStartWith('%PDF')
        ->and((new InvoiceMail($invoice, $this->company))->render())->toContain('OCR-nummer 18');
});

describe('in the browser', function () {
    it('writes, issues, sends and gets paid for an invoice with toasts', function () {
        Mail::fake();
        app(SaveArticle::class)($this->owner, $this->company, ['number' => 'LUNCH', 'name' => 'Lunchbuffé', 'unit' => 'st', 'price' => '119', 'vat_rate' => 1200]);

        Livewire::test(InvoiceEditor::class)
            ->set('customerId', (string) $this->customer->id)
            ->set('rows.0.article', 'LUNCH')
            ->assertSet('rows.0.description', 'Lunchbuffé')
            ->assertSet('rows.0.vat_rate', '1200')
            ->set('rows.0.quantity', '10')
            ->assertSee("1\u{00A0}333,00")
            ->call('issue')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invoice = Invoice::query()->sole();
        expect(session('toasts')[0]['message'])->toBe('Faktura 1 är skapad och bokförd. Skicka den till kunden här.');

        $this->get(route('invoicing.invoices.show', $invoice))->assertOk()->assertSee('Faktura 1');
        $this->get(route('invoicing.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Livewire::test(InvoiceActions::class, ['invoice' => $invoice])
            ->assertSet('email', 'ekonomi@kontorshuset.test')
            ->call('send')
            ->assertDispatched('toast', message: 'Faktura 1 är skickad till ekonomi@kontorshuset.test.')
            ->set('amount', '1 333')
            ->call('registerPayment')
            ->assertDispatched('toast', message: 'Faktura 1 är betald.');

        Livewire::test(InvoiceList::class)->set('filter', 'betalda')->assertSee('Kontorshuset AB');
    });

    it('keeps a draft and explains what is missing', function () {
        Livewire::test(InvoiceEditor::class)
            ->set('rows.0.description', 'Konsulttimme')
            ->set('rows.0.price', '900')
            ->call('save')
            ->assertHasErrors('customer_id')
            ->set('customerId', (string) $this->customer->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(Invoice::query()->sole()->isDraft())->toBeTrue();
    });

    it('adds customers and articles with toasts', function () {
        Livewire::test(Customers::class)
            ->call('create')
            ->set('form.name', 'Bageriet i Lund')
            ->set('form.email', 'info@bageriet.test')
            ->call('save')
            ->assertDispatched('toast', message: 'Kund 2 Bageriet i Lund är tillagd.')
            ->set('search', 'bageri')
            ->assertSee('Bageriet i Lund')
            ->assertDontSee('Kontorshuset AB');

        Livewire::test(Articles::class)
            ->call('create')
            ->set('form.number', 'VIN')
            ->set('form.name', 'Glas vin')
            ->set('form.price', '79')
            ->call('save')
            ->assertDispatched('toast', message: 'Artikel VIN Glas vin är tillagd.')
            ->assertSee('Glas vin');
    });

    it('lets an auditor see invoices but not write them', function () {
        $this->actingAs(Bookkeeping::member($this->company, 'auditor'));

        $this->get(route('invoicing.invoices.index'))->assertOk();
        $this->get(route('invoicing.invoices.create'))->assertForbidden();
    });

    it('answers 404 for another company\'s invoice', function () {
        $invoice = cateringDraft($this->owner, $this->company, $this->customer);

        [$stranger] = Bookkeeping::company();
        $this->actingAs($stranger);

        $this->get(route('invoicing.invoices.pdf', $invoice))->assertNotFound();
    });
});
