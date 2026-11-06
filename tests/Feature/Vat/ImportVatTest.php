<?php

declare(strict_types=1);

/*
 * Import VAT on goods from outside the EU, booked from Tullverket's
 * tullräkning (ADR-057). Every expected value is worked out by hand from
 * the amounts in the test. Sources (read 2026-11-06):
 * - Mervärdesskattelag (2023:200) 8 kap. 24–27 §§ (base), 7 kap. 27 och 41 §§ (period):
 *   https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/
 * - Skatteverket, "Köpa varor från länder utanför EU" (fält 50, 60–62, 48; the
 *   examples 105 000 + 12 600 = 117 600 and 92 000 + 8 000 = 100 000; "oavsett
 *   om du använder bokslutsmetoden eller faktureringsmetoden"):
 *   https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/inkopfranlanderutanforeu/kopavarorfranlanderutanforeuimport.4.361dc8c15312eff6fd37208.html
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Vat\ProposeImportVat;
use App\Domain\Company\VatPeriod;
use App\Domain\Payables\VatTreatment;
use App\Domain\Vat\ImportVat;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatReturn;
use App\Livewire\Accounting\VoucherEditor;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Quarterly, 'separate_supplier_approval' => false])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    $this->supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Shenzhen Lamps Ltd', 'country' => 'CN', 'payment_terms_days' => 30]);
});

/**
 * @param  list<array<string, string>>  $lines
 * @return array{date: string, description: string, rows: list<array{account: int, name: string, debit_ore: int, credit_ore: int}>}
 */
function importProposal(User $user, Company $company, array $lines, string $kind = ImportVat::GOODS, string $date = '2026-02-10', bool $reduce = false): array
{
    return app(ProposeImportVat::class)($user, $company, ['date' => $date, 'reference' => '26SE0001', 'kind' => $kind, 'reduce' => $reduce, 'lines' => $lines]);
}

/** @param  list<array<string, string>>  $lines */
function postImport(User $user, Company $company, array $lines, string $kind = ImportVat::GOODS, string $date = '2026-02-10', bool $reduce = false): Voucher
{
    $proposal = importProposal($user, $company, $lines, $kind, $date, $reduce);

    return app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => $proposal['date'],
        'description' => $proposal['description'],
        'rows' => array_map(fn (array $row): array => ['account' => $row['account'], 'debit_ore' => $row['debit_ore'], 'credit_ore' => $row['credit_ore']], $proposal['rows']),
    ]));
}

/** @return array<int, int> signed öre per account, debit positive */
function importVatVoucherLines(Voucher $voucher): array
{
    $lines = [];

    foreach ($voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

/** @return array<int, int> the declared boxes 48, 49, 50, 60, 61, 62 in kronor */
function importBoxes(FiscalYear $year, string $start = '2026-01-01', string $end = '2026-03-31'): array
{
    $boxes = VatReturn::calculate($year, CarbonImmutable::parse($start), CarbonImmutable::parse($end))->boxes();

    $boxes = array_intersect_key($boxes, array_flip([48, 49, 50, 60, 61, 62]));
    ksort($boxes);

    return $boxes;
}

/** @param  list<array<string, string>>  $rows */
function importSupplierInvoice(User $user, Company $company, Supplier $supplier, array $input, array $rows): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => '2026-03-05',
        'vat_treatment' => 'NON_EU_GOODS',
        ...$input,
        'rows' => $rows,
    ]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

it('books Skatteverket\'s example at 25 %: customs value 105 000 plus duty 12 600 is a base of 117 600', function () {
    $voucher = postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '105 000', 'duty' => '12 600', 'incidental' => '']]);

    expect(importVatVoucherLines($voucher))->toBe([2615 => -2_940_000, 2645 => 2_940_000, 4080 => -11_760_000, 4085 => 11_760_000])
        ->and($voucher->voucher_date->toDateString())->toBe('2026-02-10')
        ->and($voucher->description)->toBe('Importmoms, tullräkning 26SE0001')
        ->and(importBoxes($this->year))->toBe([48 => 29_400, 49 => 0, 50 => 117_600, 60 => 29_400, 61 => 0, 62 => 0]);
});

it('adds the bikostnader to the first destination in Sweden, as in Skatteverket\'s second example', function () {
    postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '92 000', 'duty' => '0', 'incidental' => '8 000']]);

    expect(importBoxes($this->year))->toBe([48 => 25_000, 49 => 0, 50 => 100_000, 60 => 25_000, 61 => 0, 62 => 0]);
});

