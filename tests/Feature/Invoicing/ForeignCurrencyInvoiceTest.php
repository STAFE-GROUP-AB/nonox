<?php

declare(strict_types=1);

use App\Actions\Invoicing\BookIncomingPayments;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\ImportBankFile;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SendReminder;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Vat\VatReturn;
use App\Livewire\Invoicing\InvoiceActions;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Mail\ReminderMail;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Modules\Connect\Webhooks\WebhookEvents;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Customer invoices in another currency (ADR-025): written in that currency,
 * booked in kronor at the rate on the invoice, paid in their own currency
 * with the difference between the kronor booked and the kronor received on
 * 3960 or 7960.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['iban' => 'SE4550000000058398257466', 'bic' => 'ESSESESS'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Exempel Handel AB', 'email' => 'ekonomi@exempelhandel.test', 'payment_terms_days' => 30, 'currency' => 'eur',
    ]);
});

/** A draft in euro at $rate kronor per euro, one row per [price, vat rate]. */
function euroDraft(Customer $customer, string $rate, array $rows = [['1000', 0]], array $extra = []): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'currency' => 'EUR',
        'exchange_rate' => $rate,
        'rows' => array_map(fn (array $row): array => ['description' => 'Konsultarbete', 'quantity' => '1', 'price' => $row[0], 'vat_rate' => $row[1]], $rows),
        ...$extra,
    ]);
}

/** @return array<int, int> signed öre per account, debit positive */
function euroLines(Voucher $voucher): array
{
    $lines = [];

    foreach ($voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

/** The balance of an account over every posted voucher of the company. */
function euroBalance(int $number): int
{
    return (int) VoucherRow::query()->whereHas('account', fn ($query) => $query->where('number', $number))
        ->sum(DB::raw('debit_ore - credit_ore'));
}

it('stores the customer\'s currency in capitals and starts new invoices in it', function () {
    expect($this->customer->currency)->toBe('EUR');

    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-02-27', 'value' => 11.2]])]);

    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->customer->id)
        ->assertSet('currency', 'EUR')
        ->assertSet('exchangeRate', '11,200000')
        ->assertSet('rateDate', '2026-02-27');
});

it('writes the invoice in euro and books it in kronor at Riksbanken\'s rate, without öresavrundning', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-02-27', 'value' => 11.2]])]);

    $draft = euroDraft($this->customer, '', [['100', 2500], ['33,33', 2500]]);

    expect($draft->invoice_currency)->toBe('EUR')
        ->and($draft->exchange_rate_micros)->toBe(11_200_000)
        ->and($draft->exchange_rate_source)->toBe('riksbank')
        ->and($draft->exchange_rate_date?->toDateString())->toBe('2026-02-27')
        ->and([$draft->foreign_net_minor, $draft->foreign_vat_minor, $draft->foreign_total_minor])->toBe([13_333, 3_333, 16_666])
        // Each row in kronor, half up: 100 € = 1 120 kr, 33,33 € = 373,30 kr; VAT on the kronor base.
        ->and($draft->rows->map(fn ($row) => [$row->foreign_net_minor, $row->net_ore->ore])->all())->toBe([[10_000, 112_000], [3_333, 37_330]])
        ->and([$draft->net_ore->ore, $draft->vat_ore->ore, $draft->rounding_ore->ore, $draft->total_ore->ore])->toBe([149_330, 37_333, 0, 186_663]);
});

it('keeps a typed rate as the person\'s own, and asks for one when Riksbanken has none', function () {
    // Riksbanken has euro, but cannot be reached for Norwegian kronor.
    Http::fake(fn ($request) => str_contains($request->url(), 'SEKNOKPMI')
        ? Http::response([], 500)
        : Http::response([['date' => '2026-02-27', 'value' => 11.2]]));

    $typed = euroDraft($this->customer, '11,05');

    expect($typed->exchange_rate_micros)->toBe(11_050_000)
        ->and($typed->exchange_rate_source)->toBe('manual')
        ->and($typed->total_ore->ore)->toBe(1_105_000);

    $same = euroDraft($this->customer, '11,2');
    expect($same->exchange_rate_source)->toBe('riksbank');

    expect(fn () => euroDraft($this->customer, 'elva'))->toThrow(ValidationException::class, 'Skriv kursen');

    expect(fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-03-03', 'currency' => 'NOK',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '100', 'vat_rate' => 0]],
    ]))->toThrow(ValidationException::class, 'Riksbanken har ingen kurs');
});

