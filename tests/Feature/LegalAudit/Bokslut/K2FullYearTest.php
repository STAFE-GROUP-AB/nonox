<?php

declare(strict_types=1);

/*
 * Legal audit, area "bokslut" (2026-11-02): one full fiscal year for a small
 * limited company under K2, booked through the real actions, with every
 * expected figure computed by hand below. Nothing here is printed by the code
 * and copied back; if a figure changes, the books or the law changed.
 *
 * Hand computation (kronor):
 *   Opening balances 2026-01-01: 1930 200 000, 2081 -25 000, 2091 -175 000.
 *   Sales 500 000 + VAT 125 000; goods 100 000 + VAT 25 000; computer 36 000
 *   + VAT 9 000 (36 months, 1 000 a month); payroll gross 30 000, tax 9 000,
 *   employer contributions 9 426 (test data, not a legal rate); rent 60 000
 *   for Oct 2026–Sep 2027 spread over 12 months (15 000 belongs to 2026);
 *   electricity 3 000 accrued for December; non-deductible cost 2 000 (6992);
 *   interest income 1 433.
 *   Result after financial items: 500 000 - 100 000 - 20 000 - 39 426
 *   - 12 000 + 1 433 = 330 007.
 *   Taxable income (INK2S 4.15): 330 007 + 2 000 (4.3c) = 332 007.
 *   IL 1 kap. 7 §: rounded down to whole tens of kronor = 332 000.
 *   IL 65 kap. 14 § / skatteverket.se (aktiebolag): 20,6 % = 68 392.
 *   Year's result: 330 007 - 68 392 = 261 615.
 *   Bank 2026-12-31: 200 000 + 625 000 - 125 000 - 45 000 - 21 000 - 60 000
 *   - 2 000 + 1 433 = 573 433.
 *   Balance sheet total: 24 000 + 45 000 + 573 433 = 642 433
 *   = equity 461 615 + tax 68 392 + other 109 426 (VAT 91 000, 9 000, 9 426)
 *   + accrued 3 000.
 *   Soliditet (BFNAR 2016:10 p. 5.5): 461 615 / 642 433 = 71,85 % → 71,9.
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Accruals\Accruals;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Assets\FixedAssets;
use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Actions\Vat\SettleVat;
use App\Domain\Accounting\AccountType;
use App\Domain\Accruals\AccrualKind;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Assets\AssetCategory;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\VatPeriod;
use App\Domain\IncomeTax\Ink2;
use App\Domain\Insights\BusinessKpis;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

function audBokAccount(int $number): Account
{
    return Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
}

/** @param  array<int, int>  $rows  account => öre, debit positive */
function audBokPost(User $user, Company $company, string $date, string $text, array $rows): Voucher
{
    foreach (array_keys($rows) as $number) {
        audBokAccount($number);
    }

    $draft = app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => $date,
        'description' => $text,
        'series' => 'A',
        'rows' => array_map(fn (int $account, int $ore): array => ['account' => $account, 'debit_ore' => max($ore, 0), 'credit_ore' => max(-$ore, 0)], array_keys($rows), $rows),
    ]);

    return app(PostVoucher::class)($user, $draft);
}

function audBokOpening(FiscalYear $year, int $number, int $ore): void
{
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => audBokAccount($number)->id, 'amount_ore' => $ore]);
}

/** @param  list<array{key: string, amount: int}>  $lines */
function audBokLine(array $lines, string $key): ?int
{
    return collect($lines)->firstWhere('key', $key)['amount'] ?? null;
}

