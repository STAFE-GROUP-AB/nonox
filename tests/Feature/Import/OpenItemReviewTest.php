<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SendInvoice;
use App\Actions\Invoicing\SendReminder;
use App\Actions\Invoicing\WriteOffInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Domain\Invoicing\IncomingPaymentMatcher;
use App\Domain\Invoicing\InvoicePdf;
use App\Mail\ReminderMail;
use App\Migration\Import\Actions\ConfirmRegisterImport;
use App\Migration\Import\Jobs\RunRegisterImport;
use App\Migration\Import\OpenItemReconciliation;
use App\Migration\Import\RegisterFileReader;
use App\Migration\Import\RegisterKind;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Models\RegisterImport;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Camt054\BankCredit;
use App\Support\Money\Money;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, review round: an imported open invoice keeps its old number as its
 * identity, has no NONOX document, matches payments on the old OCR, and the
 * reconciliation and confirmation behave.
 */
const REVIEW_MAPPING = ['party_ref' => 'Kund', 'invoice_number' => 'Fakturanr', 'invoice_date' => 'Fakturadatum', 'due_date' => 'Forfallodatum', 'total' => 'Belopp', 'remaining' => 'Obetalt', 'vat' => 'Moms', 'ocr' => 'OCR'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1510 => 1_350_000, 3001 => -1_350_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-02', [5410 => 500_000, 2440 => -500_000]);
    RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::fixture('kunder-spiris-lik.csv'), 'spiris');
    RegisterImports::run($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::fixture('leverantorer-spiris-lik.csv'), 'spiris');
});

function reviewImport(object $test, ?string $csv = null, array $input = [], RegisterKind $kind = RegisterKind::OpenCustomerInvoices): RegisterImport
{
    $csv ??= "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;123456789\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00;987654321\n";

    return RegisterImports::run($test->owner, $test->company, $kind, RegisterImports::contents($csv, 'open-'.uniqid().'.csv'), 'nonox', ['mapping' => REVIEW_MAPPING, ...$input]);
}

function importedInvoice(object $test, string $ref): Invoice
{
    return CurrentCompany::run($test->company, fn () => Invoice::query()->where('external_ref', $ref)->firstOrFail());
}

it('keeps the old number as the identity: no NONOX number, titled with the old number', function () {
    reviewImport($this);
    $invoice = importedInvoice($this, '5001');

    expect($invoice->number)->toBeNull()
        ->and($invoice->isImported())->toBeTrue()
        ->and($invoice->title())->toBe('Faktura 5001 (tidigare program)');

    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->get(route('invoicing.invoices.index'))->assertOk()->assertSee('5001 (tidigare program)');
    $this->get(route('invoicing.invoices.show', $invoice))->assertOk()->assertSee('Faktura 5001 (tidigare program)');
});

it('finds an imported invoice in the search by the number it had before', function () {
    reviewImport($this);
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);

    $response = $this->postJson(route('search'), ['q' => '5002'])->assertOk();

    expect($response->streamedContent())->toContain('Faktura 5002 (tidigare program)');
});

it('takes no number from the invoice series and leaves the series where it was', function () {
    $before = NumberSeries::peek(NumberSeries::INVOICES);
    reviewImport($this);

    expect(CurrentCompany::run($this->company, fn () => NumberSeries::peek(NumberSeries::INVOICES)))->toBe($before);
});

it('cannot be sent or turned into a PDF', function () {
    reviewImport($this);
    $invoice = importedInvoice($this, '5001');

    expect(fn () => app(SendInvoice::class)($this->owner, $invoice, 'kund@example.test'))->toThrow(AuthorizationException::class)
        ->and(fn () => InvoicePdf::render($invoice))->toThrow(LogicException::class);

    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->get(route('invoicing.invoices.pdf', $invoice))->assertNotFound();
});

it('sends a reminder that quotes the old number, the OCR and the amount, with no attachment', function () {
    Mail::fake();
    reviewImport($this);
    $invoice = importedInvoice($this, '5001');

    $reminder = CurrentCompany::run($this->company, fn () => app(SendReminder::class)($this->owner, $invoice, 'kund@example.test'));
    $mail = new ReminderMail($invoice, $reminder, $this->company);
    $html = $mail->render();

    expect($mail->attachments())->toBe([])
        ->and(str_replace("\u{a0}", ' ', $html))->toContain('5001')->toContain('123456789')->toContain('12 500,00');
});

