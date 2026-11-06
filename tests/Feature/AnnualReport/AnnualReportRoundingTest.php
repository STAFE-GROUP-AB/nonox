<?php

declare(strict_types=1);

/*
 * ADR-010, addendum 2026-11-06: the annual report's figures in whole kronor
 * that add up, and a contingent note without an amount.
 *
 * Loss-making fixture with öre, no previous year in NONOX (kronor):
 *   Opening balances 2026: bank 50 000,50, share capital 25 000, retained
 *   earnings 25 000,50.
 *   Bank loan 100 000,25 (2350). Sales 30 000,40. Other external costs
 *   90 000,30 paid and 1 000,49 unpaid (2440). Wages 12 345,67.
 *   Exact result -73 346,06; bank 77 655,18.
 * Each line is rounded to whole kronor (half away from zero) and every sum
 * is the sum of the rounded lines:
 *   income: 30 000 - 91 001 - 12 346 = -73 347
 *   assets: bank 77 655
 *   equity: 25 000 + 25 001 - 73 347 = -23 346
 *   liabilities: 100 000 + 1 000 = 101 000, so equity and liabilities are
 *   77 654 and the 1 krona of rounding goes to Övriga skulder: 77 655.
 *   Opening column: bank 50 001 = 25 000 + 25 001.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\PrintedFigures;
use App\Domain\Company\VatPeriod;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0002', 'legal_name' => 'Förlustbolaget AB'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    foreach ([1930 => 5_000_050, 2081 => -2_500_000, 2091 => -2_500_050] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Account::query()->firstOrCreate(['number' => 2350], ['name' => 'Skulder till kreditinstitut', 'type' => AccountType::forNumber(2350)]);
    Bookkeeping::post($this->owner, $this->company, 10_000_025, 1930, 2350, '2026-02-01');
    Bookkeeping::post($this->owner, $this->company, 3_000_040, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 9_000_030, 5010, 1930, '2026-04-01');
    Bookkeeping::post($this->owner, $this->company, 100_049, 5010, 2440, '2026-11-01');
    Bookkeeping::post($this->owner, $this->company, 1_234_567, 7010, 1930, '2026-06-25');

    $this->report = app(SaveAnnualReport::class)($this->owner, $this->year, [
        'business_description' => 'Bolaget säljer kaffe i Exempelstad.',
        'average_employees' => '0',
        'adoption_date' => '2027-05-01',
    ]);
});

it('rounds to the nearest krona, half a krona away from zero', function () {
    expect(PrintedFigures::round(10_049))->toBe(10_000)
        ->and(PrintedFigures::round(10_050))->toBe(10_100)
        ->and(PrintedFigures::round(-10_050))->toBe(-10_100)
        ->and(PrintedFigures::round(-10_049))->toBe(-10_000)
        ->and(PrintedFigures::round(0))->toBe(0);
});

it('prints every line rounded to whole kronor and every sum as the sum of the printed lines', function () {
    $data = AnnualReportDocument::data($this->company, $this->year, $this->report);
    $income = array_column($data['income'], 'amount', 'key');

    // Hand-written, in öre.
    expect($income['net_sales'])->toBe(3_000_000)
        ->and($income['other_external'])->toBe(-9_100_100)
        ->and($income['personnel'])->toBe(-1_234_600)
        ->and($income['operating_result'])->toBe(-7_334_700)
        ->and($income['after_financial'])->toBe(-7_334_700)
        ->and($income['year_result'])->toBe(-7_334_700);

    $lines = [];
    $sections = [];

    foreach ($data['balance']['sections'] as $section) {
        $sections[$section['key']] = [$section['amount'], $section['previous']];

        foreach ($section['lines'] as $line) {
            $lines[$line['key']] = [$line['amount'], $line['previous']];
            expect($line['amount'] % 100)->toBe(0)->and($line['previous'] % 100)->toBe(0);
        }

        // Every section sum is the sum of its printed lines.
        expect($section['amount'])->toBe(array_sum(array_column($section['lines'], 'amount')))
            ->and($section['previous'])->toBe(array_sum(array_column($section['lines'], 'previous')));
    }

    expect($lines['cash'])->toBe([7_765_500, 5_000_100])
        ->and($lines['share_capital'])->toBe([2_500_000, 2_500_000])
        ->and($lines['retained'])->toBe([2_500_100, 2_500_100])
        ->and($lines['year_result'])->toBe([-7_334_700, 0])
        ->and($lines['long_credit_institutions'])->toBe([10_000_000, 0])
        ->and($lines['trade_payables'])->toBe([100_000, 0])
        // The krona of rounding is put on Övriga skulder, never left out of the sums.
        ->and($lines['other_liabilities'])->toBe([100, 0])
        ->and($data['balance']['assets'])->toBe(7_765_500)
        ->and($data['balance']['equity_liabilities'])->toBe(7_765_500)
        ->and($data['balance']['assets_previous'])->toBe(5_000_100)
        ->and($data['balance']['equity_liabilities_previous'])->toBe(5_000_100)
        ->and($data['balance']['equity'])->toBe(-2_334_600)
        ->and($data['disposition'])->toBe(['free' => -4_834_600, 'dividend' => 0, 'carried' => -4_834_600]);

    // The year's result in the equity specification is the income statement's printed result.
    $equity = array_column($data['equityChanges'], 'total', 'key');
    expect($equity['year_result'])->toBe(-7_334_700)
        ->and($equity['opening'] + $equity['year_result'] + ($equity['carried_forward'] ?? 0) + ($equity['other'] ?? 0) + ($equity['dividend'] ?? 0))->toBe($equity['closing'])
        ->and($equity['closing'])->toBe(-2_334_600);

    // The PDF prints the same rounded figures.
    $html = AnnualReportDocument::html($this->company, $this->year, $this->report);
    expect($html)->toContain('-73'."\u{a0}".'347')
        ->toContain('77'."\u{a0}".'655')
        ->toContain('25'."\u{a0}".'001')
        ->not->toContain('-73'."\u{a0}".'346');
});

it('prints no 0 for a contingent liability without an amount', function () {
    $this->report->forceFill(['contingent_liabilities_ore' => null, 'contingent_liabilities_text' => 'Borgen för en leverantörs lån'])->save();
    $report = $this->report->fresh();

    expect(AnnualReportDocument::data($this->company, $this->year, $report)['contingent'])->toBeNull();

    $pdf = new DOMDocument;
    @$pdf->loadHTML('<?xml encoding="UTF-8">'.AnnualReportDocument::html($this->company, $this->year, $report));
    $row = (new DOMXPath($pdf))->query("//td[normalize-space(.)='Borgen för en leverantörs lån']/following-sibling::td[1]")?->item(0);

    expect($row)->not->toBeNull()->and(trim((string) $row?->textContent))->toBe('');
});

it('rounds a contingent liability with öre to whole kronor', function () {
    $this->report->forceFill(['contingent_liabilities_ore' => 1_234_550, 'contingent_liabilities_text' => 'Borgen'])->save();

    expect(AnnualReportDocument::data($this->company, $this->year, $this->report->fresh())['contingent'])->toBe(1_234_600);
});