/** Books the whole year up to, but not including, the tax and the result. */
function audBokBookYear(User $owner, Company $company, FiscalYear $year): void
{
    audBokOpening($year, 1930, 20_000_000);
    audBokOpening($year, 2081, -2_500_000);
    audBokOpening($year, 2091, -17_500_000);

    audBokPost($owner, $company, '2026-01-15', 'Dator', [1250 => 3_600_000, 2640 => 900_000, 1930 => -4_500_000]);
    audBokPost($owner, $company, '2026-03-15', 'Försäljning', [1510 => 62_500_000, 3001 => -50_000_000, 2611 => -12_500_000]);
    audBokPost($owner, $company, '2026-04-15', 'Inbetalning', [1930 => 62_500_000, 1510 => -62_500_000]);
    audBokPost($owner, $company, '2026-05-10', 'Varuinköp', [4010 => 10_000_000, 2640 => 2_500_000, 2440 => -12_500_000]);
    audBokPost($owner, $company, '2026-05-30', 'Betalning leverantör', [2440 => 12_500_000, 1930 => -12_500_000]);
    audBokPost($owner, $company, '2026-06-25', 'Lön juni', [7010 => 3_000_000, 7510 => 942_600, 2710 => -900_000, 2731 => -942_600, 1930 => -2_100_000]);
    audBokPost($owner, $company, '2026-08-01', 'Ej avdragsgill kostnad', [6992 => 200_000, 1930 => -200_000]);
    audBokPost($owner, $company, '2026-10-01', 'Hyra okt 2026–sep 2027', [5010 => 6_000_000, 1930 => -6_000_000]);
    audBokPost($owner, $company, '2026-11-30', 'Ränta', [1930 => 143_300, 8310 => -143_300]);

    // The fixed asset register: 36 000 kr over 36 months from January.
    audBokAccount(7835);
    app(FixedAssets::class)->save($owner, $company, [
        'name' => 'Dator', 'category' => AssetCategory::Computers->value, 'acquired_on' => '2026-01-15',
        'acquisition' => '36 000', 'life_months' => 36, 'depreciation_start' => '2026-01-01',
        'asset_account' => 1250, 'accumulated_account' => 1259, 'expense_account' => 7835,
    ]);

    for ($month = 1; $month <= 12; $month++) {
        app(FixedAssets::class)->depreciate($owner, $company, sprintf('2026-%02d', $month));
    }

    // Accruals: the prepaid rent and December's electricity.
    audBokAccount(5020);
    $accruals = app(Accruals::class);
    $accruals->save($owner, $company, ['name' => 'Hyra', 'kind' => AccrualKind::PrepaidExpense->value, 'total' => '60 000', 'balance_account' => 1790, 'result_account' => 5010, 'start_month' => '2026-10', 'months' => 12, 'move_from_result' => true]);
    $accruals->save($owner, $company, ['name' => 'El december', 'kind' => AccrualKind::AccruedExpense->value, 'total' => '3 000', 'balance_account' => 2990, 'result_account' => 5020, 'start_month' => '2026-12', 'months' => 1]);

    foreach (['2026-10', '2026-11', '2026-12'] as $month) {
        $accruals->run($owner, $company, $month);
    }

    // VAT for the year (yearly period), then the bank and the manual points.
    app(SettleVat::class)($owner, $year, CarbonImmutable::parse('2026-01-01'));
    app(BankReconciliations::class)->save($owner, $company, Account::query()->where('number', 1930)->firstOrFail(), '2026-12-31', '573 433', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        app(YearEndClosing::class)->check($owner, $year, $key, true);
    }

    app(IncomeTaxReturns::class)->save($owner, $year, ['7653' => '2 000'], false, false);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill([
        'vat_registered' => true, 'vat_period' => VatPeriod::Yearly,
        'org_number' => '556000-0001', 'legal_name' => 'Granskningsbolaget AB', 'postal_code' => '123 45', 'city' => 'Teststad',
    ])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->closing = app(YearEndClosing::class);

    audBokBookYear($this->owner, $this->company, $this->year);
});

it('computes INK2S taxable income as book result plus tax plus non-deductible costs (INK2S 4.1–4.15, SKV field table INK2S_SKV2002-34-01-26-04)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->period())->toBe('2026P4')
        ->and($ink2->ink2s())->toMatchArray([7011 => '20260101', 7012 => '20261231', 7650 => 261_615, 7651 => 68_392, 7653 => 2_000, 7670 => 332_007, 8041 => 'X', 8045 => 'X'])
        ->and($ink2->ink2())->toMatchArray([7104 => 332_007])
        ->and($ink2->taxableKronor())->toBe(332_007);
});

