<?php

declare(strict_types=1);

use App\Actions\Analysis\PinReport;
use App\Actions\Analysis\SaveReport;
use App\Domain\Analysis\Reports\SavedReports;
use App\Livewire\Analysis\AnalysisPage;
use App\Livewire\Overview\ReportTiles;
use App\Models\AuditLog;
use App\Models\ReportDefinition;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for saved reports (ADR-081 E18): company A never sees,
 * opens, changes, deletes, pins or gets a tile of company B's reports, also
 * when B shared them with its whole company. Another company's id answers 404.
 */
beforeEach(function () {
    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::run($company, function () use ($owner, $company, $side): void {
            $report = app(SaveReport::class)($owner, $company, [
                'dataset' => 'ledger', 'rows' => ['account'], 'measure' => 'debit', 'from' => '2026-01', 'to' => '2026-12',
                'name' => "Hemlig rapport {$side}", 'visibility' => 'COMPANY', 'rolling' => false,
            ]);
            app(PinReport::class)($owner, $company, $report, true);
            $this->{$side} = compact('owner', 'company', 'report');
        });
    }

    CurrentCompany::forget();
    $this->actingAs($this->a['owner']);
    CurrentCompany::set($this->a['company']);
});

it('lists only the company\'s own reports and tiles', function () {
    expect(SavedReports::openableBy($this->a['owner'], $this->a['company'])->pluck('name')->all())->toBe(['Hemlig rapport a']);

    Livewire::test(AnalysisPage::class)->assertSee('Hemlig rapport a')->assertDontSee('Hemlig rapport b');

    $tiles = Livewire::withoutLazyLoading()->test(ReportTiles::class);

    expect(array_column($tiles->viewData('tiles'), 'name'))->toBe(['Hemlig rapport a']);
    $tiles->assertDontSee('Hemlig rapport b');
});

it('answers 404 for another company\'s report by id, in the URL and by every action', function () {
    $id = $this->b['report']->id;

    Livewire::withQueryParams(['rapport' => (string) $id])->test(AnalysisPage::class)->assertNotFound();
    Livewire::withQueryParams([]);

    foreach ([['openReport', [$id]], ['deleteReport', [$id]], ['pinReport', [$id, false]]] as [$method, $arguments]) {
        Livewire::test(AnalysisPage::class)->call($method, ...$arguments)->assertNotFound();
    }

    expect(ReportDefinition::query()->withoutGlobalScopes()->whereKey($id)->value('pinned'))->toBeTrue()
        ->and(ReportDefinition::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('does not let a stored dataset or id from another company\'s report update it', function () {
    $other = ReportDefinition::query()->withoutGlobalScopes()->findOrFail($this->b['report']->id);

    expect(fn () => app(SaveReport::class)($this->a['owner'], $this->a['company'], [
        'dataset' => 'ledger', 'name' => 'Kapad', 'visibility' => 'COMPANY', 'rolling' => false,
    ], $other))->toThrow(NotFoundHttpException::class);

    expect($other->fresh()->name)->toBe('Hemlig rapport b');
});

it('writes the audit entry of an export in the company it was made in', function () {
    Bookkeeping::post($this->a['owner'], $this->a['company'], 100_000, 1930, 3001, '2026-03-01');
    Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12')->call('export', 'csv');

    $entry = AuditLog::query()->withoutGlobalScopes()->where('action', 'analysis.exported')->firstOrFail();

    expect($entry->company_id)->toBe($this->a['company']->id);
});