it('splits mixed rates into boxes 60, 61 and 62 with one base in box 50', function () {
    $voucher = postImport($this->owner, $this->company, [
        ['rate' => '2500', 'customs_value' => '40 000', 'duty' => '1 200', 'incidental' => '800'],
        ['rate' => '1200', 'customs_value' => '10 000', 'duty' => '', 'incidental' => '200'],
        ['rate' => '600', 'customs_value' => '5 000', 'duty' => '', 'incidental' => ''],
    ], ImportVat::MATERIALS);

    // 42 000 × 25 % = 10 500, 10 200 × 12 % = 1 224, 5 000 × 6 % = 300.
    expect(importVatVoucherLines($voucher))->toBe([
        2615 => -1_050_000, 2625 => -122_400, 2635 => -30_000, 2645 => 1_202_400,
        4540 => -5_720_000, 4545 => 4_200_000, 4546 => 1_020_000, 4547 => 500_000,
    ])
        ->and(importBoxes($this->year))->toBe([48 => 12_024, 49 => 0, 50 => 57_200, 60 => 10_500, 61 => 1_224, 62 => 300])
        ->and(Account::query()->where('number', 4546)->value('name'))->toBe('Import av råvaror och material, 12 % moms')
        ->and(Account::query()->where('number', 2625)->value('name'))->toBe('Utgående moms import av varor, 12 %');
});

it('rounds the VAT per rate to öre and keeps box 49 the difference of the declared boxes', function () {
    postImport($this->owner, $this->company, [['rate' => '1200', 'customs_value' => '1 234,55', 'duty' => '', 'incidental' => '']]);

    // 1 234,55 × 12 % = 148,146 → 148,15 kr; declared in whole kronor.
    expect(VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->exact[61])->toBe(14_815)
        ->and(importBoxes($this->year))->toBe([48 => 148, 49 => 0, 50 => 1_234, 60 => 0, 61 => 148, 62 => 0]);
});

it('declares the customs value in kronor from the tullräkning, not the supplier\'s invoice in dollars', function () {
    // 10 000 USD at 10,50 = 105 000 kr on the supplier's invoice; Tullverket fixed the customs value at 98 650 kr.
    $invoice = importSupplierInvoice($this->owner, $this->company, $this->supplier, [
        'total' => '105 000', 'vat' => '999', 'currency' => 'USD', 'foreign_total' => '10 000', 'exchange_rate' => '10,50',
    ], [['account' => '4010', 'amount' => '105 000', 'description' => 'Lampor']]);

    expect($invoice->vat_treatment)->toBe(VatTreatment::NonEuGoods)
        ->and($invoice->vat_ore->ore)->toBe(0)
        ->and(importVatVoucherLines($invoice->voucher))->toBe([2440 => -10_500_000, 4010 => 10_500_000]);

    $voucher = postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '98 650', 'duty' => '3 946', 'incidental' => '']], date: '2026-03-12');

    $cost = importVatVoucherLines($invoice->voucher)[4010] + importVatVoucherLines($voucher)[4080] + importVatVoucherLines($voucher)[4085];

    // 102 596 × 25 % = 25 649. The goods still cost what the invoice says.
    expect(importBoxes($this->year))->toBe([48 => 25_649, 49 => 0, 50 => 102_596, 60 => 25_649, 61 => 0, 62 => 0])
        ->and($cost)->toBe(10_500_000);
});

it('books a decision from Tullverket that lowers the base the other way round', function () {
    postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '105 000', 'duty' => '12 600', 'incidental' => '']]);
    $credit = postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '', 'duty' => '2 600', 'incidental' => '']], date: '2026-03-02', reduce: true);

    expect(importVatVoucherLines($credit))->toBe([2615 => 65_000, 2645 => -65_000, 4080 => 260_000, 4085 => -260_000])
        ->and($credit->description)->toBe('Importmoms, ändrat beslut från Tullverket 26SE0001')
        ->and(importBoxes($this->year))->toBe([48 => 28_750, 49 => 0, 50 => 115_000, 60 => 28_750, 61 => 0, 62 => 0]);
});

it('reports the import VAT in the period of the tullräkning under kontantmetoden too, not when the supplier is paid', function () {
    $this->company->forceFill(['accounting_method' => 'KONTANTMETODEN'])->save();

    $invoice = importSupplierInvoice($this->owner, $this->company, $this->supplier, ['total' => '90 000'], [['account' => '4010', 'amount' => '90 000']]);
    postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '92 000', 'duty' => '', 'incidental' => '8 000']], date: '2026-03-20');

    expect($invoice->voucher_id)->toBeNull()
        ->and(importBoxes($this->year))->toBe([48 => 25_000, 49 => 0, 50 => 100_000, 60 => 25_000, 61 => 0, 62 => 0]);

    $this->travelTo(CarbonImmutable::parse('2026-04-20 10:00:00'));
    app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-15'), Money::parse('90 000'));

    expect(importBoxes($this->year, '2026-04-01', '2026-06-30'))->toBe([48 => 0, 49 => 0, 50 => 0, 60 => 0, 61 => 0, 62 => 0]);
});