it('rounds every row to öre half up and keeps the kronor and the euro totals consistent', function () {
    $draft = euroDraft($this->customer, '11,234567', [['33,33', 2500], ['33,33', 2500], ['33,33', 2500]]);

    expect($draft->rows->map(fn ($row) => $row->net_ore->ore)->all())->toBe([37_445, 37_445, 37_445])
        ->and([$draft->net_ore->ore, $draft->vat_ore->ore, $draft->total_ore->ore])->toBe([112_335, 28_084, 140_419])
        ->and([$draft->foreign_net_minor, $draft->foreign_vat_minor, $draft->foreign_total_minor])->toBe([9_999, 2_500, 12_499]);
});

it('issues an invoice in euro on 1510 in kronor, with the currency in the voucher text and the audit log', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11', [['1000', 2500]]));

    expect(euroLines($invoice->voucher))->toBe([1510 => 1_375_000, 2611 => -275_000, 3001 => -1_100_000])
        ->and($invoice->voucher->description)->toContain("1\u{00A0}250,00 EUR")->toContain('11,000000')
        ->and($invoice->foreign_paid_minor)->toBe(0)
        ->and(AuditLog::query()->where('action', 'invoice.issued')->latest('id')->first()->payload)
        ->toMatchArray(['currency' => 'EUR', 'foreign_total_minor' => 125_000, 'exchange_rate_micros' => 11_000_000]);
});

it('reports the VAT of an invoice in euro in kronor', function () {
    app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11', [['1000', 2500]]));

    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $boxes = VatReturn::calculate($year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    expect($boxes[5])->toBe(11_000)
        ->and($boxes[10])->toBe(2_750);
});

it('books an exchange gain on 3960 when more kronor arrive than were booked', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));

    $payment = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('11 500'));
    $invoice->refresh();

    expect(euroLines($payment->voucher))->toBe([1510 => -1_100_000, 1930 => 1_150_000, 3960 => -50_000])
        ->and($payment->foreign_amount_minor)->toBe(100_000)
        ->and($payment->exchange_difference_ore)->toBe(50_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid_ore->ore)->toBe(1_100_000)
        ->and($invoice->foreign_paid_minor)->toBe(100_000)
        ->and(Account::query()->where('number', 3960)->exists())->toBeTrue();
});

it('books an exchange loss on 7960, and no difference at the same rate', function () {
    $loss = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    $same = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));

    $paid = app(RegisterPayment::class)($this->owner, $loss, CarbonImmutable::parse('2026-03-20'), Money::parse('10 800'));
    $even = app(RegisterPayment::class)($this->owner, $same, CarbonImmutable::parse('2026-03-20'), Money::parse('11 000'));

    expect(euroLines($paid->voucher))->toBe([1510 => -1_100_000, 1930 => 1_080_000, 7960 => 20_000])
        ->and($paid->exchange_difference_ore)->toBe(-20_000)
        ->and(euroLines($even->voucher))->toBe([1510 => -1_100_000, 1930 => 1_100_000]);
});

it('settles part payments by their share, and the last one clears the receivable to zero', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11,234567', [['33,33', 2500], ['33,33', 2500], ['33,33', 2500]]));

    $first = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::parse('470'), foreignAmount: 4_166);
    $second = app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-11'), Money::parse('465'), foreignAmount: 4_166);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->foreignRemaining())->toBe(4_167)
        ->and($invoice->fresh()->formatRemaining())->toBe('41,67 EUR')
        // 140 419 öre × 4 166 / 12 499 = 46 802,59, half up.
        ->and($first->amount_ore->ore - $first->exchange_difference_ore)->toBe(46_803)
        ->and($second->amount_ore->ore - $second->exchange_difference_ore)->toBe(46_803);

    $last = app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-12'), Money::parse('470'));

    expect($last->foreign_amount_minor)->toBe(4_167)
        ->and($last->amount_ore->ore - $last->exchange_difference_ore)->toBe(46_813)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_ore->ore)->toBe(140_419)
        ->and(euroBalance(1510))->toBe(0)
        ->and(euroBalance(1930))->toBe(140_500)
        ->and(euroBalance(3960) + euroBalance(7960))->toBe(-81);
});

