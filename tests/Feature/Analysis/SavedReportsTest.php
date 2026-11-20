<?php

declare(strict_types=1);

use App\Actions\Analysis\DeleteReport;
use App\Actions\Analysis\PinReport;
use App\Actions\Analysis\SaveReport;
use App\Actions\Company\SetDimensionScope;
use App\Domain\Analysis\Export\ReportFiles;
use App\Domain\Analysis\Export\ReportTable;
use App\Domain\Analysis\Reports\ReportVisibility;
use App\Domain\Analysis\Reports\SavedReports;
use App\Livewire\Analysis\AnalysisPage;
use App\Livewire\Overview\OverviewPage;
use App\Livewire\Overview\ReportTiles;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ReportDefinition;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 7, E18: saved and shared reports. A report holds the choices of
 * an analysis as keys; opening it runs them again with the viewer's own
 * permission and dimension scope, and every export holds what the screen holds.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $this->fixture = DimensionScopeFixture::make();
    $this->actingAs($this->fixture->owner);
    CurrentCompany::forget();
});

/** @param  array<string, mixed>  $choices */
function e18Save($test, $user, array $choices = [], string $name = 'Resultat per konto', string $visibility = 'COMPANY', bool $rolling = false): ReportDefinition
{
    $company = $test->fixture->company;

    return app(SaveReport::class)($user, $company, [
        'dataset' => 'ledger', 'rows' => ['account'], 'measure' => 'net', 'from' => '2026-01', 'to' => '2026-12',
        ...$choices, 'name' => $name, 'visibility' => $visibility, 'rolling' => $rolling,
    ]);
}

function e18Open($test, $user, ReportDefinition $report)
{
    CurrentCompany::forget();

    try {
        return Livewire::actingAs($user)->withQueryParams(['rapport' => (string) $report->id])->test(AnalysisPage::class);
    } finally {
        Livewire::withQueryParams([]);
    }
}

function e18Tiles($user)
{
    CurrentCompany::forget();
    test()->actingAs($user);

    return Livewire::withoutLazyLoading()->test(ReportTiles::class);
}

/** @return array<string, int> */
function e18ByRow($page): array
{
    $result = $page->viewData('result');

    return collect($result->rows)->mapWithKeys(fn (array $row): array => [$row['label'] => $result->rowTotals[$row['key']]['v']])->all();
}

it('stores the choices as keys only, normalised against the catalogue, and no figures', function () {
    $report = e18Save($this, $this->fixture->owner, ['rows' => ['account', 'nonsense'], 'measure' => '1; DROP TABLE x', 'filters' => ['account' => ['3001'], 'bogus' => ['x']]], 'Test');

    expect($report->definition['rows'])->toBe(['account'])
        ->and($report->definition['measure'])->toBe('net')
        ->and($report->definition['filters'])->toBe(['account' => ['3001']])
        ->and(array_keys($report->definition))->toBe(['dataset', 'rows', 'column', 'measure', 'from', 'to', 'compare', 'filters', 'sort', 'rolling', 'months'])
        ->and(json_encode($report->definition))->not->toContain('DROP')
        ->and($report->dataset)->toBe('ledger');
});

it('saves from the page, shows a toast, and keeps the report as the one being edited', function () {
    $page = Livewire::test(AnalysisPage::class)
        ->set('from', '2026-01')->set('to', '2026-12')->set('rows', ['account'])
        ->set('reportName', 'Mitt resultat')->set('reportVisibility', 'PRIVATE')
        ->call('saveReport')
        ->assertDispatched('toast', message: 'Rapporten Mitt resultat är sparad.', type: 'success');

    $report = ReportDefinition::query()->firstOrFail();

    expect($report->name)->toBe('Mitt resultat')->and($report->visibility)->toBe(ReportVisibility::Private)
        ->and($report->owner_user_id)->toBe($this->fixture->owner->id);

    $page->set('reportName', 'Nytt namn')->call('updateReport')->assertDispatched('toast', message: 'Rapporten Nytt namn är uppdaterad.', type: 'success');

    expect(ReportDefinition::query()->count())->toBe(1)->and($report->fresh()->name)->toBe('Nytt namn');
    expect(AuditLog::query()->whereIn('action', ['analysis.report_saved', 'analysis.report_updated'])->count())->toBe(2);
});