it('fills INK2R (balance and income statement) in whole kronor from the books (SKV field table INK2R_SKV2002-34-01-26-04)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $fields = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole())->ink2r();

    expect($fields)->toMatchArray([
        7215 => 24_000,    // 2.4 maskiner och inventarier: 36 000 - 12 000
        7263 => 45_000,    // 2.23 förutbetalda kostnader: 9 of 12 months rent
        7281 => 573_433,   // 2.26 kassa och bank
        7301 => 25_000,    // 2.27 bundet eget kapital
        7302 => 436_615,   // 2.28 fritt eget kapital: 175 000 + 261 615
        7368 => 68_392,    // 2.49 skatteskulder (2512)
        7369 => 109_426,   // 2.48 övriga skulder: 2650 91 000, 2710 9 000, 2731 9 426
        7370 => 3_000,     // 2.50 upplupna kostnader
        7410 => 500_000,   // 3.1
        7511 => 100_000,   // 3.5
        7513 => 20_000,    // 3.7: rent 15 000, el 3 000, 6992 2 000
        7514 => 39_426,    // 3.8
        7515 => 12_000,    // 3.9
        7417 => 1_433,     // 3.16
        7528 => 68_392,    // 3.25
        7450 => 261_615,   // 3.26
    ])->and($fields)->not->toHaveKey(7550);

    // The balance sheet side adds up: assets = equity + liabilities.
    expect($fields[7215] + $fields[7263] + $fields[7281])
        ->toBe($fields[7301] + $fields[7302] + $fields[7368] + $fields[7369] + $fields[7370]);
});

it('exports SRU with INK2, INK2R and INK2S blocks in ISO 8859-1, LF and #FIL_SLUT (Teknisk information om filöverföring, skatteverket.se)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $zipContent = app(IncomeTaxReturns::class)->sruZip($this->owner, $this->year);
    $path = (string) tempnam(sys_get_temp_dir(), 'audbok');
    file_put_contents($path, $zipContent);
    $zip = new ZipArchive;
    $zip->open($path);
    $blanketter = (string) $zip->getFromName('BLANKETTER.SRU');
    $zip->close();
    unlink($path);

    expect($blanketter)->not->toContain("\r")
        ->and($blanketter)->toContain('#IDENTITET 165560000001 ')
        ->and($blanketter)->toContain("#UPPGIFT 7104 332007\n")
        ->and($blanketter)->toContain("#UPPGIFT 7450 261615\n")
        ->and($blanketter)->toContain("#UPPGIFT 7670 332007\n")
        ->and(mb_check_encoding(mb_convert_encoding($blanketter, 'UTF-8', 'ISO-8859-1'), 'UTF-8'))->toBeTrue()
        ->and($blanketter)->toEndWith("#FIL_SLUT\n");
});

it('builds the K2 income statement and balance sheet that tie to the ledger (ÅRL 3 kap., bilaga 2–3; BFNAR 2016:10)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $this->closing->bookResult($this->owner, $this->year);
    $accounts = AnnualAccounts::for($this->year);
    $income = $accounts->incomeStatement();

    expect(audBokLine($income, 'net_sales'))->toBe(50_000_000)
        ->and(audBokLine($income, 'raw_materials'))->toBe(-10_000_000)
        ->and(audBokLine($income, 'other_external'))->toBe(-2_000_000)
        ->and(audBokLine($income, 'personnel'))->toBe(-3_942_600)
        ->and(audBokLine($income, 'depreciation'))->toBe(-1_200_000)
        ->and(audBokLine($income, 'operating_result'))->toBe(32_857_400)
        ->and(audBokLine($income, 'interest_income'))->toBe(143_300)
        ->and(audBokLine($income, 'after_financial'))->toBe(33_000_700)
        ->and(audBokLine($income, 'tax'))->toBe(-6_839_200)
        ->and(audBokLine($income, 'year_result'))->toBe(26_161_500)
        ->and($accounts->yearResult())->toBe(26_161_500);

    $sheet = $accounts->balanceSheet();
    $sections = collect($sheet['sections'])->keyBy('key');

    expect($sheet['assets'])->toBe(64_243_300)
        ->and($sheet['equity_liabilities'])->toBe(64_243_300)
        ->and($sheet['equity'])->toBe(46_161_500)
        ->and(audBokLine($sections['fixed']['lines'], 'machinery'))->toBe(2_400_000)
        ->and(audBokLine($sections['current']['lines'], 'prepaid'))->toBe(4_500_000)
        ->and(audBokLine($sections['current']['lines'], 'cash'))->toBe(57_343_300)
        ->and(audBokLine($sections['restricted']['lines'], 'share_capital'))->toBe(2_500_000)
        ->and(audBokLine($sections['free']['lines'], 'retained'))->toBe(17_500_000)
        ->and(audBokLine($sections['free']['lines'], 'year_result'))->toBe(26_161_500)
        ->and(audBokLine($sections['short_term']['lines'], 'tax_liabilities'))->toBe(6_839_200)
        ->and(audBokLine($sections['short_term']['lines'], 'other_liabilities'))->toBe(10_942_600)
        ->and(audBokLine($sections['short_term']['lines'], 'accrued'))->toBe(300_000)
        ->and($accounts->freeEquity())->toBe(43_661_500)
        // BFNAR 2016:10 p. 5.5: soliditet = justerat eget kapital / balansomslutning.
        ->and($accounts->solidity())->toBe(71.9);
});