it('credits an imported invoice with a note that refers to the old number', function () {
    reviewImport($this);
    $invoice = importedInvoice($this, '5001');

    $draft = CurrentCompany::run($this->company, fn () => app(CreditInvoice::class)($this->owner, $invoice)->load('rows'));

    expect($draft->notes)->toContain('5001')->toContain('tidigare program')
        ->and($draft->rows)->toHaveCount(1)
        ->and($draft->rows[0]->vat_rate)->toBe(2500);
});

it('writes an imported invoice off with the imported VAT and a booking text about the opening balance', function () {
    reviewImport($this);
    $invoice = importedInvoice($this, '5001');

    $written = CurrentCompany::run($this->company, fn () => app(WriteOffInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-11-09'), 'Konkurs'));
    $voucher = CurrentCompany::run($this->company, fn () => Voucher::query()->findOrFail($written->write_off_voucher_id));
    $rows = CurrentCompany::run($this->company, fn () => VoucherRow::query()->where('voucher_id', $voucher->id)->with('account')->get());

    expect($voucher->description)->toContain('Ingående balans (inläst)')->toContain('5001')
        ->and($rows->firstWhere('account.number', 1510)->credit_ore->ore)->toBe(1_250_000)
        ->and($rows->firstWhere('account.number', 2611)->debit_ore->ore)->toBe(250_000)
        ->and($rows->firstWhere('account.number', 6351)->debit_ore->ore)->toBe(1_000_000);
});

it('books a supplier payment against the opening balance with an imported supplier invoice that has no approver', function () {
    $csv = "Leverantör;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms\n2001;L-77;2026-09-03;2026-10-03;5 000,00;5 000,00;1 000,00\n";
    RegisterImports::run($this->owner, $this->company, RegisterKind::OpenSupplierInvoices, RegisterImports::contents($csv), 'nonox', ['mapping' => array_diff_key(REVIEW_MAPPING, ['ocr' => 1, 'party_ref' => 1]) + ['party_ref' => 'Leverantör']]);
    $invoice = CurrentCompany::run($this->company, fn () => SupplierInvoice::query()->sole());

    expect($invoice->number)->toBeNull()->and($invoice->approved_by)->toBeNull()->and($invoice->title())->toBe('Leverantörsfaktura L-77 (tidigare program)');

    $payment = CurrentCompany::run($this->company, fn () => app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-11-09'), Money::ofOre(500_000)));

    expect(CurrentCompany::run($this->company, fn () => Voucher::query()->findOrFail($payment->voucher_id)->description))->toContain('Ingående balans (inläst)');
});

it('matches a bank credit that quotes the old OCR, and none when an OCR is on two invoices', function () {
    reviewImport($this);
    $credit = new BankCredit('ref', CarbonImmutable::parse('2026-11-09'), 1_250_000, '123456789', null, 'Kund');

    $matched = CurrentCompany::run($this->company, fn () => (new IncomingPaymentMatcher)->match($credit));
    expect($matched?->external_ref)->toBe('5001');

    CurrentCompany::run($this->company, function () {
        $first = Invoice::query()->where('external_ref', '5001')->firstOrFail();
        (new Invoice)->forceFill(['company_id' => $this->company->id, 'customer_id' => $first->customer_id, 'number' => null, 'status' => 'ISSUED', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'total_ore' => 1_000, 'net_ore' => 1_000, 'ocr' => '123456789', 'external_ref' => 'ZZ', 'import_source' => 'IMPORT_OPEN', 'issued_at' => now()])->save();
    });

    expect(CurrentCompany::run($this->company, fn () => (new IncomingPaymentMatcher)->match($credit)))->toBeNull();
});

it('matches on the old invoice number written in the message of the payment', function () {
    reviewImport($this);
    $credit = new BankCredit('ref', CarbonImmutable::parse('2026-11-09'), 1_250_000, null, 'Betalning faktura 5001', 'Kund');

    expect(CurrentCompany::run($this->company, fn () => (new IncomingPaymentMatcher)->match($credit))?->external_ref)->toBe('5001');
});

it('refuses an OCR that is already on an open invoice, repeated in the file or too long', function () {
    reviewImport($this);

    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;6001;2026-09-01;2026-10-01;100,00;100,00;20,00;123456789\n1001;6002;2026-09-01;2026-10-01;100,00;100,00;20,00;555\n1001;6003;2026-09-01;2026-10-01;100,00;100,00;20,00;555\n1001;6004;2026-09-01;2026-10-01;100,00;100,00;20,00;".str_repeat('1', 31)."\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv, 'ocr.csv'));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => REVIEW_MAPPING]);
    $errors = collect($import->report['rows'])->pluck('errors', 'line');

    expect($import->report['counts'])->toMatchArray(['read' => 4, 'create' => 1, 'error' => 3])
        ->and(implode(' ', $errors[2]))->toContain('finns redan på en annan obetald faktura')
        ->and(implode(' ', $errors[4]))->toContain('finns redan på en annan obetald faktura')
        ->and(implode(' ', $errors[5]))->toContain('längre än 30 tecken');
});