it('needs a name, and at most fifty reports per person', function () {
    Livewire::test(AnalysisPage::class)->set('reportName', '')->call('saveReport')->assertHasErrors('reportName');

    for ($i = 1; $i <= SavedReports::MAX_PER_OWNER; $i++) {
        e18Save($this, $this->fixture->owner, [], "Rapport {$i}", 'PRIVATE');
    }

    Livewire::test(AnalysisPage::class)->set('reportName', 'En till')->call('saveReport')->assertHasErrors('reportName');
});

it('lets only someone with analysis:share make a report visible to the company', function () {
    $bookkeeper = Bookkeeping::member($this->fixture->company, 'bookkeeper');
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    $auditor = Bookkeeping::member($this->fixture->company, 'auditor');
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');

    expect(fn () => e18Save($this, $bookkeeper))->toThrow(AuthorizationException::class)
        ->and(fn () => e18Save($this, $manager))->toThrow(AuthorizationException::class)
        ->and(e18Save($this, $accountant)->visibility)->toBe(ReportVisibility::Company)
        ->and(e18Save($this, $auditor, [], 'Revisorns')->visibility)->toBe(ReportVisibility::Company)
        ->and(e18Save($this, $bookkeeper, [], 'Egen', 'PRIVATE')->visibility)->toBe(ReportVisibility::Private)
        ->and(e18Save($this, $manager, [], 'Enhetschefens', 'PRIVATE')->visibility)->toBe(ReportVisibility::Private);

    // The page offers the choice only to those who may use it.
    CurrentCompany::forget();
    expect(Livewire::actingAs($bookkeeper)->test(AnalysisPage::class)->viewData('canShare'))->toBeFalse();
});

it('never gives a limited member analysis:share', function () {
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');

    expect($manager->hasTeamPermission($this->fixture->company, 'analysis:share'))->toBeFalse()
        ->and(in_array('analysis:share', DimensionScope::SCOPE_AWARE_PERMISSIONS, true))->toBeFalse();
});

it('runs a shared report again with the viewer\'s own scope, so sharing widens nothing', function () {
    $report = e18Save($this, $this->fixture->owner, ['rows' => ['account'], 'measure' => 'net']);
    $owner = e18ByRow(e18Open($this, $this->fixture->owner, $report));
    $member = $this->fixture->member();
    $limited = e18Open($this, $member, $report);

    expect(e18ByRow($limited))->toBe([
        '3001 Försäljning inom Sverige, 25 % moms' => -50_000,
        '4010 Inköp av handelsvaror i Sverige' => 20_000,
        '5010 Lokalhyra' => 30_000,
    ])->and($owner['3001 Försäljning inom Sverige, 25 % moms'])->toBeLessThan(-50_000);

    $limited->assertDontSee('Butik Syd');
});

it('shows a limited member only the datasets of their own: a report on invoices is not theirs to open', function () {
    $report = e18Save($this, $this->fixture->owner, ['dataset' => 'customer_invoices', 'rows' => ['customer'], 'measure' => 'net'], 'Fakturor');
    $member = $this->fixture->member();

    expect($report->dataset)->toBe('customer_invoices')
        ->and(SavedReports::openableBy($member, $this->fixture->company)->pluck('name')->all())->not->toContain('Fakturor');

    e18Open($this, $member, $report)->assertNotFound();
});

it('keeps a private report to its owner: others get a 404, also by id and by action', function () {
    $report = e18Save($this, $this->fixture->owner, [], 'Privat', 'PRIVATE');
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');

    e18Open($this, $accountant, $report)->assertNotFound();

    foreach ([['openReport', [$report->id]], ['deleteReport', [$report->id]], ['pinReport', [$report->id, true]]] as [$method, $arguments]) {
        CurrentCompany::forget();
        Livewire::actingAs($accountant)->test(AnalysisPage::class)->call($method, ...$arguments)->assertNotFound();
    }

    expect(SavedReports::openableBy($accountant, $this->fixture->company)->pluck('id')->all())->not->toContain($report->id)
        ->and(ReportDefinition::query()->count())->toBe(1);
});

