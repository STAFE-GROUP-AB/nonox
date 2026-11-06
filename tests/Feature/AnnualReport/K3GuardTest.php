<?php

declare(strict_types=1);

/*
 * ADR-010, addendum 2026-11-06: NONOX only makes a K2 annual report. A company that has
 * chosen K3 gets an explanation instead of a document that says it follows
 * BFNAR 2016:10, and none of the ways to the K2 document lets it through.
 */

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Company\ReportingFramework;
use App\Domain\IncomeTax\IncomeTaxForms;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\AnnualReport;
use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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

/** @return list<string> the file names in an archive package */
function k3GuardArchiveFiles(ArchivePackage $package): array
{
    $zipPath = tempnam(sys_get_temp_dir(), 'k3arkiv');
    file_put_contents($zipPath, Storage::disk('local')->get((string) $package->file_path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }

    $zip->close();
    unlink($zipPath);

    return $names;
}

it('makes the annual report of a K2 aktiebolag as before', function () {
    expect($this->company->reporting_framework)->toBe(ReportingFramework::K2)
        ->and(AnnualReportDocument::supports($this->company))->toBeTrue()
        ->and(AnnualReportDocument::refusal($this->company))->toBeNull();

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Texter och underskrifter')
        ->assertDontSee('NONOX tar ännu inte fram årsredovisningar enligt K3');

    $this->get(route('accounting.annualReport.show', $this->year))->assertOk();
});

it('explains on the page that NONOX does not make a K3 annual report yet', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Årsredovisning enligt K3 finns inte ännu')
        ->assertSee('NONOX tar ännu inte fram årsredovisningar enligt K3 (BFNAR 2012:1).')
        ->assertSee('ladda ner årets SIE-fil under Räkenskapsår')
        ->assertSee('redovisningskonsult')
        ->assertDontSee('Texter och underskrifter')
        ->assertDontSee('Ladda ner PDF');
});

it('refuses the preview and the PDF of a K3 company', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    $this->get(route('accounting.annualReport.show', $this->year))->assertForbidden();
    $this->get(route('accounting.annualReport.pdf', $this->year))->assertForbidden();
});

it('does not save the texts of a K3 annual report, but keeps the income tax return', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => '0']))
        ->toThrow(ValidationException::class, 'NONOX tar ännu inte fram årsredovisningar enligt K3')
        ->and(AnnualReport::query()->exists())->toBeFalse()
        // INK2 follows the legal form, not the framework.
        ->and(IncomeTaxForms::for($this->company))->toBe(IncomeTaxReturn::INK2);
});

it('refuses a company on K1 with its own explanation', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K1])->save();

    expect(AnnualReportDocument::supports($this->company))->toBeFalse()
        ->and(AnnualReportDocument::refusal($this->company))->toContain('Företaget har valt K1');

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Inte för det här regelverket')
        ->assertDontSee('Årsredovisning enligt K3 finns inte ännu');

    $this->get(route('accounting.annualReport.pdf', $this->year))->assertForbidden();
});

it('puts no annual report in the archive package of a K3 company', function () {
    Storage::fake('local');
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    $names = k3GuardArchiveFiles(app(CreateArchivePackage::class)($this->owner, $this->year));

    expect($names)->not->toBeEmpty()
        ->and(array_filter($names, fn (string $name): bool => str_contains(mb_strtolower($name), 'arsredovisning')))->toBe([]);
});
