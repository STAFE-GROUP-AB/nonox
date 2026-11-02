<?php

declare(strict_types=1);

/*
 * #93 (BS-AUD-BOK-12): BFNAR 2016:10 p. 5.5 andra stycket (and BFNAR
 * 2025:2 p. 5.5), read 2026-11-02: "Varierar nettoomsättningen mer än 30
 * procent mellan åren ska företaget kommentera detta." Expected values by
 * hand: 2025 net sales 100 000; 2026 130 000 is +30,0 % (not more than
 * 30 %), 130 000,01 is more; 69 999 is -30,001 %.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Livewire\Accounting\AnnualReportPage;
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
    FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2025-03-01');
});

it('flags a change of more than 30 % between the years, but not exactly 30 %', function (int $sales2026, bool $varies) {
    Bookkeeping::post($this->owner, $this->company, $sales2026, 1930, 3001, '2026-03-01');

    expect(AnnualAccounts::for($this->year)->netSalesVariations() !== [])->toBe($varies);
})->with([
    'up 30,0 %' => [13_000_000, false],
    'up 30,00001 %' => [13_000_001, true],
    'down 30,0 %' => [7_000_000, false],
    'down 30,001 %' => [6_999_900, true],
    'up 25 %' => [12_500_000, false],
]);

it('requires a comment before saving and prints it with the variation under the multi-year overview', function () {
    Bookkeeping::post($this->owner, $this->company, 20_000_000, 1930, 3001, '2026-03-01');
    $label2025 = FiscalYear::query()->whereDate('start_date', '2025-01-01')->firstOrFail()->label();

    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0]))
        ->toThrow(ValidationException::class, "mellan {$label2025} och {$this->year->label()}");

    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'net_sales_comment' => 'En ny stor kund från mars.']);
    $html = AnnualReportDocument::html($this->company, $this->year, $report);
    $nbsp = "\u{a0}";

    // 100 000 → 200 000 is +100 %.
    expect($html)->toContain("Nettoomsättningen har förändrats med mer än 30 procent, från 100{$nbsp}000 kr {$label2025} till 200{$nbsp}000 kr {$this->year->label()}.")
        ->and($html)->toContain('En ny stor kund från mars.');
});

it('warns on the page and asks for the comment', function () {
    Bookkeeping::post($this->owner, $this->company, 20_000_000, 1930, 3001, '2026-03-01');

    Livewire::test(AnnualReportPage::class)
        ->set('year', $this->year->id)
        ->assertSee('K2 kräver att du kommenterar det i förvaltningsberättelsen.')
        ->call('save')
        ->assertHasErrors('net_sales_comment');
});
