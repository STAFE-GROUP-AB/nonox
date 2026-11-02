<?php

declare(strict_types=1);

/*
 * #42 (AUD-BOK-3): BFL 5 kap. 5 § (read 2026-11-02) — when an entry is
 * corrected by a separate correcting entry, it must be easy to learn of the
 * correction when the corrected entry is examined. BFN's vägledning
 * (BFNAR 2013:2, comment on p. 2.17–2.18): e.g. by giving the correcting
 * entry's number on the corrected one. The voucher list in CSV, PDF and the
 * archive package therefore names the correction on every line.
 */

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\ReportTable;
use App\Livewire\Accounting\Reports;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

/** @return array<string, list<string|int>> the CSV row by voucher reference (the first line of each) */
function correctionRows(FiscalYear $year): array
{
    $rows = app(ReportTable::class)->rows('verifikationer', LedgerQuery::forYear($year));
    $heading = array_shift($rows);
    $byReference = [];

    foreach ($rows as $row) {
        $byReference[(string) $row[0]] ??= array_combine($heading, $row);
    }

    return $byReference;
}

it('names the correcting voucher on the corrected one and the other way round, in the same year', function () {
    $original = Bookkeeping::post($this->owner, $this->company, 100_000, 5010, 1930, '2026-03-01');
    app(CorrectVoucher::class)($this->owner, $original, 'Fel konto', '2026-03-31');
    $rows = correctionRows($this->year);

    // A1 is the original, A2 the correction.
    expect($rows['A1'])->toMatchArray(['Rättad av' => 'A2', 'Rättar' => ''])
        ->and($rows['A2'])->toMatchArray(['Rättad av' => '', 'Rättar' => 'A1']);
});

it('adds the fiscal year when the correction lies in another year', function () {
    $original = Bookkeeping::post($this->owner, $this->company, 100_000, 5010, 1930, '2026-03-01');
    $next = FiscalYear::query()->whereDate('start_date', '2027-01-01')->first() ?? app(CreateNextFiscalYear::class)($this->owner, $this->company);
    app(CorrectVoucher::class)($this->owner, $original, 'Upptäckt efter årsskiftet', '2027-01-15');

    // The correction is the first A voucher of 2027.
    expect(correctionRows($this->year)['A1']['Rättad av'])->toBe('A1 (2027)')
        ->and(correctionRows($next)['A1']['Rättar'])->toBe('A1 (2026)');
});

it('shows the link in the printable voucher list as well', function () {
    $original = Bookkeeping::post($this->owner, $this->company, 100_000, 5010, 1930, '2026-03-01');
    app(CorrectVoucher::class)($this->owner, $original, 'Fel konto', '2026-03-31');

    Livewire::test(Reports::class)
        ->call('show', 'verifikationer')
        ->assertSee('Rättad av A2')
        ->assertSee('Rättar A1');
});