it('shows the import accounts behind boxes 50, 60 and 48 for drill-down', function () {
    postImport($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '105 000', 'duty' => '12 600', 'incidental' => '']]);

    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

    expect(array_column($return->sources[50], 'number'))->toBe([4085])
        ->and(array_column($return->sources[60], 'number'))->toBe([2615])
        ->and(array_column($return->sources[48], 'number'))->toContain(2645)
        ->and(VatBoxes::boxFor(4080))->toBeNull()
        ->and(VatBoxes::boxFor(4540))->toBeNull()
        ->and(VatBoxes::boxFor(4086))->toBe(50)
        ->and(VatBoxes::boxFor(4547))->toBe(50);
});

it('always balances, whatever the amounts', function () {
    mt_srand(57);

    foreach (range(1, 50) as $attempt) {
        $lines = array_map(fn (int $rate): array => ['rate' => $rate, 'customs_value' => mt_rand(1, 50_000_000), 'duty' => mt_rand(0, 5_000_000), 'incidental' => mt_rand(0, 1_000_000)], ImportVat::RATES);
        $rows = ImportVat::rows(mt_rand(0, 1) === 1 ? ImportVat::GOODS : ImportVat::MATERIALS, $lines, mt_rand(0, 1) === 1);

        expect(array_sum(array_column($rows, 'debit_ore')))->toBe(array_sum(array_column($rows, 'credit_ore')));
    }
});

it('explains what is missing on the tullräkning', function (array $input, string $field) {
    expect(fn () => app(ProposeImportVat::class)($this->owner, $this->company, ['date' => '2026-02-10', 'kind' => ImportVat::GOODS, ...$input]))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));
})->with([
    'no lines' => [['lines' => []], 'lines'],
    'only empty lines' => [['lines' => [['rate' => '2500', 'customs_value' => '', 'duty' => '', 'incidental' => '']]], 'lines'],
    'a rate that is not Swedish' => [['lines' => [['rate' => '2000', 'customs_value' => '100', 'duty' => '', 'incidental' => '']]], 'lines.0.rate'],
    'duty without a customs value' => [['lines' => [['rate' => '2500', 'customs_value' => '', 'duty' => '100', 'incidental' => '']]], 'lines.0.customs_value'],
    'an unreadable amount' => [['lines' => [['rate' => '2500', 'customs_value' => 'mycket', 'duty' => '', 'incidental' => '']]], 'lines.0.customs_value'],
    'a negative amount' => [['lines' => [['rate' => '2500', 'customs_value' => '-100', 'duty' => '', 'incidental' => '']]], 'lines.0.customs_value'],
    'an unknown kind' => [['kind' => 'tjanster', 'lines' => [['rate' => '2500', 'customs_value' => '100']]], 'kind'],
]);

it('lets only those who may create vouchers propose one', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => importProposal($auditor, $this->company, [['rate' => '2500', 'customs_value' => '100']]))
        ->toThrow(AuthorizationException::class);
});

it('adds the missing BAS accounts to the company\'s own chart only', function () {
    [$otherOwner, $other] = Bookkeeping::company();

    importProposal($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '100']]);

    expect(Account::query()->whereIn('number', [4080, 4085, 2615, 2645])->count())->toBe(4)
        ->and(CompanyScope::bypass(fn () => Account::query()->whereIn('number', [4080, 4085])->where('company_id', $other->id)->count()))->toBe(0);
});

it('cannot propose into another company', function () {
    [$otherOwner, $other] = Bookkeeping::company();

    expect(fn () => importProposal($this->owner, $other, [['rate' => '2500', 'customs_value' => '100']]))
        ->toThrow(AuthorizationException::class);
});

