<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Assets\FixedAssets;
use App\Actions\Closing\YearEndClosing;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Company\LegalForm;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    // Sales 100 000, costs 20 000, a 36 000 kr machine written off over 36 months from October.
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
    Bookkeeping::post($this->owner, $this->company, 3_600_000, 1220, 1930, '2026-10-01');
    $assets = app(FixedAssets::class);
    $assets->addAccounts($this->owner, $this->company);
    $assets->save($this->owner, $this->company, ['name' => 'Kaffemaskin', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-10-01', 'acquisition' => '36 000', 'life_months' => '36', 'depreciation_start' => '2026-10-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832']);
    $assets->depreciate($this->owner, $this->company, '2026-12');
});

function lineOf(array $lines, string $key): ?int
{
    return collect($lines)->firstWhere('key', $key)['amount'] ?? null;
}

it('builds the K2 income statement with its subtotals', function () {
    $income = AnnualAccounts::for($this->year)->incomeStatement();

    expect(lineOf($income, 'net_sales'))->toBe(10_000_000)
        ->and(lineOf($income, 'other_external'))->toBe(-2_000_000)
        ->and(lineOf($income, 'depreciation'))->toBe(-300_000)
        ->and(lineOf($income, 'operating_result'))->toBe(7_700_000)
        ->and(lineOf($income, 'year_result'))->toBe(7_700_000);
});

it('balances the balance sheet before and after the result is booked', function () {
    $before = AnnualAccounts::for($this->year)->balanceSheet();
    $free = collect($before['sections'])->firstWhere('key', 'free');

    expect($before['assets'])->toBe($before['equity_liabilities'])
        ->and($before['assets'])->toBe(7_700_000)
        ->and(lineOf($free['lines'], 'year_result'))->toBe(7_700_000);

    $closing = app(YearEndClosing::class);
    $closing->bookTax($this->owner, $this->year, '15 862');
    $closing->bookResult($this->owner, $this->year);
    $after = AnnualAccounts::for($this->year);
    $sheet = $after->balanceSheet();

    expect($sheet['assets'])->toBe($sheet['equity_liabilities'])
        ->and(lineOf(collect($sheet['sections'])->firstWhere('key', 'free')['lines'], 'year_result'))->toBe(7_700_000 - 1_586_200)
        ->and(lineOf(collect($sheet['sections'])->firstWhere('key', 'short_term')['lines'], 'tax_liabilities'))->toBe(1_586_200)
        ->and($after->freeEquity())->toBe(6_113_800)
        ->and($after->solidity())->toBe(79.4);
});

it('notes the fixed assets with acquisition values and depreciation', function () {
    [$note] = AnnualAccounts::for($this->year)->fixedAssetNotes();

    expect($note['label'])->toBe('Maskiner och inventarier')
        ->and($note['acquisition'])->toBe(['opening' => 0, 'additions' => 3_600_000, 'disposals' => 0, 'closing' => 3_600_000])
        ->and($note['depreciation']['charge'])->toBe(300_000)
        ->and($note['book_value'])->toBe(3_300_000);
});

it('saves the texts and renders the report as a page and a PDF', function () {
    app(SaveAnnualReport::class)($this->owner, $this->year, [
        'business_description' => 'Företaget driver ett kafé i Uppsala.', 'significant_events' => '', 'dividend' => '20 000',
        'average_employees' => '2', 'signing_place' => 'Uppsala', 'signing_date' => '2027-03-01', 'signatories' => "Anna Andersson, Styrelseledamot\nBo Berg",
    ]);

    $this->get(route('accounting.annualReport.show', $this->year))
        ->assertOk()
        ->assertSee('Företaget driver ett kafé i Uppsala.')
        ->assertSee('Utdelas till aktieägarna')
        ->assertSee('Anna Andersson')
        ->assertSee('Maskiner och inventarier');

    $pdf = $this->get(route('accounting.annualReport.pdf', $this->year));

    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue()
        ->and(AnnualReportDocument::filename($this->company, $this->year))->toEndWith('-2026.pdf');
});

it('is for companies with an annual report, and only for those who read reports or close the year', function () {
    // Since S1-P10 reading needs reports:view (the auditor reads and exports, AGENTS.md §4); the approver has neither.
    $bookkeeper = Bookkeeping::member($this->company, 'approver');
    $other = Company::factory()->create();

    Livewire::test(AnnualReportPage::class)
        ->assertSet('year', $this->year->id)
        ->assertSet('form.signatories', "{$this->owner->name}, Styrelseledamot")
        ->set('form.average_employees', '1')
        ->call('save')
        ->assertDispatched('toast');

    $this->company->forceFill(['legal_form' => LegalForm::Ef])->save();
    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => '0']))->toThrow(ValidationException::class);
    $this->get(route('accounting.annualReport.pdf', $this->year))->assertForbidden();

    $this->actingAs($bookkeeper);
    $this->get(route('accounting.annualReport.show', $this->year))->assertForbidden();
    $this->get(route('accounting.annualReport'))->assertForbidden();

    $foreign = CurrentCompany::run($other, fn () => FiscalYear::create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']));
    $this->actingAs($this->owner);
    $this->get(route('accounting.annualReport.show', $foreign))->assertNotFound();
});

it('refuses a proposed dividend larger than the free equity (ABL 17 kap. 3 §, #84)', function () {
    $save = fn (string $dividend) => app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => $dividend, 'average_employees' => '0']);

    // Free equity: no retained earnings, the year's result 100 000 - 20 000 - 3 000 = 77 000.
    expect(AnnualAccounts::for($this->year)->freeEquity())->toBe(7_700_000)
        ->and(fn () => $save('77 000,01'))->toThrow(ValidationException::class, 'Aktiebolagslagen 17 kap. 3 §')
        ->and(AnnualReport::query()->exists())->toBeFalse();

    $report = $save('77 000');

    expect($report->dividend_ore->ore)->toBe(7_700_000)
        ->and(AnnualReportDocument::data($this->company, $this->year, $report)['disposition'])->toBe(['free' => 7_700_000, 'dividend' => 7_700_000, 'carried' => 0]);
});

it('refuses any dividend when the free equity is negative, and names the law of an ekonomisk förening', function () {
    // A loss of 120 000 brings the free equity to 77 000 - 120 000 = -43 000.
    Bookkeeping::post($this->owner, $this->company, 12_000_000, 5010, 1930, '2026-11-01');
    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();

    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => '1', 'average_employees' => '0']))
        ->toThrow(ValidationException::class, 'Lagen om ekonomiska föreningar 12 kap. 3 §')
        ->and(app(SaveAnnualReport::class)($this->owner, $this->year, ['dividend' => '', 'average_employees' => '0'])->dividend_ore->ore)->toBe(0);
});

it('shows the free equity and the prudence rule by the dividend field', function () {
    Livewire::test(AnnualReportPage::class)
        ->assertSee('Högst det fria egna kapitalet, 77'."\u{a0}".'000,00'."\u{a0}".'kr', false)
        ->assertSee('försiktighetsregeln')
        ->set('form.dividend', '100 000')
        ->call('save')
        ->assertHasErrors('dividend');
});
