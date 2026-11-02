<?php

declare(strict_types=1);

/*
 * #92 (BS-AUD-BOK-11): the K2 notes a person enters. ÅRL 5 kap. 14 §
 * (ställda säkerheter: omfattning, art och form), 15 § (the sum of
 * eventualförpliktelser), 16 § (åtaganden for group and associated
 * companies), 22 § (significant events after the year: art and economic
 * effect); BFNAR 2025:2 p. 18.13 a–e (kinds), 18.14 (mortgage amount or
 * carrying value), 18.20A (effect cannot be estimated). Read 2026-11-02.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\AnnualReport;
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
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
});

function notesHtml(AnnualReport $report): string
{
    return AnnualReportDocument::html(test()->company, test()->year, $report);
}

it('prints the ställda säkerheter per kind with their sum, and the eventualförpliktelser, as numbered notes', function () {
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, [
        'average_employees' => 0,
        'pledged' => ['company_mortgage' => '500 000', 'property_mortgage' => '200 000', 'other' => '50 000'],
        'pledged_other_text' => 'Pantsatta aktier',
        'contingent_liabilities' => '100 000', 'contingent_liabilities_text' => 'Borgen för kunds banklån',
    ]);
    $html = notesHtml($report);
    $nbsp = "\u{a0}";

    // No fixed assets: notes 1 and 2 are the principles and the employees.
    // 500 000 + 200 000 + 50 000 = 750 000.
    expect($report->pledged_assets)->toBe(['company_mortgage' => 50_000_000, 'property_mortgage' => 20_000_000, 'other' => 5_000_000])
        ->and($html)->toContain('Not 3 Ställda säkerheter')
        ->and($html)->toContain('Företagsinteckningar')
        ->and($html)->toContain('Fastighetsinteckningar')
        ->and($html)->toContain('Pantsatta aktier')
        ->and($html)->toContain("750{$nbsp}000")
        ->and($html)->toContain('Not 4 Eventualförpliktelser')
        ->and($html)->toContain('Borgen för kunds banklån')
        ->and($html)->toContain("100{$nbsp}000");
});

it('prints events after the year and that their effect cannot be estimated', function () {
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, [
        'average_employees' => 0,
        'group_commitments' => 'Borgen för dotterbolagets checkkredit.',
        'events_after' => 'En brand i lagret i februari 2027.', 'events_effect_unknown' => true,
    ]);
    $html = notesHtml($report);

    expect($html)->toContain('Not 3 Åtaganden till förmån för koncern- och intresseföretag')
        ->and($html)->toContain('Not 4 Väsentliga händelser efter räkenskapsårets slut')
        ->and($html)->toContain('En brand i lagret i februari 2027.')
        ->and($html)->toContain('Den ekonomiska effekten av händelserna kan inte uppskattas.');
});

it('leaves the notes out when nothing is entered', function () {
    $html = notesHtml(app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0]));

    expect($html)->not->toContain('Ställda säkerheter')
        ->and($html)->not->toContain('Not 3');
});

it('refuses a negative or unreadable amount', function () {
    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'pledged' => ['company_mortgage' => '-1 000']]))
        ->toThrow(ValidationException::class, 'negativt')
        ->and(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'contingent_liabilities' => 'mycket']))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'pledged' => ['gold' => '1']]))
        ->toThrow(ValidationException::class);
});

it('reminds about ställda säkerheter when the company has a bank loan, and saves the notes from the page', function () {
    Livewire::test(AnnualReportPage::class)->assertDontSee('Företaget har lån hos kreditinstitut.');

    // A bank loan of 300 000 on 2350.
    Bookkeeping::post($this->owner, $this->company, 30_000_000, 1930, 2350, '2026-05-01');

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Företaget har lån hos kreditinstitut.')
        ->set('form.pledged.company_mortgage', '300 000')
        ->set('form.events_after', 'Nytt lån i januari.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(AnnualReport::query()->sole())->pledged_assets->toBe(['company_mortgage' => 30_000_000])->events_after->toBe('Nytt lån i januari.');
});