describe('in the voucher editor', function () {
    it('fills the date, the description and the rows from the tullräkning, and posts them', function () {
        Livewire::test(VoucherEditor::class)
            ->set('importDate', '2026-02-10')
            ->set('importReference', '26SE0001')
            ->set('importLines.0.customs_value', '105 000')
            ->set('importLines.0.duty', '12 600')
            ->call('applyImportVat')
            ->assertHasNoErrors()
            ->assertSet('voucherDate', '2026-02-10')
            ->assertSet('description', 'Importmoms, tullräkning 26SE0001')
            ->assertSet('rows.0.account', '4085 Import av handelsvaror, 25 % moms')
            ->assertSet('rows.0.debit', Money::parse('117600')->format(withCurrency: false))
            ->assertSet('rows.1.account', '2615 Utgående moms import av varor, 25 %')
            ->assertSet('rows.1.credit', Money::parse('29400')->format(withCurrency: false))
            ->assertSet('rows.2.account', '4080 Import av handelsvaror')
            ->assertSet('rows.3.account', '2645 Beräknad ingående moms på förvärv från utlandet')
            ->assertDispatched('toast')
            ->call('post')
            ->assertHasNoErrors();

        expect(importBoxes($this->year))->toBe([48 => 29_400, 49 => 0, 50 => 117_600, 60 => 29_400, 61 => 0, 62 => 0]);
    });

    it('shows the errors next to the import fields', function () {
        Livewire::test(VoucherEditor::class)
            ->set('importDate', '2026-02-10')
            ->set('importLines.0.duty', '100')
            ->call('applyImportVat')
            ->assertHasErrors('import_lines.0.customs_value')
            ->assertSee('skriv tullvärdet från tullräkningen')
            ->call('addImportLine')
            ->assertCount('importLines', 2)
            ->call('removeImportLine', 0)
            ->assertCount('importLines', 1);
    });
});

it('reads BAS 2026\'s trading-goods accounts into box 20 and box 23', function () {
    foreach ([4075 => 'Inköp av handelsvaror från annat EU-land, 25 % moms', 4065 => 'Inköp av handelsvaror i Sverige, omvänd betalningsskyldighet, 25 % moms', 2614 => 'Utgående moms omvänd betalskyldighet, 25 %', 2645 => 'Beräknad ingående moms på förvärv från utlandet', 2647 => 'Ingående moms omvänd betalningsskyldighet varor och tjänster i Sverige'] as $number => $name) {
        if (! Account::query()->where('number', $number)->exists()) {
            Account::create(['number' => $number, 'name' => $name, 'type' => $number >= 4000 ? 'EXPENSE' : 'LIABILITY_EQUITY']);
        }
    }

    Bookkeeping::postRows($this->owner, $this->company, '2026-02-10', [4075 => 10_000_000, 2645 => 2_500_000, 2614 => -2_500_000, 2440 => -10_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-02-11', [4065 => 4_000_000, 2647 => 1_000_000, 2614 => -1_000_000, 2440 => -4_000_000]);

    $boxes = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    expect($boxes[20])->toBe(100_000)
        ->and($boxes[23])->toBe(40_000)
        ->and($boxes[30])->toBe(35_000)
        ->and($boxes[48])->toBe(35_000)
        ->and(VatBoxes::boxFor(4077))->toBe(20)
        ->and(VatBoxes::boxFor(4067))->toBe(23)
        ->and(VatBoxes::boxFor(4070))->toBeNull()
        ->and(VatBoxes::boxFor(4060))->toBeNull();
});

it('refuses the import base accounts on the supplier\'s invoice, so box 50 is not counted twice', function (int $account) {
    importProposal($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '100'], ['rate' => '1200', 'customs_value' => '100'], ['rate' => '600', 'customs_value' => '100']]);
    importProposal($this->owner, $this->company, [['rate' => '2500', 'customs_value' => '100'], ['rate' => '1200', 'customs_value' => '100'], ['rate' => '600', 'customs_value' => '100']], ImportVat::MATERIALS);

    expect(fn () => app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $this->supplier->id, 'invoice_date' => '2026-03-05', 'vat_treatment' => 'NON_EU_GOODS',
        'total' => '100 000', 'rows' => [['account' => (string) $account, 'amount' => '100 000']],
    ]))->toThrow(fn (ValidationException $exception) => expect($exception->errors()['rows.0.account'][0])->toContain('bara för verifikationen från tullräkningen'));
})->with([4085, 4086, 4087, 4545, 4546, 4547]);

it('refuses odd input on the tullräkning lines without failing', function (array $lines) {
    expect(fn () => importProposal($this->owner, $this->company, $lines))
        ->toThrow(ValidationException::class);
})->with([
    'an array as an amount' => [[['rate' => '2500', 'customs_value' => ['1', '2'], 'duty' => '', 'incidental' => '']]],
    'an array as the rate' => [[['rate' => ['2500'], 'customs_value' => '100', 'duty' => '', 'incidental' => '']]],
    'more lines than rates' => [array_fill(0, 4, ['rate' => '2500', 'customs_value' => '100', 'duty' => '', 'incidental' => ''])],
]);

it('names the date of the changed decision when the base is lowered', function () {
    Livewire::test(VoucherEditor::class)
        ->assertSee('Tullräkningens datum')
        ->set('importReduce', true)
        ->assertSee('Datum för Tullverkets ändrade beslut');
});
