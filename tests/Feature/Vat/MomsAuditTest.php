<?php

declare(strict_types=1);

/*
 * Legal audit of the VAT area (granska-moms, 2026-11-02).
 *
 * Every expected value below is computed by hand from the transactions in
 * the test, not printed by the code. Sources (read 2026-11-02):
 * - Skatteverket, "Fylla i momsdeklarationen" (fält 05–62):
 *   https://skatteverket.se/foretag/moms/deklareramoms/fyllaimomsdeklarationen.4.3a2a542410ab40a421c80004214.html
 * - Skatteverket, "När ska jag deklarera moms?" (deadlines, period choice):
 *   https://skatteverket.se/foretag/moms/deklareramoms/narskajagdeklareramoms.4.6d02084411db6e252fe80008988.html
 * - ML 2023:200 9 kap. (rates), bet. 2025/26:SkU9 (temporary 6 % on food 2026-04-01–2027-12-31).
 * - SFL 2011:1244 26 kap. (redovisningsperioder and deadlines).
 * Findings are listed in the audit report "moms" (AUD-MOMS-n).
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Vat\SettleVat;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Vat\EskdFile;
use App\Domain\Vat\EuSalesList;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatDeadlines;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Quarterly, 'org_number' => '556334-3689', 'vat_number' => 'SE556334368901', 'separate_supplier_approval' => false])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

/** @param  list<array<string, mixed>>  $rows */
function momsAuditBook(User $user, Company $company, string $date, array $rows): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => 'Revision moms', 'rows' => $rows]));
}

function momsAuditAccount(int $number, string $name, string $type): void
{
    if (! Account::query()->where('number', $number)->exists()) {
        Account::create(['number' => $number, 'name' => $name, 'type' => $type]);
    }
}

/** @param  array<string, mixed>  $attributes */
function momsAuditCustomer(User $user, Company $company, array $attributes): Customer
{
    return app(SaveCustomer::class)($user, $company, [...$attributes, 'payment_terms_days' => 30]);
}

/** @param  list<array<string, mixed>>  $rows */
function momsAuditInvoice(User $user, Company $company, Customer $customer, string $date, array $rows): Invoice
{
    return app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'rows' => $rows,
    ]));
}

/** @param  list<array<string, string>>  $rows */
function momsAuditSupplierInvoice(User $user, Company $company, Supplier $supplier, string $date, string $total, string $vat, array $rows, string $treatment): void
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => $date,
        'total' => $total,
        'vat' => $vat,
        'credit' => false,
        'vat_treatment' => $treatment,
        'rows' => $rows,
    ]);

    app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft));
}

/**
 * One quarter (Q1 2026) of mixed trade through the real actions:
 * domestic sales at 25/12/6 %, a fully credited invoice, construction
 * services under reverse charge, EU and export sales, purchases with Swedish
 * VAT, from the EU, from outside the EU and under Swedish reverse charge,
 * import of goods, and a till receipt with öre.
 */
