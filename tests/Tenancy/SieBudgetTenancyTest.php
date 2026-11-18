<?php

declare(strict_types=1);

use App\Actions\Accounting\AnalyseSieFile;
use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Accounting\ExportSie;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Domain\Budget\Sie\SieBudgetAnalyser;
use App\Models\Account;
use App\Models\BudgetLine;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;

/*
 * SIE #PBUDGET (ADR-081 E4): accounts and objects are matched by number and
 * code inside the importing company only, and the export writes only the
 * company's own budget.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));

    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    $this->foreignCentre = CurrentCompany::run($this->companyB, function (): int {
        $centre = CostCenter::create(['code' => '77', 'name' => 'Hemligt ställe B'])->id;
        $version = app(CreateBudgetVersion::class)($this->ownerB, $this->companyB, [
            'fiscal_year_id' => FiscalYear::query()->whereDate('start_date', '2026-01-01')->value('id'),
            'name' => 'Hemlig budget B',
            'start' => CreateBudgetVersion::EMPTY,
        ]);

        app(SaveBudgetLines::class)($this->ownerB, $version, [[
            'account_id' => (int) Account::query()->where('number', 5010)->value('id'),
            'cost_center_id' => $centre, 'project_id' => null, 'amounts' => ['2026-02' => 99_999_900],
        ]]);

        return $centre;
    });

    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    $this->companyA->forceFill(['org_number' => '556123-4567'])->save();
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);
});

function e4TenancyFile(array $lines): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'e4ten');
    file_put_contents($path, implode("\r\n", ['#FLAGGA 0', '#FORMAT PC8', '#SIETYP 4', '#ORGNR 556123-4567', '#RAR 0 20260101 20261231', ...$lines])."\r\n");

    return $path;
}

it('never writes another company\'s budget into the SIE file', function () {
    $stream = fopen('php://memory', 'w+b');
    app(ExportSie::class)($this->ownerA, FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole(), $stream, true);
    rewind($stream);
    $file = (string) stream_get_contents($stream);

    expect($file)->not->toContain('#PBUDGET')
        ->and($file)->not->toContain('999999')
        ->and($file)->not->toContain('"77"');
});

it('does not find another company\'s cost centre when a budget file is checked', function () {
    $plan = app(SieBudgetAnalyser::class)->analyse($this->companyA, e4TenancyFile([
        '#PBUDGET 0 202602 5010 {1 "77"} 100.00',
    ]));

    expect(implode(' ', $plan->blockers))->toContain('Kostnadsställe 77 finns inte här')
        ->and($plan->lines)->toBe([]);
});

it('puts an imported budget on the company\'s own cost centre, never on another company\'s', function () {
    $path = e4TenancyFile([
        '#PBUDGET 0 202602 5010 {} 100.00',
        '#PBUDGET 0 202602 5010 {1 "77"} 100.00',
        '#VER A 1 20260210 "Test"', '{', '#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00', '}',
    ]);

    $import = app(AnalyseSieFile::class)($this->ownerA, $this->companyA, UploadedFile::fake()->createWithContent('bokforing.se', (string) file_get_contents($path)));
    app(ConfirmSieImport::class)($this->ownerA, $import);

    $own = CostCenter::query()->where('code', '77')->sole();
    $lines = DB::table('budget_lines')->where('company_id', $this->companyA->id)->get();

    expect($import->fresh()->status)->toBe(SieImportStatus::Completed)
        ->and($own->id)->not->toBe($this->foreignCentre)
        ->and($lines->pluck('cost_center_id')->filter()->values()->all())->toBe([$own->id])
        ->and(DB::table('budget_lines')->where('cost_center_id', $this->foreignCentre)->where('company_id', '!=', $this->companyB->id)->exists())->toBeFalse()
        ->and(BudgetLine::query()->count())->toBe(1);
});