it('warns when the OCR column is not mapped', function () {
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => array_diff_key(REVIEW_MAPPING, ['ocr' => 1])]);

    expect(implode(' ', $import->report['notes']))->toContain('OCR-nummer är inte kopplad')->toContain('totalsumman')->toContain('annan valuta')
        ->and($import->canBeConfirmed())->toBeTrue();
});

it('reconciles the file plus open NONOX invoices against 1510 and the sub-accounts the user names', function () {
    // 100,00 of the receivable sits on 1511, and a NONOX invoice of 50,00 is open: file 13 500,00 + 50,00 equals 1510 + 1511.
    CurrentCompany::run($this->company, fn () => Account::create(['number' => 1511, 'name' => 'Kundfordringar, delbetalda', 'type' => 'ASSET']));
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-05', [1511 => 10_000, 1510 => -10_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-06', [1510 => 5_000, 3001 => -5_000]);
    CurrentCompany::run($this->company, function () {
        $customer = Customer::query()->firstOrFail();
        (new Invoice)->forceFill(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'number' => 1, 'status' => 'ISSUED', 'invoice_date' => '2026-03-06', 'due_date' => '2026-04-06', 'total_ore' => 5_000, 'net_ore' => 4_000, 'vat_ore' => 1_000, 'issued_at' => now()])->save();
    });

    $without = reviewImport($this);
    expect($without->canBeConfirmed())->toBeFalse()->and($without->report['reconciliation']['difference_ore'])->toBe(10_000);

    $with = reviewImport($this, null, ['extra_accounts' => '1511']);
    expect($with->status)->toBe(RegisterImport::COMPLETED)
        ->and($with->report['reconciliation'])->toMatchArray(['file_ore' => 1_350_000, 'nonox_ore' => 5_000, 'ledger_ore' => 1_355_000, 'equal' => true]);
});

it('reads credit notes with a negative remainder and counts them with a minus', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-07', [3001 => 50_000, 1510 => -50_000]);
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;1\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00;2\n1002;K-9;2026-09-20;2026-09-20;-500,00;-500,00;-100,00;3\n";
    $import = reviewImport($this, $csv);
    $credit = importedInvoice($this, 'K-9');

    expect($import->status)->toBe(RegisterImport::COMPLETED)
        ->and($credit->kind)->toBe(Invoice::CREDIT)
        ->and($credit->total_ore->ore)->toBe(-50_000)
        ->and($credit->title())->toBe('Kreditfaktura K-9 (tidigare program)');
});

it('defaults to the latest year with postings and warns about an older one', function () {
    CurrentCompany::run($this->company, fn () => app(CreateNextFiscalYear::class)($this->owner, $this->company));
    $import = reviewImport($this);
    $olderId = CurrentCompany::run($this->company, fn () => FiscalYear::query()->orderBy('start_date')->value('id'));
    $default = CurrentCompany::run($this->company, fn () => OpenItemReconciliation::defaultYear());

    expect($default->start_date->format('Y'))->toBe('2026')
        ->and($import->status)->toBe(RegisterImport::COMPLETED);

    $again = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents("Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;7001;2026-09-01;2026-10-01;1,00;1,00;0,20;9\n"));
    $again = RegisterImports::analyse($this->owner, $again, ['mapping' => REVIEW_MAPPING, 'fiscal_year_id' => $default->id === $olderId ? 0 : $olderId]);

    expect($default->id === $olderId || str_contains(implode(' ', $again->report['notes'] ?? []), 'ett annat räkenskapsår'))->toBeTrue();
});

it('finds the party on the NONOX customer number only when the user says so', function () {
    $csv = "Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1;8001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;11\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents($csv));

    $default = RegisterImports::analyse($this->owner, $import, ['mapping' => REVIEW_MAPPING]);
    expect($default->report['counts']['error'])->toBe(1);

    $chosen = RegisterImports::analyse($this->owner, $import, ['mapping' => REVIEW_MAPPING, 'match_nonox_number' => true]);
    expect($chosen->report['counts']['create'])->toBe(1);
});