it('refuses to pay more than remains in the currency, and a foreign amount on an invoice in kronor', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));

    expect(fn () => app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('12 000'), foreignAmount: 100_001))
        ->toThrow(ValidationException::class, "större än det som återstår, 1\u{00A0}000,00 EUR")
        ->and(fn () => app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('100'), foreignAmount: 0))
        ->toThrow(ValidationException::class, 'större än noll');

    $kronor = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Svensk Kund AB', 'payment_terms_days' => 30]);
    $sek = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $kronor->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 0]],
    ]));

    expect($sek->invoice_currency)->toBe('SEK')
        ->and(fn () => app(RegisterPayment::class)($this->owner, $sek, CarbonImmutable::parse('2026-03-20'), Money::parse('100'), foreignAmount: 1_000))
        ->toThrow(ValidationException::class, 'Fakturan är i kronor.');
});

it('books revenue and VAT at the booking rate under kontantmetoden, and the difference on 3960', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();

    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11', [['1000', 2500]]));
    expect($invoice->voucher_id)->toBeNull();

    $payment = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('14 000'));

    expect(euroLines($payment->voucher))->toBe([1930 => 1_400_000, 2611 => -275_000, 3001 => -1_100_000, 3960 => -25_000]);
});

it('credits an invoice in euro in the same currency and at the same rate', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-02', 'value' => 12.5]])]);
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11', [['100', 2500], ['33,33', 1200]]));

    $this->travelTo(CarbonImmutable::parse('2026-03-05 10:00:00'));
    $draft = app(CreditInvoice::class)($this->owner, $invoice);

    expect($draft->invoice_currency)->toBe('EUR')
        ->and($draft->exchange_rate_micros)->toBe(11_000_000)
        ->and($draft->foreign_total_minor)->toBe(-$invoice->foreign_total_minor)
        ->and($draft->total_ore->ore)->toBe(-$invoice->total_ore->ore);

    // A changed currency or rate on the credit draft is ignored.
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-03-05', 'currency' => 'USD', 'exchange_rate' => '9',
        'rows' => $draft->rows->load('account')->map->asInput()->all(),
    ], $draft);
    expect([$draft->invoice_currency, $draft->exchange_rate_micros])->toBe(['EUR', 11_000_000]);

    $credit = app(IssueInvoice::class)($this->owner, $draft);

    expect($credit->foreign_paid_minor)->toBe($credit->foreign_total_minor)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Credited)
        ->and($invoice->fresh()->foreignRemaining())->toBe(0)
        ->and(euroBalance(1510))->toBe(0)
        ->and(euroBalance(2611) + euroBalance(2621))->toBe(0);
});

it('does not combine ROT or RUT with another currency', function () {
    expect(fn () => euroDraft($this->customer, '11', [['1000', 2500]], ['tax_reduction' => 'RUT']))
        ->toThrow(ValidationException::class, 'räknas i kronor');
});

it('never changes the currency or rate of an issued invoice, but records payments in the currency', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    $refused = function (callable $statement): bool {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            return true;
        }

        return false;
    };

    expect($refused(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['exchange_rate_micros' => 12_000_000])))->toBeTrue()
        ->and($refused(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['invoice_currency' => 'USD'])))->toBeTrue()
        ->and($refused(fn () => DB::table('invoices')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'kind' => 'INVOICE', 'status' => 'DRAFT', 'invoice_date' => '2026-03-02', 'due_date' => '2026-03-02', 'invoice_currency' => 'EUR',
        ])))->toBeTrue()
        ->and($refused(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['foreign_paid_minor' => 1])))->toBeFalse()
        ->and(fn () => $invoice->forceFill(['foreign_total_minor' => 1])->save())->toThrow(LogicException::class);
});

it('reminds in the currency, and shows the IBAN', function () {
    Mail::fake();
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('3 300'), foreignAmount: 30_000);

    $this->travelTo(CarbonImmutable::parse('2026-04-10 09:00:00'));
    $reminder = app(SendReminder::class)($this->owner, $invoice->fresh(), 'ekonomi@exempelhandel.test');

    expect($reminder->foreign_remaining_minor)->toBe(70_000)
        ->and((new ReminderMail($invoice->fresh(), $reminder, $this->company))->render())
        ->toContain('700,00 EUR')->toContain('SE4550000000058398257466');
});

