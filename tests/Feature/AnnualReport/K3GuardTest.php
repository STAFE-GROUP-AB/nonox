<?php

declare(strict_types=1);

/*
 * ADR-059 stage F.1, and S1-P10 since: a K3 year never gets the K2 document,
 * which says it follows BFNAR 2016:10. It gets the K3 document
 * (K3AnnualReportDocument, Beta) or, while that is refused, the reasons with
 * their rule ids; none of the ways to the K2 document or the K2 iXBRL file
 * lets a K3 year through.
 */

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\K3AnnualReportDocument;
use App\Domain\Company\ReportingFramework;
use App\Domain\IncomeTax\IncomeTaxForms;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\AnnualReport;
use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Support\Ixbrl\IxbrlRefused;
use App\Support\Ixbrl\K2AnnualReportIxbrl;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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

it('routes a K3 year to the K3 annual report and lists what refuses it, with the rule ids (S1-P10)', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    // The framework is the fiscal year's (ADR-059, A.2.1).
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(AnnualReportDocument::supports($this->company, $this->year))->toBeFalse()
        ->and(AnnualReportDocument::refusal($this->company, $this->year))->toBeNull()
        ->and(K3AnnualReportDocument::supports($this->company, $this->year))->toBeTrue()
        // No year before 2026 and no answer about the first year: the size cannot be determined (S1-R9).
        ->and(K3AnnualReportDocument::refusal($this->company, $this->year))->toContain('(S1-R9)');

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Det här hindrar årsredovisningen')
        ->assertSee('S1-R9')
        ->assertSee('Texter och underskrifter')
        ->assertSee('Uppgifter för K3')
        ->assertSee('Ingen förhandsvisning ännu')
        ->assertDontSee('Ladda ner PDF')
        ->assertDontSee('Ladda ner som iXBRL')
        ->assertDontSee('Årsredovisning enligt K3 finns inte ännu');
});

it('refuses the preview and the PDF of a refused K3 report, and the K2 iXBRL file of any K3 year', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    // The framework is the fiscal year's (ADR-059, A.2.1).
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    $this->get(route('accounting.annualReport.show', $this->year))->assertNotFound();
    $this->get(route('accounting.annualReport.pdf', $this->year))->assertRedirect();
    $this->get(route('accounting.annualReport.ixbrl', $this->year))->assertForbidden();

    $report = new AnnualReport(['fiscal_year_id' => $this->year->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0, 'business_description' => 'Kafé.']);

    expect(fn () => K2AnnualReportIxbrl::render($this->company, $this->year, $report))
        ->toThrow(IxbrlRefused::class, 'NONOX tar ännu inte fram årsredovisningen enligt K3 som iXBRL');
});

it('saves the texts of a K3 annual report, and keeps the income tax return', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    // The framework is the fiscal year's (ADR-059, A.2.1).
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => '2', 'business_description' => 'Kafé.']);

    expect($report->business_description)->toBe('Kafé.')
        ->and($report->average_employees)->toBe(2)
        // INK2 follows the legal form, not the framework.
        ->and(IncomeTaxForms::for($this->company))->toBe(IncomeTaxReturn::INK2);
});

it('refuses a company on K1 with its own explanation', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K1])->save();
    // The framework is the fiscal year's (ADR-059, A.2.1).
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K1])->save();

    expect(AnnualReportDocument::supports($this->company))->toBeFalse()
        ->and(AnnualReportDocument::refusal($this->company))->toContain('Räkenskapsåret har regelverket K1');

    Livewire::test(AnnualReportPage::class)
        ->assertSee('Inte för det här regelverket')
        ->assertDontSee('Årsredovisning enligt K3 finns inte ännu');

    $this->get(route('accounting.annualReport.pdf', $this->year))->assertForbidden();
});

it('puts no annual report in the archive package of a K3 company', function () {
    Storage::fake('local');
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    // The framework is the fiscal year's (ADR-059, A.2.1).
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    $names = k3GuardArchiveFiles(app(CreateArchivePackage::class)($this->owner, $this->year));

    expect($names)->not->toBeEmpty()
        ->and(array_filter($names, fn (string $name): bool => str_contains(mb_strtolower($name), 'arsredovisning')))->toBe([]);
});