it('lets only one confirmation start the run, and the job leaves a finished import alone', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::OpenCustomerInvoices, RegisterImports::contents("Kund;Fakturanr;Fakturadatum;Forfallodatum;Belopp;Obetalt;Moms;OCR\n1001;5001;2026-09-01;2026-10-01;12 500,00;12 500,00;2 500,00;1\n1002;5002;2026-09-15;2026-10-15;2 000,00;1 000,00;400,00;2\n"));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => REVIEW_MAPPING]);

    app(ConfirmRegisterImport::class)($this->owner, $import);

    expect(fn () => app(ConfirmRegisterImport::class)($this->owner, $import))->toThrow(ValidationException::class, 'redan startat')
        ->and(CurrentCompany::run($this->company, fn () => Invoice::query()->whereNotNull('import_source')->count()))->toBe(2);

    RunRegisterImport::dispatchSync($import->id, $this->company->id, $this->owner->id);

    expect(CurrentCompany::run($this->company, fn () => Invoice::query()->whereNotNull('import_source')->count()))->toBe(2);
});

it('refuses a second claim with a Swedish message when the status already changed', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents("Namn\nA AB\n"));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn']]);
    $stale = clone $import;

    app(ConfirmRegisterImport::class)($this->owner, $import);

    expect(fn () => app(ConfirmRegisterImport::class)($this->owner, $stale))->toThrow(ValidationException::class, 'redan startat');
});

it('ends a dying job in FAILED, and the nightly command ends a run stuck for two hours', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents("Namn\nA AB\n"));
    CompanyScope::bypass(fn () => RegisterImport::query()->whereKey($import->id)->toBase()->update(['status' => RegisterImport::RUNNING, 'started_at' => now()]));

    (new RunRegisterImport($import->id, $this->company->id, $this->owner->id))->failed(new RuntimeException('killed'));
    $failed = CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($import->id));
    expect($failed->status)->toBe(RegisterImport::FAILED)->and($failed->error)->toContain('avbröts');

    CompanyScope::bypass(fn () => RegisterImport::query()->whereKey($import->id)->toBase()->update(['status' => RegisterImport::RUNNING, 'started_at' => now()->subHours(3), 'finished_at' => null, 'error' => null]));
    $this->artisan('register-import:prune')->assertSuccessful();

    expect(CompanyScope::bypass(fn () => RegisterImport::query()->findOrFail($import->id)->status))->toBe(RegisterImport::FAILED);
});

it('says in Swedish that a finished import cannot be reviewed again', function () {
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents("Namn\nZ AB\n"), 'nonox', ['mapping' => ['name' => 'Namn']]);

    expect(fn () => RegisterImports::analyse($this->owner, $import, []))->toThrow(ValidationException::class, 'går inte att granska igen');
});

it('asks about a code a preset does not list, but not about every plain number', function () {
    $csv = "IsActive,CustomerNumber,Name,TermsofPaymentCode\nTrue,9,Kod AB,99\nTrue,10,Kod Två AB,40\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents($csv), 'spiris');
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['counts'])->toMatchArray(['create' => 1, 'error' => 1])
        ->and(array_values($import->report['unmapped']['payment_terms_days']))->toBe(['99']);
});

it('gives a row error for characters that are not valid in the chosen encoding', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents("Namn;Ort\nA AB;Stockholm\n"));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn']]);
    $path = Storage::disk('local')->path($import->file_path);
    file_put_contents($path, "Namn;Ort\nB\xE5 AB;Ort\n");
    Storage::disk('local')->put($import->file_path, "Namn;Ort\nB\xE5 AB;Ort\n");
    $import = RegisterImports::analyse($this->owner, $import, ['reader' => ['encoding' => 'UTF-8']]);

    expect($import->report['counts']['error'])->toBe(1)
        ->and(collect($import->report['rows'])->first()['errors'][0])->toContain('teckenkodning');
});

it('does not take a name alone as a match: two firms can share a name', function () {
    CurrentCompany::run($this->company, fn () => app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Samma Namn AB', 'payment_terms_days' => 30]));
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Customers, RegisterImports::contents("Namn\nSamma Namn AB\n"), 'nonox', ['mapping' => ['name' => 'Namn']]);

    expect($import->result['created'])->toBe(1)
        ->and(CurrentCompany::run($this->company, fn () => Customer::query()->where('name', 'Samma Namn AB')->count()))->toBe(2);
});

it('refuses a workbook that unpacks to absurdly more than it weighs before opening it', function () {
    $path = tempnam(sys_get_temp_dir(), 'bomb').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat("\0", 3_000_000));
    $zip->close();

    expect(fn () => RegisterFileReader::open($path))->toThrow(RuntimeException::class, 'orimligt');
    unlink($path);
});