function momsAuditQuarter(User $user, Company $company): void
{
    $domestic = momsAuditCustomer($user, $company, ['name' => 'Kund i Sverige AB']);
    $builder = momsAuditCustomer($user, $company, ['name' => 'Byggfirman AB', 'org_number' => '5563343689', 'vat_number' => 'SE556334368901', 'reverse_charge_construction' => true]);
    $euGoods = momsAuditCustomer($user, $company, ['name' => 'Waren GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE', 'foreign_sale' => ForeignSale::EuGoods->value]);
    $euServices = momsAuditCustomer($user, $company, ['name' => 'Dienst GmbH', 'vat_number' => 'DE987654321', 'country' => 'DE', 'foreign_sale' => ForeignSale::EuServices->value]);
    $exportGoods = momsAuditCustomer($user, $company, ['name' => 'Goods Inc', 'country' => 'US', 'foreign_sale' => ForeignSale::ExportGoods->value]);
    $exportServices = momsAuditCustomer($user, $company, ['name' => 'Services Inc', 'country' => 'US', 'foreign_sale' => ForeignSale::ExportServices->value]);

    // Box 05 13 000, box 10 2 500, box 11 240, box 12 60.
    momsAuditInvoice($user, $company, $domestic, '2026-01-15', [
        ['description' => 'Konsulttjänst', 'quantity' => '10', 'price' => '1 000', 'vat_rate' => 2500],
        ['description' => 'Lunch', 'quantity' => '20', 'price' => '100', 'vat_rate' => 1200],
        ['description' => 'Böcker', 'quantity' => '4', 'price' => '250', 'vat_rate' => 600],
    ]);

    // Issued and fully credited in the same quarter (the credit note is dated today, 2026-03-31): nets to zero in boxes 05 and 10.
    $credited = momsAuditInvoice($user, $company, $domestic, '2026-02-01', [['description' => 'Felaktig', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]);
    app(IssueInvoice::class)($user, app(CreditInvoice::class)($user, $credited));

    // Box 41 10 000 (seller of construction services, ML 16 kap. 13 §).
    momsAuditInvoice($user, $company, $builder, '2026-02-10', [['description' => 'Snickeri', 'quantity' => '10', 'price' => '1 000', 'vat_rate' => 2500]]);
    // Box 35 5 000, box 39 3 000, box 36 2 000, box 40 1 000.
    momsAuditInvoice($user, $company, $euGoods, '2026-02-20', [['description' => 'Maskindel', 'quantity' => '1', 'price' => '5 000', 'vat_rate' => 2500]]);
    momsAuditInvoice($user, $company, $euServices, '2026-03-02', [['description' => 'Konsult', 'quantity' => '3', 'price' => '1 000', 'vat_rate' => 2500]]);
    momsAuditInvoice($user, $company, $exportGoods, '2026-03-03', [['description' => 'Maskin', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500]]);
    momsAuditInvoice($user, $company, $exportServices, '2026-03-04', [['description' => 'Support', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]);

    $supplier = app(SaveSupplier::class)($user, $company, ['name' => 'Leverantören AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);

    // Box 48 +1 000.
    momsAuditSupplierInvoice($user, $company, $supplier, '2026-01-20', '5000', '1000', [['amount' => '4000']], 'DOMESTIC');
    // Box 20 2 000 + 1 000, box 30 +500, box 31 120, box 48 +620.
    momsAuditSupplierInvoice($user, $company, $supplier, '2026-02-05', '3000', '0', [
        ['amount' => '2000', 'vat_rate' => '2500', 'description' => 'Varor'],
        ['amount' => '1000', 'vat_rate' => '1200', 'description' => 'Livsmedel'],
    ], 'EU_GOODS');
    // Box 21 1 000, box 30 +250, box 48 +250.
    momsAuditSupplierInvoice($user, $company, $supplier, '2026-02-06', '1000', '0', [['amount' => '1000', 'vat_rate' => '2500', 'description' => 'Licens EU']], 'EU_SERVICES');
    // Box 22 800, box 30 +200, box 48 +200.
    momsAuditSupplierInvoice($user, $company, $supplier, '2026-02-07', '800', '0', [['amount' => '800', 'vat_rate' => '2500', 'description' => 'Licens USA']], 'NON_EU_SERVICES');
    // Box 23 400, box 30 +100, box 48 +100.
    momsAuditSupplierInvoice($user, $company, $supplier, '2026-02-08', '400', '0', [['amount' => '400', 'vat_rate' => '2500', 'description' => 'Skrot']], 'SE_REVERSE_GOODS');
    // Box 24 6 000, box 30 +1 500, box 48 +1 500 (buyer of construction services).
    momsAuditSupplierInvoice($user, $company, $supplier, '2026-02-09', '6000', '0', [['amount' => '6000', 'vat_rate' => '2500', 'description' => 'Ombyggnad']], 'SE_REVERSE_SERVICES');

    // Import of goods booked by hand from the customs decision: box 50 10 000, box 60 2 500, box 48 +2 500.
    momsAuditAccount(4545, 'Import av varor, 25 % moms', 'EXPENSE');
    momsAuditAccount(4549, 'Motkonto beskattningsunderlag import', 'EXPENSE');
    momsAuditAccount(2615, 'Utgående moms import av varor, 25 %', 'LIABILITY_EQUITY');
    momsAuditBook($user, $company, '2026-03-10', [
        ['account' => 4545, 'debit_ore' => 1_000_000],
        ['account' => 4549, 'credit_ore' => 1_000_000],
        ['account' => 2645, 'debit_ore' => 250_000],
        ['account' => 2615, 'credit_ore' => 250_000],
    ]);

    // A till receipt with öre: box 05 +99,99, box 10 +24,99.
    momsAuditBook($user, $company, '2026-03-31', [
        ['account' => 1930, 'debit_ore' => 12_498],
        ['account' => 3001, 'credit_ore' => 9_999],
        ['account' => 2611, 'credit_ore' => 2_499],
    ]);
}

it('declares a mixed quarter in every box as computed by hand (Skatteverket fält 05–62)', function () {
    momsAuditQuarter($this->owner, $this->company);

    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

    // Output VAT 2 524 + 240 + 60 + 2 550 + 120 + 2 500 = 7 994; input VAT 6 170; to pay 1 824.
    expect(array_filter($return->boxes()))->toEqual([
        5 => 13_099,
        10 => 2_524,
        11 => 240,
        12 => 60,
        20 => 3_000,
        21 => 1_000,
        22 => 800,
        23 => 400,
        24 => 6_000,
        30 => 2_550,
        31 => 120,
        35 => 5_000,
        36 => 2_000,
        39 => 3_000,
        40 => 1_000,
        41 => 10_000,
        48 => 6_170,
        50 => 10_000,
        60 => 2_500,
        49 => 1_824,
    ]);
});

it('settles the quarter to 2650 in whole kronor with the öre on 3740, in series M', function () {
    momsAuditQuarter($this->owner, $this->company);

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    $voucher = $report->voucher()->with('rows.account', 'series')->sole();
    $lines = $voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // Exact VAT: output 7 994,99, input 6 170,00 → 1 824,99; declared 1 824 → 0,99 kr to 3740.
    expect($voucher->series->code)->toBe('M')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-03-31')
        ->and($lines[2650])->toBe(-182_400)
        ->and($lines[3740])->toBe(-99)
        ->and($lines[2611])->toBe(252_499)
        ->and($lines[2614])->toBe(255_000)
        ->and($lines[2641] + $lines[2645] + $lines[2647])->toBe(-617_000)
        ->and(array_sum($lines))->toBe(0)
        ->and($report->boxes[49])->toBe(1_824);
});

it('drills each box down to the accounts behind it', function () {
    momsAuditQuarter($this->owner, $this->company);

    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

    foreach ($return->sources as $box => $sources) {
        expect(array_sum(array_column($sources, 'amount')))->toBe($return->exact[$box]);
    }

    expect(collect($return->sources[48])->pluck('amount', 'number')->all())->toEqual([2641 => 100_000, 2645 => 357_000, 2647 => 160_000]);
});

it('writes every box to the eSKD element Skatteverket names for it', function () {
    momsAuditQuarter($this->owner, $this->company);

    $xml = EskdFile::render($this->company, VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31')));

    expect($xml)->toContain('<Period>202603</Period>')
        ->toContain('<ForsMomsEjAnnan>13099</ForsMomsEjAnnan>')
        ->toContain('<InkopVaruAnnatEg>3000</InkopVaruAnnatEg>')
        ->toContain('<InkopTjanstAnnatEg>1000</InkopTjanstAnnatEg>')
        ->toContain('<InkopTjanstUtomEg>800</InkopTjanstUtomEg>')
        ->toContain('<InkopVaruSverige>400</InkopVaruSverige>')
        ->toContain('<InkopTjanstSverige>6000</InkopTjanstSverige>')
        ->toContain('<MomsUlagImport>10000</MomsUlagImport>')
        ->toContain('<ForsVaruAnnatEg>5000</ForsVaruAnnatEg>')
        ->toContain('<ForsVaruUtomEg>2000</ForsVaruUtomEg>')
        ->toContain('<ForsTjSkskAnnatEg>3000</ForsTjSkskAnnatEg>')
        ->toContain('<ForsTjOvrUtomEg>1000</ForsTjOvrUtomEg>')
        ->toContain('<ForsKopareSkskSverige>10000</ForsKopareSkskSverige>')
        ->toContain('<MomsUtgHog>2524</MomsUtgHog>')
        ->toContain('<MomsInkopUtgHog>2550</MomsInkopUtgHog>')
        ->toContain('<MomsInkopUtgMedel>120</MomsInkopUtgMedel>')
        ->toContain('<MomsImportUtgHog>2500</MomsImportUtgHog>')
        ->toContain('<MomsIngAvdr>6170</MomsIngAvdr>')
        ->toContain('<MomsBetala>1824</MomsBetala>');
});

it('maps the BAS VAT and sales accounts to Skatteverket\'s boxes', function (int $account, ?int $box) {
    expect(VatBoxes::boxFor($account))->toBe($box);
})->with([
    'utg moms 25 % → 10' => [2611, 10],
    'utg moms 12 % → 11' => [2621, 11],
    'utg moms 6 % → 12' => [2631, 12],
    'omvänd 25 % → 30' => [2614, 30],
    'omvänd 12 % → 31' => [2624, 31],
    'omvänd 6 % → 32' => [2634, 32],
    'import 25 % → 60' => [2615, 60],
    'import 12 % → 61' => [2625, 61],
    'import 6 % → 62' => [2635, 62],
    'debiterad ing moms → 48' => [2641, 48],
    'beräknad ing moms utland → 48' => [2645, 48],
    'ing moms omvänd Sverige → 48' => [2647, 48],
    'varor EU → 20' => [4515, 20],
    'tjänster EU → 21' => [4535, 21],
    'tjänster utanför EU → 22' => [4531, 22],
    'varor Sverige omvänd → 23' => [4415, 23],
    'tjänster Sverige omvänd → 24' => [4425, 24],
    'import varor → 50' => [4545, 50],
    'varor EU momsfri → 35' => [3108, 35],
    'varor export → 36' => [3105, 36],
    'trepartshandel mellanman → 38' => [3107, 38],
    'tjänster EU → 39' => [3308, 39],
    'tjänster utanför EU → 40' => [3305, 40],
    'bygg omvänd försäljning → 41' => [3231, 41],
    'momsfri försäljning → 42' => [3004, 42],
    'försäljning 25 % → 05' => [3001, 5],
    'försäljning 12 % → 05' => [3002, 5],
    'försäljning 6 % → 05' => [3003, 5],
    'redovisningskonto 2650 → ingen ruta' => [2650, null],
]);

it('drops the öre of every box and computes box 49 from the declared boxes, also for a refund', function () {
    // Input VAT 1 000,75 against output VAT 250,60: refund. Boxes: 10 = 250, 48 = 1 000, 49 = −750.
    momsAuditBook($this->owner, $this->company, '2026-02-02', [
        ['account' => 1930, 'debit_ore' => 125_310],
        ['account' => 3001, 'credit_ore' => 100_250],
        ['account' => 2611, 'credit_ore' => 25_060],
    ]);
    momsAuditBook($this->owner, $this->company, '2026-02-03', [
        ['account' => 5410, 'debit_ore' => 400_300],
        ['account' => 2641, 'debit_ore' => 100_075],
        ['account' => 1930, 'credit_ore' => 500_375],
    ]);

    $boxes = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    expect($boxes[5])->toBe(1_002)
        ->and($boxes[10])->toBe(250)
        ->and($boxes[48])->toBe(1_000)
        ->and($boxes[49])->toBe(-750);
});

it('splits a calendar year into the redovisningsperioder of SFL 26 kap.', function (VatPeriod $period, array $expected) {
    $periods = array_map(fn (array $p): string => $p['start']->toDateString().'/'.$p['end']->toDateString(), VatPeriods::of($this->year, $period));

    expect($periods)->toBe($expected);
})->with([
    'kvartal' => [VatPeriod::Quarterly, ['2026-01-01/2026-03-31', '2026-04-01/2026-06-30', '2026-07-01/2026-09-30', '2026-10-01/2026-12-31']],
    'helår' => [VatPeriod::Yearly, ['2026-01-01/2026-12-31']],
    'ej registrerad' => [VatPeriod::None, []],
]);

it('gives the deadlines Skatteverket publishes, moved to the next weekday', function (VatPeriod $period, string $end, bool $large, ?string $deadline) {
    expect(VatDeadlines::for($period, CarbonImmutable::parse($end), $large)?->toDateString())->toBe($deadline);
})->with([
    'månad ≤ 40 mkr, januari → 12 mars' => [VatPeriod::Monthly, '2026-01-31', false, '2026-03-12'],
    'månad ≤ 40 mkr, juni → 17 augusti' => [VatPeriod::Monthly, '2026-06-30', false, '2026-08-17'],
    'månad ≤ 40 mkr, november → 17 jan (söndag) → 18 jan' => [VatPeriod::Monthly, '2026-11-30', false, '2027-01-18'],
    'månad > 40 mkr, november → 27 dec (söndag) → 28 dec' => [VatPeriod::Monthly, '2026-11-30', true, '2026-12-28'],
    'månad > 40 mkr, januari → 26 feb' => [VatPeriod::Monthly, '2026-01-31', true, '2026-02-26'],
    'kvartal 1 → 12 maj' => [VatPeriod::Quarterly, '2026-03-31', false, '2026-05-12'],
    'kvartal 2 → 17 augusti' => [VatPeriod::Quarterly, '2026-06-30', false, '2026-08-17'],
    'kvartal 4 → 12 februari' => [VatPeriod::Quarterly, '2026-12-31', false, '2027-02-12'],
]);

it('gives the yearly deadline (12 maj / 26 feb with EU trade for enskild firma)', function () {
    // SFL 26 kap. 33 a §; with EU trade 33 § (AUD-MOMS-9, #60). More cases in tests/Unit/Vat/VatDeadlinesTest.php.
    expect(VatDeadlines::for(VatPeriod::Yearly, CarbonImmutable::parse('2026-12-31'), false, LegalForm::Ef)?->toDateString())->toBe('2027-05-12')
        ->and(VatDeadlines::for(VatPeriod::Yearly, CarbonImmutable::parse('2026-12-31'), false, LegalForm::Ef, euTrade: true)?->toDateString())->toBe('2027-02-26')
        ->and(VatDeadlines::for(VatPeriod::Yearly, CarbonImmutable::parse('2026-12-31'), false, LegalForm::Ab, euTrade: true)?->toDateString())->toBe('2027-02-26');
});

it('keeps vilande moms out of the declaration and the settlement', function () {
    // Output VAT parked on 2618 until payment (e.g. kontantmetoden a-conto) is not yet due.
    momsAuditAccount(2618, 'Vilande utgående moms, 25 %', 'LIABILITY_EQUITY');
    momsAuditBook($this->owner, $this->company, '2026-01-15', [
        ['account' => 1510, 'debit_ore' => 125_000],
        ['account' => 3001, 'credit_ore' => 100_000],
        ['account' => 2618, 'credit_ore' => 25_000],
    ]);
    momsAuditBook($this->owner, $this->company, '2026-01-16', [
        ['account' => 1930, 'debit_ore' => 125_000],
        ['account' => 3001, 'credit_ore' => 100_000],
        ['account' => 2611, 'credit_ore' => 25_000],
    ]);

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    $lines = $report->voucher()->with('rows.account')->sole()->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // Only the 250 kr on 2611 is declared and settled; 2618 keeps its 250 kr, nothing goes to 3740.
    expect($report->boxes[49])->toBe(250)
        ->and($lines)->toEqual([2611 => 25_000, 2650 => -25_000]);

    // ML 7 kap. 14 §: the parked VAT is not due yet, so it stays on 2618 and is listed as not declared (AUD-MOMS-1, #58).
    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));
    $account = Account::query()->where('number', 2618)->sole();

    expect((int) DB::table('voucher_rows')->where('account_id', $account->id)->sum(DB::raw('debit_ore - credit_ore')))->toBe(-25_000)
        ->and($return->unsettled)->toBe([['number' => 2618, 'name' => 'Vilande utgående moms, 25 %', 'amount' => -25_000]]);
});

it('does not deduct vilande ingående moms in box 48', function () {
    momsAuditAccount(2648, 'Vilande ingående moms', 'ASSET');
    momsAuditBook($this->owner, $this->company, '2026-01-15', [
        ['account' => 4010, 'debit_ore' => 100_000],
        ['account' => 2648, 'debit_ore' => 25_000],
        ['account' => 2440, 'credit_ore' => 125_000],
    ]);

    $boxes = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    expect($boxes[48])->toBe(0);
});

it('puts every taxable sale in box 05, not only accounts 3000–3099', function () {
    // A taxable sale on 3990 (Övriga ersättningar och intäkter) with output VAT 25 %.
    momsAuditBook($this->owner, $this->company, '2026-02-10', [
        ['account' => 1930, 'debit_ore' => 125_000],
        ['account' => 3990, 'credit_ore' => 100_000],
        ['account' => 2611, 'credit_ore' => 25_000],
    ]);

    $boxes = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    // Skatteverket fält 05: all taxable sales in Sweden excluding VAT, whatever the account (AUD-MOMS-3, #61).
    expect($boxes[10])->toBe(250)
        ->and($boxes[5])->toBe(1_000);
});

it('places a sale by its VAT rate, not only by the revenue account chosen', function () {
    $customer = momsAuditCustomer($this->owner, $this->company, ['name' => 'Kund i Sverige AB']);

    // A VAT-exempt row (0 %) whose account is the 25 % sales account.
    momsAuditInvoice($this->owner, $this->company, $customer, '2026-02-10', [['description' => 'Momsfri tjänst', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 0, 'account' => 3001]]);

    $boxes = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    // Fält 42: sales exempt from VAT that belong in no other box; a sale without VAT is not taxable (AUD-MOMS-4, #62).
    expect($boxes[5])->toBe(0)
        ->and($boxes[42])->toBe(1_000);
});

it('uses 6 % for food from 2026-04-01 to 2027-12-31 and 12 % for restaurant service', function () {
    // An article sold as food (livsmedel) on 2026-05-01 must carry 6 %; the same food served at a restaurant 12 %.
})->todo('AUD-MOMS-2: rates are fixed per article/row; NONOX has no notion of livsmedel vs restaurang and no date-dependent rate, so food articles set to 12 % before 2026-04-01 keep 12 % (and must go back to 12 % on 2028-01-01). Bet. 2025/26:SkU9, ML 9 kap.');

it('reports a calendar quarter as one return when the fiscal year is broken', function () {
    $year = new FiscalYear(['start_date' => '2026-05-01', 'end_date' => '2027-04-30']);
    $periods = array_map(fn (array $p): string => $p['start']->toDateString().'/'.$p['end']->toDateString(), VatPeriods::of($year, VatPeriod::Quarterly));

    // SFL 26 kap. 11 §: the period is the calendar quarter (AUD-MOMS-6, #59).
    expect($periods[0])->toBe('2026-04-01/2026-06-30')
        ->and(end($periods))->toBe('2027-04-01/2027-06-30');
});

it('settles VAT booked in a period after it was settled', function () {
    momsAuditBook($this->owner, $this->company, '2026-01-16', [
        ['account' => 1930, 'debit_ore' => 125_000],
        ['account' => 3001, 'credit_ore' => 100_000],
        ['account' => 2611, 'credit_ore' => 25_000],
    ]);
    app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));

    // A late receipt dated in the settled quarter.
    momsAuditBook($this->owner, $this->company, '2026-02-01', [
        ['account' => 1930, 'debit_ore' => 12_500],
        ['account' => 3001, 'credit_ore' => 10_000],
        ['account' => 2611, 'credit_ore' => 2_500],
    ]);

    // A supplementary settlement moves the 25 kr to 2650: 2611 ends at zero (AUD-MOMS-5, #63).
    $supplement = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    $account = Account::query()->where('number', 2611)->sole();
    $balance = (int) DB::table('voucher_rows')->where('account_id', $account->id)->sum(DB::raw('debit_ore - credit_ore'));

    expect($balance)->toBe(0)
        ->and($supplement->version)->toBe(2)
        ->and($supplement->boxes[49])->toBe(275);
});

it('lets a company choose yearly VAT only with a taxable base up to 1 million kronor', function () {})->todo('AUD-MOMS-8: vat_period YEARLY is accepted for any company; Skatteverket allows yearly only up to 1 mkr and quarterly up to 40 mkr (SFL 26 kap. 10–11 §§). Low impact: Skatteverket decides the period at registration.');

it('supports boxes 06, 07, 08 and 37', function () {})->todo('AUD-MOMS-7: no account feeds box 06 (uttag), 07 (vinstmarginal), 08 (frivillig skattskyldighet uthyrning) or 37 (mellanmans inköp, trepartshandel); 2612/2613/2616 VAT reaches box 10 but its base lands in 05 or nowhere.');

it('lists the quarter\'s VAT-free EU sales per buyer in the periodisk sammanställning (ML 2023:200, SFL 35 kap.)', function () {
    momsAuditQuarter($this->owner, $this->company);

    $list = EuSalesList::for(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));

    // Goods to DE123456789 5 000 kr (box 35), services to DE987654321 3 000 kr (box 39); export and construction are not on the list.
    expect($list->rows)->toBe([
        'DE123456789' => ['goods' => 500_000, 'triangular' => 0, 'services' => 0],
        'DE987654321' => ['goods' => 0, 'triangular' => 0, 'services' => 300_000],
    ])
        ->and($list->periodCode())->toBe('26-1')
        ->and($list->totals()['goods'] / 100)->toEqual(VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes()[35])
        ->and($list->totals()['services'] / 100)->toEqual(VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes()[39]);
});