it('notes the fixed asset with opening and closing acquisition values and the year\'s depreciation (ÅRL 5 kap. 8 §, BFNAR 2016:10 p. 18.10)', function () {
    [$note] = AnnualAccounts::for($this->year)->fixedAssetNotes();

    expect($note['acquisition'])->toBe(['opening' => 0, 'additions' => 3_600_000, 'disposals' => 0, 'closing' => 3_600_000])
        ->and($note['depreciation'])->toBe(['opening' => 0, 'disposals' => 0, 'charge' => 1_200_000, 'closing' => 1_200_000])
        ->and($note['book_value'])->toBe(2_400_000);
});

it('shows the multi-year overview for the year and the three before it (BFNAR 2016:10 p. 5.5)', function () {
    foreach ([2022, 2023, 2024, 2025] as $earlier) {
        FiscalYear::create(['start_date' => "{$earlier}-01-01", 'end_date' => "{$earlier}-12-31"]);
    }

    $rows = AnnualAccounts::for($this->year)->multiYear();

    expect(array_column($rows, 'label'))->toHaveCount(4)
        ->and($rows[0]['net_sales'])->toBe(50_000_000)
        ->and($rows[0]['after_financial'])->toBe(33_000_700)
        ->and($rows[3]['label'])->toBe(FiscalYear::query()->whereDate('start_date', '2023-01-01')->firstOrFail()->label());
});

it('proposes a disposition of the free equity in whole kronor in the annual report (ÅRL 6 kap. 2 §, BFNAR 2016:10 p. 3.9)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $this->closing->bookResult($this->owner, $this->year);
    app(SaveAnnualReport::class)($this->owner, $this->year, ['business_description' => 'Konsultverksamhet med säte i Teststad.', 'dividend' => '100 000', 'average_employees' => 1, 'signatories' => "Anna Andersson, styrelseledamot\nBo Berg, styrelseledamot"]);
    $data = AnnualReportDocument::data($this->company, $this->year, AnnualReport::query()->sole());

    expect($data['disposition'])->toBe(['free' => 43_661_500, 'dividend' => 10_000_000, 'carried' => 33_661_500]);

    $html = AnnualReportDocument::html($this->company, $this->year, AnnualReport::query()->sole());

    expect($html)->toContain('436'."\u{a0}".'615')
        ->and($html)->toContain('336'."\u{a0}".'615')
        ->and($html)->toContain('BFNAR 2016:10')
        ->and($html)->toContain('Anna Andersson');
});

it('closes the year only when the checklist is done, carries the balances and disposes of the result after the meeting (BFL 6 kap., ABL 7 kap. 11 §)', function () {
    expect(collect(ClosingChecklist::for($this->company, $this->year))->where('status', ClosingChecklist::TODO)->pluck('key')->values()->all())->toBe(['tax', 'result']);

    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $this->closing->bookResult($this->owner, $this->year);
    $this->closing->close($this->owner, $this->year);

    $next = FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();
    $opening = OpeningBalance::query()->where('fiscal_year_id', $next->id)->with('account')->get()
        ->mapWithKeys(fn (OpeningBalance $balance): array => [$balance->account->number => $balance->amount_ore->ore])->all();

    expect($opening)->toMatchArray([1930 => 57_343_300, 1250 => 3_600_000, 1259 => -1_200_000, 1790 => 4_500_000, 2081 => -2_500_000, 2091 => -17_500_000, 2099 => -26_161_500, 2512 => -6_839_200, 2650 => -9_100_000, 2990 => -300_000])
        ->and(array_sum($opening))->toBe(0)
        ->and($opening)->not->toHaveKeys([3001, 8999, 8910]);

    $this->closing->dispose($this->owner, $this->year, '2027-05-20', '100 000');
    $voucher = Voucher::query()->whereDate('voucher_date', '2027-05-20')->with('rows.account')->sole();
    $rows = $voucher->rows->mapWithKeys(fn ($row): array => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // 2099 debit 261 615; 2098 credit 161 615; 2898 credit 100 000.
    expect($rows)->toBe([2099 => 26_161_500, 2098 => -16_161_500, 2898 => -10_000_000]);
});

it('AUD-BOK-1: proposes the income tax from the taxable income, rounded down to tens, at 20,6 % (IL 1 kap. 7 §, IL 65 kap. 14 §)', function () {
    // Today the user types the tax; nothing computes 332 007 → 332 000 × 20,6 % = 68 392.
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->proposedTaxKronor())->toBe(68_392);
})->todo('AUD-BOK-1: no tax calculation; YearEndClosing::bookTax accepts any amount and is not checked against INK2 4.15');