it('lets only the owner change a company report, and the owner or a sharer delete it', function () {
    $report = e18Save($this, $this->fixture->owner, [], 'Delad');
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    $bookkeeper = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    expect(fn () => app(SaveReport::class)($accountant, $this->fixture->company, ['dataset' => 'ledger', 'name' => 'Kapad', 'visibility' => 'COMPANY', 'rolling' => false], $report))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteReport::class)($bookkeeper, $this->fixture->company, $report))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PinReport::class)($accountant, $this->fixture->company, $report, true))->toThrow(AuthorizationException::class);

    app(DeleteReport::class)($accountant, $this->fixture->company, $report);

    expect(ReportDefinition::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'analysis.report_deleted')->count())->toBe(1);
});

it('shows a sharing note and the delete confirmation on the page, with a toast on delete', function () {
    $report = e18Save($this, $this->fixture->owner, [], 'Min rapport', 'PRIVATE');

    Livewire::test(AnalysisPage::class)
        ->assertSee('Sparade rapporter')->assertSee('Min rapport')->assertSee('Radera rapporten Min rapport?')
        ->call('deleteReport', $report->id)
        ->assertDispatched('toast', message: 'Rapporten är raderad.', type: 'success');

    expect(ReportDefinition::query()->count())->toBe(0);
});

it('lets a rolling period follow the calendar and a fixed one stay', function () {
    $rolling = e18Save($this, $this->fixture->owner, ['from' => '2026-01', 'to' => '2026-03'], 'Rullande', 'PRIVATE', true);
    $fixed = e18Save($this, $this->fixture->owner, ['from' => '2026-01', 'to' => '2026-03'], 'Fast', 'PRIVATE', false);

    expect($rolling->definition['months'])->toBe(3);

    $page = e18Open($this, $this->fixture->owner, $rolling);
    expect([$page->get('from'), $page->get('to')])->toBe(['2026-04', '2026-06']);

    $this->travelTo(CarbonImmutable::parse('2026-09-02 10:00:00'));
    $page = e18Open($this, $this->fixture->owner, $rolling);
    expect([$page->get('from'), $page->get('to')])->toBe(['2026-07', '2026-09']);

    $page = e18Open($this, $this->fixture->owner, $fixed);
    expect([$page->get('from'), $page->get('to')])->toBe(['2026-01', '2026-03']);
});

it('keeps the choices in the URL after opening, so an edit survives a reload', function () {
    $report = e18Save($this, $this->fixture->owner, ['rows' => ['account']], 'Konton', 'PRIVATE');
    CurrentCompany::forget();

    $page = Livewire::actingAs($this->fixture->owner)->withQueryParams(['rapport' => (string) $report->id, 'mangd' => 'ledger', 'rader' => ['cost_center'], 'matt' => 'debit'])->test(AnalysisPage::class);

    expect($page->get('rows'))->toBe(['cost_center'])->and($page->get('metric'))->toBe('debit');
});

