<?php

declare(strict_types=1);

use App\Actions\Analysis\PinReport;
use App\Actions\Analysis\SaveReport;
use App\Domain\Analysis\Datasets\PayrollDataset;
use App\Domain\Analysis\Reports\SavedReports;
use App\Livewire\Analysis\AnalysisPage;
use App\Livewire\Overview\ReportTiles;
use App\Models\CostCenter;
use App\Models\ReportDefinition;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Support\Luhn;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * ADR-081 gap 7, E18, with the payroll rule of E17 (owner decision 17): a
 * saved or shared report on pay, a tile and every export keep the small-group
 * rule. A group of fewer than three people is "Dold" on the screen, in the
 * files and on the tile; sharing a report never turns a hidden cell into a
 * figure, and a member without payroll:view cannot open it.
 */
beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    CostCenter::create(['code' => '10', 'name' => 'Kök']);
    CostCenter::create(['code' => '20', 'name' => 'Bar']);

    foreach ([1, 2, 3] as $i) {
        Payroll::employee($this->owner, $this->company, ['first_name' => "Kock{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'cost_center' => '10', 'salary' => '30 000']);
    }

    Payroll::employee($this->owner, $this->company, ['first_name' => 'Bartender', 'personal_number' => '19800101124'.Luhn::checkDigit('800101124'), 'cost_center' => '20', 'salary' => '45 000']);

    $runs = app(PayrollRuns::class);
    $run = $runs->create($this->owner, $this->company, '2026-04');
    $runs->calculate($this->owner, $run);
    $runs->approve(Bookkeeping::member($this->company, 'admin'), $run->fresh());

    $this->report = app(SaveReport::class)($this->owner, $this->company, [
        'dataset' => 'payroll', 'rows' => ['cost_center'], 'measure' => array_key_first((new PayrollDataset)->measures()),
        'from' => '2026-01', 'to' => '2026-12', 'name' => 'Lön per enhet', 'visibility' => 'COMPANY', 'rolling' => false,
    ]);
});

function e18PayrollOpen($test, $user, ReportDefinition $report)
{
    CurrentCompany::forget();

    try {
        return Livewire::actingAs($user)->withQueryParams(['rapport' => (string) $report->id])->test(AnalysisPage::class);
    } finally {
        Livewire::withQueryParams([]);
    }
}

it('shows a small group as Dold also when the report is opened by someone else', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');

    foreach ([$this->owner, $accountant] as $viewer) {
        $page = e18PayrollOpen($this, $viewer, $this->report);
        $result = $page->viewData('result');
        $bar = collect($result->rows)->firstWhere('label', '20 Bar');
        $kitchen = collect($result->rows)->firstWhere('label', '10 Kök');

        expect($result->cells[$bar['key']]['']['hidden'])->toBeTrue()
            ->and($result->cells[$bar['key']]['']['v'])->toBe(0)
            ->and($result->cells[$kitchen['key']]['']['hidden'])->toBeFalse();

        $page->assertSee('Dold');
    }
});

it('does not offer or open a pay report to a member without payroll:view', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(SavedReports::openableBy($bookkeeper, $this->company)->pluck('id')->all())->not->toContain($this->report->id);

    e18PayrollOpen($this, $bookkeeper, $this->report)->assertNotFound();
});

it('writes Dold in every file and never the small group\'s pay, or a total that contains it', function () {
    CurrentCompany::forget();
    $this->actingAs($this->owner);

    foreach (['csv', 'xlsx'] as $format) {
        $page = Livewire::withQueryParams(['rapport' => (string) $this->report->id])->test(AnalysisPage::class);
        Livewire::withQueryParams([]);
        $download = $page->call('export', $format);
        $content = base64_decode((string) data_get($download->effects, 'download.content'));
        $text = $format === 'csv' ? $content : implode("\n", array_map(fn (array $row): string => implode(';', $row), XlsxReader::rows($content)));

        $text = str_replace('"', '', $text);
        expect($text)->toContain('20 Bar;Dold')->toContain('Summa;Dold')
            ->and($text)->not->toContain('Bartender')->not->toContain('Kock1');
    }
});

it('shows a tile of pay as Dold when the total contains a small group, and carries no figure into the page', function () {
    app(PinReport::class)($this->owner, $this->company, $this->report, true);
    CurrentCompany::forget();
    $this->actingAs($this->owner);

    $tiles = Livewire::withoutLazyLoading()->test(ReportTiles::class);

    expect($tiles->viewData('tiles')[0]['hidden'])->toBeTrue()
        ->and($tiles->viewData('tiles')[0]['value'])->toBe(0);

    $tiles->assertSee('Lön per enhet')->assertSee('Dold');
});

it('answers 404 to every action on a pay report by someone without payroll:view', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect($bookkeeper->can('view', $this->report))->toBeFalse();

    foreach ([['openReport', [$this->report->id]], ['deleteReport', [$this->report->id]], ['pinReport', [$this->report->id, true]]] as [$method, $arguments]) {
        CurrentCompany::forget();
        Livewire::actingAs($bookkeeper)->test(AnalysisPage::class)->call($method, ...$arguments)->assertNotFound();
    }
});

it('does not let a sharer without payroll:view delete a pay report', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect($auditor->hasTeamPermission($this->company, 'analysis:share'))->toBeTrue()
        ->and($auditor->hasTeamPermission($this->company, 'payroll:view'))->toBeFalse()
        ->and($auditor->can('delete', $this->report))->toBeFalse()
        ->and($accountant->can('delete', $this->report))->toBeTrue();
});