it('AUD-BOK-7: shows the year\'s result on the overview also after it has been booked to 2099 (BusinessKpis)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $this->closing->bookResult($this->owner, $this->year);
    $kpis = app(BusinessKpis::class)->for($this->owner, $this->company, [BusinessKpis::LEDGER], CarbonImmutable::parse('2026-12-31'));
    $result = collect($kpis)->first(fn (array $kpi): bool => str_starts_with($kpi['label'], 'Resultat hittills'));

    expect($result['value']->ore)->toBe(26_161_500);
})->todo('AUD-BOK-7: BusinessKpis::RESULT is 3000–8999, so 8999 cancels the result to 0 once it is booked');

it('#83: adds the schablonintäkt on the periodiseringsfond at the start of the year to INK2S 4.6 a and 4.15 (IL 30 kap. 6 a §, SKV INK2S 2026P4 field 7654)', function () {
    // Move 50 000 of the opening retained earnings to a periodiseringsfond (2125).
    OpeningBalance::query()->where('fiscal_year_id', $this->year->id)->where('account_id', audBokAccount(2091)->id)->update(['amount_ore' => -12_500_000]);
    audBokOpening($this->year, 2125, -5_000_000);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    // 50 000 × 2,55 % (end of November 2025) × 12/12 = 1 275.
    // Taxable before the tax is booked: 330 007 + 2 000 (4.3c) + 1 275 (4.6a) = 333 282.
    expect($ink2->ink2s())->toMatchArray([7650 => 330_007, 7653 => 2_000, 7654 => 1_275, 7670 => 333_282])
        ->and($ink2->ink2())->toMatchArray([7104 => 333_282])
        ->and($ink2->ink2r())->toMatchArray([7321 => 50_000]);
});

it('#87: reports the pension costs in INK2S 4.21 and the basis for särskild löneskatt in INK2 1.4 (lag 1991:687, SKV INK2/INK2S 2026P4 fields 7132 and 8022)', function () {
    // A tjänstepension premium of 6 000 in December and its särskild löneskatt
    // 6 000 × 24,26 % = 1 455,60 on 7533/2514.
    audBokPost($this->owner, $this->company, '2026-12-20', 'Tjänstepension', [7412 => 600_000, 1930 => -600_000]);
    audBokPost($this->owner, $this->company, '2026-12-31', 'Särskild löneskatt', [7533 => 145_560, 2514 => -145_560]);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    // 3.8: 39 426 + 6 000 + 1 455,60 = 46 881,60 → 46 882.
    // Result before tax: 330 007 - 7 455,60 = 322 551,40 → 322 551; taxable 322 551 + 2 000 = 324 551.
    expect($ink2->ink2())->toMatchArray([7132 => 6_000, 7104 => 324_551])
        ->and($ink2->ink2s())->toMatchArray([8022 => 6_000, 7650 => 322_551, 7670 => 324_551])
        ->and($ink2->ink2r())->toMatchArray([7514 => 46_882]);
});

it('#84/#85: refuses a dividend above the free equity 436 615 in the proposal and in the disposition, and accepts exactly that (ABL 17 kap. 3 §)', function () {
    $this->closing->bookTax($this->owner, $this->year, '68 392');
    $this->closing->bookResult($this->owner, $this->year);

    // Free equity: retained 175 000 + the year's result 261 615 = 436 615.
    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => '436 616', 'average_employees' => 1]))
        ->toThrow(ValidationException::class);

    app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => '436 615', 'average_employees' => 1]);
    $this->closing->close($this->owner, $this->year);

    expect(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-20', '436 616'))
        ->toThrow(ValidationException::class);

    $this->closing->dispose($this->owner, $this->year, '2027-05-20', '436 615');
    $rows = Voucher::query()->whereDate('voucher_date', '2027-05-20')->with('rows.account')->sole()->rows
        ->mapWithKeys(fn ($row): array => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // 2099 debit 261 615; 2098 debit 175 000; 2898 credit 436 615. Free equity in 2027: 0.
    expect($rows)->toEqualCanonicalizing([2099 => 26_161_500, 2098 => 17_500_000, 2898 => -43_661_500]);
});