it('exports what the screen shows as CSV, XLSX and PDF, and writes an audit entry without figures', function () {
    $page = Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12')->set('rows', ['account'])->set('metric', 'debit');
    $result = $page->viewData('result');
    $cents = $result->grandTotal['v'];

    $csv = $page->call('export', 'csv')->assertFileDownloaded('bokforingsrader-20260615.csv', null, 'text/csv; charset=UTF-8');
    $contents = base64_decode((string) data_get($csv->effects, 'download.content'));

    expect($contents)->toStartWith("\xEF\xBB\xBF")
        ->and($contents)->toContain('Bokföringsrader')->toContain(';'.number_format($cents / 100, 2, ',', ''))
        ->and(substr_count($contents, "\n"))->toBeGreaterThan(count($result->rows));

    $xlsx = Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12')->set('rows', ['account'])->set('metric', 'debit')->call('export', 'xlsx');
    $rows = XlsxReader::rows(base64_decode((string) data_get($xlsx->effects, 'download.content')));

    expect(count($rows))->toBe(count($result->rows) + 4)
        ->and(end($rows)[0])->toBe('Summa')
        ->and((float) end($rows)[1])->toEqual($cents / 100);

    $pdf = Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12')->set('rows', ['account'])->call('export', 'pdf');

    expect(base64_decode((string) data_get($pdf->effects, 'download.content')))->toStartWith('%PDF');

    $audit = AuditLog::query()->where('action', 'analysis.exported')->orderBy('id')->get();

    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('payload.format')->all())->toBe(['csv', 'xlsx', 'pdf'])
        ->and(json_encode($audit->pluck('payload')->all()))->not->toContain((string) $cents);
});

it('exports only a limited member\'s own rows, never another unit\'s', function () {
    $member = $this->fixture->member();
    CurrentCompany::forget();
    $page = Livewire::actingAs($member)->test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12')->set('rows', ['cost_center'])->set('metric', 'debit');
    $download = $page->call('export', 'csv');
    $contents = base64_decode((string) data_get($download->effects, 'download.content'));

    expect($contents)->toContain('Butik Norr')->toContain('Annan enhet')
        ->and($contents)->not->toContain('Butik Syd')->not->toContain('Grossisten');
});

it('refuses a file format that does not exist and says so when there is nothing to export', function () {
    Livewire::test(AnalysisPage::class)->call('export', 'exe')->assertNotFound();

    Livewire::test(AnalysisPage::class)->set('from', '1999-01')->set('to', '1999-02')->call('export', 'csv')
        ->assertDispatched('toast', message: 'Det finns inget att hämta för valet.', type: 'error')
        ->assertNoFileDownloaded();
});

it('refuses a PDF that would not fit a page and offers the other formats', function () {
    $table = new ReportTable('Stor', '', array_fill(0, ReportFiles::PDF_MAX_COLUMNS + 1, 'x'), [], true);

    expect(ReportFiles::tooLargeForPdf($table))->toBeTrue();

    $rows = array_fill(0, ReportFiles::PDF_MAX_ROWS + 1, ['a', 1]);

    expect(ReportFiles::tooLargeForPdf(new ReportTable('Stor', '', ['a', 'b'], $rows, true)))->toBeTrue()
        ->and(ReportFiles::tooLargeForPdf(new ReportTable('Liten', '', ['a', 'b'], [['a', 1]], true)))->toBeFalse();
});

it('guards every text against spreadsheet formulas in CSV and writes figures as numbers in XLSX', function () {
    $table = new ReportTable('=HYPERLINK("x")', '+1', ['@A', 'Belopp'], [['=1+1', 12_345], ['Dold', 'Dold'], ['Summa', -50_000]], true);
    $csv = ReportFiles::csv($table);

    expect($csv)->toContain("'=HYPERLINK")->toContain("'=1+1")->toContain("'@A")->toContain(';123,45')->toContain(';-500,00');

    $rows = XlsxReader::rows(ReportFiles::xlsx($table));

    expect($rows[3][1])->toBe('123.45')->and($rows[4][1])->toBe('Dold')->and($rows[5][1])->toBe('-500');
});

it('writes a hidden cell as Dold in every format and never as a number', function () {
    $table = new ReportTable('Lön', '', ['Rad', 'Belopp'], [['Bar', ReportTable::HIDDEN], ['Summa', ReportTable::HIDDEN]], true);

    expect(ReportFiles::csv($table))->toContain('Bar;Dold')->toContain('Summa;Dold');
    expect(array_column(XlsxReader::rows(ReportFiles::xlsx($table)), 1))->toContain('Dold');
});

