<?php

declare(strict_types=1);

/*
 * Legal audit, area "bokslut" (2026-11-02): one test per requirement that
 * the code meets or misses. A test marked ->todo('AUD-BOK-n: ...') shows a
 * deviation described in the audit report; its body is what should hold
 * once the deviation is fixed. Expected values are computed by hand.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\VatPeriod;
use App\Domain\IncomeTax\Ink2;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0001'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->closing = app(YearEndClosing::class);

    // Share capital 25 000 and retained earnings 50 000 in the bank; a profit of 80 000 in 2026.
    foreach ([1930 => 7_500_000, 2081 => -2_500_000, 2091 => -5_000_000] as $number => $ore) {
        OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => audBokFindingsAccount($number)->id, 'amount_ore' => $ore]);
    }

    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
});

function audBokFindingsAccount(int $number): Account
{
    return Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
}

/** Books tax 16 480 (80 000 × 20,6 %), the result and closes 2026. Free equity: 50 000 + 63 520 = 113 520. */
function audBokFindingsClose(): FiscalYear
{
    $bank = Account::query()->where('number', 1930)->firstOrFail();
    app(BankReconciliations::class)->save(test()->owner, test()->company, $bank, '2026-12-31', '155 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        test()->closing->check(test()->owner, test()->year, $key, true);
    }

    test()->closing->bookTax(test()->owner, test()->year, '16 480');
    test()->closing->bookResult(test()->owner, test()->year);
    test()->closing->close(test()->owner, test()->year);

    return FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();
}

it('names the beskattningsperiod by the last day of the fiscal year (SKV field table INK2_SKV2002-34-01-26-04: P1 0101–0430, P2 0501–0630, P3 0701–0831, P4 0901–1231)', function () {
    $period = fn (string $start, string $end): string => Ink2::for($this->company, new FiscalYear(['start_date' => $start, 'end_date' => $end]), null)->period();

    expect($period('2025-05-01', '2026-04-30'))->toBe('2026P1')
        ->and($period('2025-07-01', '2026-06-30'))->toBe('2026P2')
        ->and($period('2025-09-01', '2026-08-31'))->toBe('2026P3')
        ->and($period('2025-09-01', '2026-09-30'))->toBe('2026P4')
        ->and($period('2026-01-01', '2026-12-31'))->toBe('2026P4');
});

it('reports the book result after tax on INK2R 3.26 and adds the tax back on INK2S 4.3a (SKV INK2R/INK2S 2026P4)', function () {
    $this->closing->bookTax($this->owner, $this->year, '16 480');
    $ink2 = Ink2::for($this->company, $this->year, null);

    // 100 000 - 20 000 - 16 480 = 63 520; taxable 63 520 + 16 480 = 80 000.
    expect($ink2->ink2r())->toMatchArray([7450 => 63_520, 7528 => 16_480, 7302 => 113_520])
        ->and($ink2->ink2s())->toMatchArray([7650 => 63_520, 7651 => 16_480, 7670 => 80_000]);
});

it('balances the K2 balance sheet with the year\'s result whether or not it is booked to 2099 (ÅRL 3 kap. 3 §)', function () {
    $before = AnnualAccounts::for($this->year)->balanceSheet();
    $this->closing->bookTax($this->owner, $this->year, '16 480');
    $this->closing->bookResult($this->owner, $this->year);
    $after = AnnualAccounts::for($this->year)->balanceSheet();

    // Assets: bank 75 000 + 100 000 - 20 000 = 155 000.
    expect($before['assets'])->toBe(15_500_000)
        ->and($before['equity_liabilities'])->toBe(15_500_000)
        ->and($after['assets'])->toBe(15_500_000)
        ->and($after['equity_liabilities'])->toBe(15_500_000)
        ->and($after['equity'])->toBe(2_500_000 + 5_000_000 + 6_352_000);
});

it('AUD-BOK-2: refuses a proposed dividend larger than the free equity (ABL 17 kap. 3 §)', function () {
    $this->closing->bookTax($this->owner, $this->year, '16 480');
    $this->closing->bookResult($this->owner, $this->year);

    // Free equity 113 520; a dividend of 200 000 would eat into the restricted equity.
    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => '200 000', 'average_employees' => 0]))
        ->toThrow(ValidationException::class);

    // Exactly the free equity is allowed and leaves 0 to carry forward (#84).
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => '113 520', 'average_employees' => 0]);

    expect(AnnualReportDocument::data($this->company, $this->year, $report)['disposition'])->toBe(['free' => 11_352_000, 'dividend' => 11_352_000, 'carried' => 0]);
});

