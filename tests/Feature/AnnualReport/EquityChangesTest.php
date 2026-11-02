<?php

declare(strict_types=1);

/*
 * #90 (BS-AUD-BOK-5): the specification of changes in equity compared with
 * the previous year's balance sheet (ÅRL 6 kap. 2 § andra stycket, Lag
 * 2024:347; BFNAR 2016:10 kap. 4 uppställningsform förvaltningsberättelse).
 * Expected values computed by hand (kronor):
 *   2026: share capital 25 000, retained 50 000; sales 100 000, costs
 *   20 000, tax 16 480 → result 63 520. Equity 31 Dec 2026: 138 520.
 *   2027: the meeting on 20 May disposes of 63 520: dividend 30 000,
 *   33 520 carried forward. A new issue of 10 000 on 2081 in June.
 *   Equity 31 Dec 2027: 35 000 + 83 520 + 0 = 118 520
 *   = 138 520 - 30 000 + 10 000.
 */

use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\VatPeriod;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-06-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0001'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $closing = app(YearEndClosing::class);

    foreach ([1930 => 7_500_000, 2081 => -2_500_000, 2091 => -5_000_000] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
    app(BankReconciliations::class)->save($this->owner, $this->company, Account::query()->where('number', 1930)->firstOrFail(), '2026-12-31', '155 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        $closing->check($this->owner, $this->year, $key, true);
    }

    $closing->bookTax($this->owner, $this->year, '16 480');
    $closing->bookResult($this->owner, $this->year);
    $closing->close($this->owner, $this->year);
    $this->next = FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();
});

/** @return array<string, array{amounts: array<string, int>, total: int}> */
function equityRows(FiscalYear $year): array
{
    return collect(AnnualAccounts::for($year)->equityChanges())->keyBy('key')->all();
}

it('specifies the dividend, the amount carried forward and a new issue, and the rows add up per column', function () {
    app(YearEndClosing::class)->dispose($this->owner, $this->year, '2027-05-20', '30 000');
    Bookkeeping::post($this->owner, $this->company, 1_000_000, 1930, 2081, '2027-06-01');
    $rows = equityRows($this->next);

    expect(array_column(AnnualAccounts::for($this->next)->equityColumns(), 'key'))->toBe(['share_capital', 'retained', 'year_result'])
        ->and(array_keys($rows))->toBe(['opening', 'dividend', 'carried_forward', 'year_result', 'other', 'closing'])
        ->and($rows['opening']['amounts'])->toBe(['share_capital' => 2_500_000, 'retained' => 5_000_000, 'year_result' => 6_352_000])
        ->and($rows['opening']['total'])->toBe(13_852_000)
        ->and($rows['dividend']['amounts'])->toBe(['share_capital' => 0, 'retained' => 0, 'year_result' => -3_000_000])
        ->and($rows['carried_forward']['amounts'])->toBe(['share_capital' => 0, 'retained' => 3_352_000, 'year_result' => -3_352_000])
        ->and($rows['carried_forward']['total'])->toBe(0)
        ->and($rows['year_result']['total'])->toBe(0)
        ->and($rows['other']['amounts'])->toBe(['share_capital' => 1_000_000, 'retained' => 0, 'year_result' => 0])
        ->and($rows['closing']['amounts'])->toBe(['share_capital' => 3_500_000, 'retained' => 8_352_000, 'year_result' => 0])
        ->and($rows['closing']['total'])->toBe(11_852_000);
});

it('shows the year\'s own result and no disposition rows for the first year', function () {
    $rows = equityRows($this->year);

    // No previous year in the books: the opening balances are the comparison.
    expect(array_keys($rows))->toBe(['opening', 'year_result', 'closing'])
        ->and($rows['opening']['total'])->toBe(7_500_000)
        ->and($rows['year_result']['amounts']['year_result'])->toBe(6_352_000)
        ->and($rows['closing']['total'])->toBe(13_852_000);
});

it('prints the specification with the dividend in the annual report', function () {
    app(YearEndClosing::class)->dispose($this->owner, $this->year, '2027-05-20', '30 000');
    $html = AnnualReportDocument::html($this->company, $this->next, new AnnualReport(['fiscal_year_id' => $this->next->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]));

    expect($html)->toContain('Utdelning')
        ->and($html)->toContain('Balanseras i ny räkning')
        ->and($html)->toContain('Belopp vid årets ingång')
        ->and($html)->toContain('-30'."\u{a0}".'000')
        ->and($html)->toContain('108'."\u{a0}".'520');
});