it('shows pinned reports as tiles, each person\'s own figures, with a fixed number of queries', function () {
    $owner = $this->fixture->owner;
    $count = function (callable $callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $make = function (int $n) use ($owner): void {
        for ($i = 1; $i <= $n; $i++) {
            $report = e18Save($this, $owner, [], "Flik {$i}", 'COMPANY');
            app(PinReport::class)($owner, $this->fixture->company, $report, true);
        }
    };

    CurrentCompany::forget();
    Livewire::actingAs($owner)->test(OverviewPage::class);
    CurrentCompany::forget();
    $none = $count(fn () => Livewire::actingAs($owner)->test(OverviewPage::class));
    $make(2);
    CurrentCompany::forget();
    Livewire::actingAs($owner)->test(OverviewPage::class);
    CurrentCompany::forget();
    $overviewTwo = $count(fn () => Livewire::actingAs($owner)->test(OverviewPage::class));
    $tilesTwo = $count(fn () => e18Tiles($owner));
    $make(4);
    CurrentCompany::forget();
    Livewire::actingAs($owner)->test(OverviewPage::class);
    CurrentCompany::forget();
    $overviewSix = $count(fn () => Livewire::actingAs($owner)->test(OverviewPage::class));
    $tilesSix = $count(fn () => e18Tiles($owner));
    $tilesAgain = $count(fn () => e18Tiles($owner));

    // The overview runs no tile query itself (the component is lazy); the tiles are capped, so six pinned cost no more than four.
    expect($overviewTwo)->toBe($none)->and($overviewSix)->toBe($none)
        ->and($tilesSix)->toBe($tilesAgain)
        ->and($tilesTwo)->toBeLessThan($tilesSix);

    $tiles = e18Tiles($owner)->viewData('tiles');

    expect($tiles)->toHaveCount(SavedReports::MAX_TILES);
});

it('shows a tile as the viewer\'s own total and not as the owner\'s', function () {
    $report = e18Save($this, $this->fixture->owner, ['rows' => ['account'], 'measure' => 'debit'], 'Resultat', 'COMPANY');
    app(PinReport::class)($this->fixture->owner, $this->fixture->company, $report, true);
    $member = $this->fixture->member();

    CurrentCompany::forget();
    $owner = e18Tiles($this->fixture->owner)->viewData('tiles')[0]['value'];
    CurrentCompany::forget();
    $limited = e18Tiles($member)->viewData('tiles')[0]['value'];

    // 20 000 + 30 000 of debit inside the scope.
    expect($limited)->toBe(50_000)->and($owner)->not->toBe($limited);
});

it('shows no tile for a private report to anyone but its owner, and none for a dataset the viewer lacks', function () {
    $private = e18Save($this, $this->fixture->owner, [], 'Privat flik', 'PRIVATE');
    app(PinReport::class)($this->fixture->owner, $this->fixture->company, $private, true);
    $invoices = e18Save($this, $this->fixture->owner, ['dataset' => 'customer_invoices', 'rows' => ['customer'], 'measure' => 'net'], 'Fakturaflik', 'COMPANY');
    app(PinReport::class)($this->fixture->owner, $this->fixture->company, $invoices, true);

    CurrentCompany::forget();
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    expect(array_column(e18Tiles($accountant)->viewData('tiles'), 'name'))->toBe(['Fakturaflik']);

    CurrentCompany::forget();
    expect(e18Tiles($this->fixture->member())->viewData('tiles'))->toBe([]);
});

it('asks for analysis:view before showing tiles on the overview', function () {
    CurrentCompany::forget();
    $approver = Bookkeeping::member($this->fixture->company, 'approver');

    expect(Livewire::actingAs($approver)->test(OverviewPage::class)->viewData('canSeeReportTiles'))->toBeFalse();
});

it('lets a limited member save and open private reports of their own', function () {
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $manager->id, [$this->fixture->north->id], []);
    DimensionScope::forget();

    $report = e18Save($this, $manager->fresh(), [], 'Enhetens rapport', 'PRIVATE');

    expect(e18ByRow(e18Open($this, $manager->fresh(), $report)))->not->toBe([])
        ->and(Account::query()->count())->toBeGreaterThan(0);
});