it('AUD-BOK-3: refuses to book a dividend larger than the free equity in the disposition (ABL 17 kap. 3 §)', function () {
    audBokFindingsClose();

    expect(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-20', '200 000'))
        ->toThrow(ValidationException::class, 'Aktiebolagslagen 17 kap. 3 §')
        ->and(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-20', '113 520,01'))
        ->toThrow(ValidationException::class);

    // The whole free equity, 113 520, may be distributed (#85): 2099 debit 63 520,
    // 2098 debit 50 000 (113 520 - 63 520), 2898 credit 113 520.
    $entry = $this->closing->dispose($this->owner, $this->year, '2027-05-20', '113 520');
    $rows = Voucher::query()->with('rows.account')->findOrFail($entry->voucher_id)->rows
        ->mapWithKeys(fn ($row): array => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    expect($rows)->toEqualCanonicalizing([2099 => 6_352_000, 2098 => 5_000_000, 2898 => -11_352_000]);
});

it('AUD-BOK-4: puts 2420 Förskott från kunder and 2480 Checkräkningskredit on their own K2 balance sheet lines (BFNAR 2016:10 uppställningsform balansräkning, ÅRL bilaga 1 D, #88)', function () {
    Bookkeeping::post($this->owner, $this->company, 500_000, 1930, audBokFindingsAccount(2420)->number, '2026-12-01');
    Bookkeeping::post($this->owner, $this->company, 700_000, 1930, audBokFindingsAccount(2480)->number, '2026-12-02');
    Bookkeeping::post($this->owner, $this->company, 300_000, 1930, audBokFindingsAccount(2440)->number, '2026-12-03');
    $short = collect(AnnualAccounts::for($this->year)->balanceSheet()['sections'])->firstWhere('key', 'short_term');
    $lines = collect($short['lines'])->keyBy('key');

    // 5 000 is an advance from customers, not a debt to a credit institution;
    // 7 000 is an overdraft at a bank (K2: Checkräkningskredit), not a debt to
    // a supplier; only 3 000 on 2440 is a trade payable. Sum 15 000.
    expect($lines['overdraft']['amount'] ?? 0)->toBe(700_000)
        ->and($lines['customer_advances']['amount'] ?? 0)->toBe(500_000)
        ->and($lines['trade_payables']['amount'] ?? 0)->toBe(300_000)
        ->and($lines->has('credit_institutions'))->toBeFalse()
        ->and($short['amount'])->toBe(1_500_000);
});

it('#88: puts 1120 Förbättringsutgifter på annans fastighet and 1280 Pågående nyanläggningar on their own K2 lines (BFNAR 2016:10 uppställningsform balansräkning, ÅRL bilaga 1 A II)', function () {
    Bookkeeping::post($this->owner, $this->company, 200_000, audBokFindingsAccount(1120)->number, 1930, '2026-12-01');
    Bookkeeping::post($this->owner, $this->company, 900_000, audBokFindingsAccount(1280)->number, 1930, '2026-12-02');
    Bookkeeping::post($this->owner, $this->company, 400_000, audBokFindingsAccount(1220)->number, 1930, '2026-12-03');
    $fixed = collect(collect(AnnualAccounts::for($this->year)->balanceSheet()['sections'])->firstWhere('key', 'fixed')['lines'])->keyBy('key');

    // 2 000 + 9 000 + 4 000 = 15 000 of fixed assets, on three lines.
    expect($fixed['improvements']['amount'])->toBe(200_000)
        ->and($fixed['construction_in_progress']['amount'])->toBe(900_000)
        ->and($fixed['machinery']['amount'])->toBe(400_000)
        ->and($fixed->sum('amount'))->toBe(1_500_000);
});

it('AUD-BOK-5: specifies every change in equity, the dividend included, in the förvaltningsberättelse (ÅRL 6 kap. 2 § andra stycket, BFNAR 2016:10 kap. 5)', function () {
    $next = audBokFindingsClose();
    $this->closing->dispose($this->owner, $this->year, '2027-05-20', '30 000');
    $labels = collect(AnnualAccounts::for($next)->equityChanges())->pluck('label')->implode(' ');

    expect(mb_strtolower($labels))->toContain('utdelning');
});

it('AUD-BOK-6: rounds amounts to whole kronor rather than cutting the öre off (BFNAR 2016:10 p. 3.9)', function () {
    Bookkeeping::post($this->owner, $this->company, 99, 1930, 3001, '2026-05-01');
    $html = AnnualReportDocument::html($this->company, $this->year, new AnnualReport(['fiscal_year_id' => $this->year->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]));

    // Net sales 100 000,99 kr is 100 001 kr in whole kronor, not 100 000.
    expect($html)->toContain('100'."\u{a0}".'001');
})->todo('AUD-BOK-6: the annual report view uses intdiv($ore, 100), which truncates');

it('AUD-BOK-8: adds the schablonintäkt on periodiseringsfonder to INK2S 4.6a (field 7654) when the company has funds at the start of the year (IL 30 kap. 6 a §)', function () {
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => audBokFindingsAccount(2125)->id, 'amount_ore' => -10_000_000]);
    OpeningBalance::query()->where('account_id', Account::query()->where('number', 2091)->value('id'))->update(['amount_ore' => 5_000_000]);

    // 100 000 × 2,55 % (statslåneräntan end of November 2025, for a tax year
    // ending 2026) × 12/12 = 2 550; taxable 80 000 + 2 550 = 82 550 (#83).
    expect(Ink2::for($this->company, $this->year, null)->ink2s())->toMatchArray([7650 => 80_000, 7654 => 2_550, 7670 => 82_550])
        ->and(Ink2::for($this->company, $this->year, null)->ink2())->toMatchArray([7104 => 82_550]);
});

it('AUD-BOK-9: reports a change of stock of finished goods on INK2R 3.2 (7411/7510), not as raw materials (SKV INK2R 2026P4)', function () {
    Bookkeeping::post($this->owner, $this->company, 400_000, audBokFindingsAccount(1460)->number, audBokFindingsAccount(4950)->number, '2026-12-31');

    expect(Ink2::for($this->company, $this->year, null)->ink2r())->toHaveKey(7411)
        ->and(Ink2::for($this->company, $this->year, null)->ink2r())->not->toHaveKey(7511);
});

it('AUD-BOK-10: includes a fastställelseintyg for the copy filed with Bolagsverket (ÅRL 8 kap. 3 §)', function () {
    $html = AnnualReportDocument::html($this->company, $this->year, new AnnualReport(['fiscal_year_id' => $this->year->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]));

    expect($html)->toContain('Fastställelseintyg');
})->todo('AUD-BOK-10: the annual report document has no fastställelseintyg');

it('AUD-BOK-11: offers the K2 notes on ställda säkerheter, eventualförpliktelser and events after the year (ÅRL 5 kap. 14–16, 22 §; BFNAR 2016:10 p. 18.13–18.22)', function () {
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'pledged_assets' => 'Företagsinteckning 500 000 kr']);
    $html = AnnualReportDocument::html($this->company, $this->year, $report);

    expect($html)->toContain('Ställda säkerheter');
})->todo('AUD-BOK-11: the annual report has no way to enter or show these notes');