it('prints the amounts in euro and the VAT and total in kronor with the rate on the invoice', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-02-27', 'value' => 11.2]])]);
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '', [['100', 2500], ['33,33', 2500]]));

    expect(InvoicePdf::html($invoice))
        ->toContain('166,66 EUR')
        ->toContain('I svenska kronor')
        ->toContain('373,33 kr')
        ->toContain("1\u{00A0}866,63\u{00A0}kr")
        ->toContain('Riksbankens mittkurs 2026-02-27')
        ->toContain('SE4550000000058398257466')
        ->not->toContain('Öresavrundning')
        ->and(InvoicePdf::render($invoice))->toStartWith('%PDF');
});

it('registers a payment in euro in the browser with the kronor prefilled at Riksbanken\'s rate', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-02', 'value' => 11.5]])]);

    Livewire::test(InvoiceActions::class, ['invoice' => $invoice])
        ->assertSet('foreignAmount', "1\u{00A0}000,00")
        ->assertSet('amount', "11\u{00A0}500,00")
        ->assertSee("Kursvinst 500,00\u{00A0}kr bokförs på 3960.")
        ->set('foreignAmount', '400')
        ->assertSet('amount', "4\u{00A0}600,00")
        ->call('registerPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($invoice->fresh()->foreignRemaining())->toBe(60_000)
        ->and($invoice->payments()->first()->exchange_difference_ore)->toBe(20_000);
});

it('reads the amount the payer sent in euro from the bank file and books it against the invoice', function () {
    Storage::fake('local');
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    $xml = str_replace('__OCR__', (string) $invoice->ocr, (string) file_get_contents(base_path('tests/Fixtures/camt/foreign-currency-credit.xml')));

    $import = app(ImportBankFile::class)($this->owner, $this->company, UploadedFile::fake()->createWithContent('euro.xml', $xml))['import'];
    $transaction = BankTransaction::query()->where('bank_file_import_id', $import->id)->sole();

    expect($transaction->amount_ore->ore)->toBe(1_120_000)
        ->and([$transaction->foreign_currency, $transaction->foreign_amount_minor])->toBe(['EUR', 100_000])
        ->and($transaction->invoice_id)->toBe($invoice->id);

    app(BookIncomingPayments::class)($this->owner, $this->company, [$transaction->id]);
    $payment = $invoice->payments()->sole();

    expect($payment->foreign_amount_minor)->toBe(100_000)
        ->and($payment->exchange_difference_ore)->toBe(20_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(euroLines($payment->voucher))->toBe([1510 => -1_100_000, 1930 => 1_120_000, 3960 => -20_000]);
});

it('does not suggest an invoice in euro when the payer sent more euro than remains', function () {
    Storage::fake('local');
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11', [['900', 0]]));
    $xml = str_replace('__OCR__', (string) $invoice->ocr, (string) file_get_contents(base_path('tests/Fixtures/camt/foreign-currency-credit.xml')));

    $import = app(ImportBankFile::class)($this->owner, $this->company, UploadedFile::fake()->createWithContent('euro.xml', $xml))['import'];
    $transaction = BankTransaction::query()->where('bank_file_import_id', $import->id)->sole();

    expect($transaction->invoice_id)->toBeNull()
        ->and(fn () => app(BookIncomingPayments::class)->assign($this->owner, $this->company, $transaction, $invoice->id))
        ->toThrow(ValidationException::class, 'större än det som återstår');
});

it('includes the currency in the invoice.sent and invoice.paid webhook payloads', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    $payment = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('11 500'));

    expect(WebhookEvents::invoiceSent($invoice->id))->toMatchArray(['currency' => 'SEK', 'invoice_currency' => 'EUR', 'foreign_total_minor' => 100_000, 'exchange_rate_micros' => 11_000_000])
        ->and(WebhookEvents::invoicePaid($invoice->id, $payment->id))->toMatchArray(['invoice_currency' => 'EUR', 'foreign_paid_minor' => 100_000, 'foreign_amount_minor' => 100_000, 'exchange_difference_ore' => 50_000]);
});

it('never shows another company\'s invoices in euro', function () {
    $invoice = app(IssueInvoice::class)($this->owner, euroDraft($this->customer, '11'));
    [, $other] = Bookkeeping::company();

    CurrentCompany::run($other, function () use ($invoice): void {
        expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeFalse()
            ->and(Invoice::query()->where('invoice_currency', 'EUR')->count())->toBe(0);
    });
});