it('AUD-BOK-12: asks for a comment when net sales vary more than 30 % between years in the multi-year overview (BFNAR 2016:10 p. 5.5, BFNAR 2025:2)', function () {
    $previous = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    $html = AnnualReportDocument::html($this->company, $this->year, new AnnualReport(['fiscal_year_id' => $this->year->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]));

    // 2025 had no sales and 2026 has 100 000: the variation is over 30 %.
    expect($previous->exists)->toBeTrue()
        ->and($html)->toContain('Nettoomsättningen har förändrats');
})->todo('AUD-BOK-12: no comment or warning for a net sales variation over 30 % (applies to fiscal years starting after 2025-12-31)');

it('AUD-BOK-13: lets the disposition pay a dividend from retained earnings after a loss year (ABL 17 kap. 3 §, 18 kap. 1 §)', function () {
    Bookkeeping::post($this->owner, $this->company, 12_000_000, 5010, 1930, '2026-11-01');
    // Loss 40 000; free equity 50 000 - 40 000 = 10 000, so 5 000 may be distributed.
    $bank = Account::query()->where('number', 1930)->firstOrFail();
    app(BankReconciliations::class)->save($this->owner, $this->company, $bank, '2026-12-31', '35 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        $this->closing->check($this->owner, $this->year, $key, true);
    }

    $this->closing->bookTax($this->owner, $this->year, '0');
    $this->closing->bookResult($this->owner, $this->year);
    $this->closing->close($this->owner, $this->year);

    expect(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-20', '10 000,01'))->toThrow(ValidationException::class);

    $entry = $this->closing->dispose($this->owner, $this->year, '2027-05-20', '5 000');
    $rows = Voucher::query()->with('rows.account')->findOrFail($entry->voucher_id)->rows
        ->mapWithKeys(fn ($row): array => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    // 2099 credit 40 000 (the loss leaves it); 2098 debit 40 000 + 5 000 = 45 000; 2898 credit 5 000 (#86).
    expect($entry->dividend_ore->ore)->toBe(500_000)
        ->and($rows)->toEqualCanonicalizing([2099 => -4_000_000, 2098 => 4_500_000, 2898 => -500_000]);
});

it('AUD-BOK-14: fills INK2 1.4 särskild löneskatt and INK2S 4.21 from booked pension costs (SKV INK2 / INK2S 2026P4, fields 7132 and 8022)', function () {
    Bookkeeping::post($this->owner, $this->company, 1_000_000, audBokFindingsAccount(7412)->number, 1930, '2026-06-01');

    // 10 000 on 7412 is proposed for INK2S 4.21 and INK2 1.4 (#87).
    expect(Ink2::for($this->company, $this->year, null)->ink2s())->toMatchArray([8022 => 10_000])
        ->and(Ink2::for($this->company, $this->year, null)->ink2())->toMatchArray([7132 => 10_000]);
});
