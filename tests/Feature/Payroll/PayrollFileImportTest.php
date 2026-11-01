<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollFileImports;
use App\Modules\Payroll\Actions\PayrollImportSources;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\PayrollFileImportPage;
use App\Modules\Payroll\Livewire\PayrollImportSettings;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollFileImport;
use App\Modules\Payroll\Models\PayrollFileImportLine;
use App\Modules\Payroll\Models\PayrollImportMapping;
use App\Modules\Payroll\Models\PayrollImportSource;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * Löneunderlag from scheduling systems (PAXml or CSV) read into a payroll
 * run as proposals that a person approves. Fixtures are synthetic; the
 * personal numbers are Luhn-valid test numbers.
 */

function importFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/payroll/paxml/{$name}"));
}

/** @return array<string, PayrollFileImportLine> lines by external code and line number, e.g. "OB1#1" */
function importLines(PayrollFileImport $import): array
{
    return PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id)->orderBy('line_no')->get()
        ->mapWithKeys(fn (PayrollFileImportLine $line) => ["{$line->external_code}#{$line->line_no}" => $line])->all();
}

function payCodeId(string $code): int
{
    return (int) PayCode::query()->where('code', $code)->value('id');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->linda = Payroll::employee($this->owner, $this->company, ['first_name' => 'Linda', 'personal_number' => '198503024567', 'salary_form' => 'HOURLY', 'salary' => '180', 'employment_type' => 'TIMANSTALLD']);
    $this->run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-10');
    $this->source = app(PayrollImportSources::class)->create($this->owner, $this->company, 'Testschema');
    $this->imports = app(PayrollFileImports::class);
});

it('reads a PAXml file as proposals, matched by anställningsnummer and personal number', function () {
    expect([$this->anna->employee_number, $this->linda->employee_number])->toBe([1, 2]);

    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));
    $lines = importLines($import);

    expect($import->format)->toBe('PAXML')
        ->and($import->format_version)->toBe('2.2')
        ->and($import->report['ignored_sections'])->toBe(['schematransaktioner' => 1, 'personal' => 1])
        ->and($lines)->toHaveKeys(['OB1#1', 'ÖT1#2', 'OB1#3', 'ARB#4', 'SJK#5', 'OB2#6'])
        ->and($lines['OB1#1']->employee_id)->toBe($this->anna->id)
        ->and($lines['OB1#1']->description)->toBe('OB-tillägg kväll')
        ->and($lines['OB1#1']->problems)->toBe(['unmapped_code'])
        // Matched on the personal number; it is never stored, not even masked, for a known employee.
        ->and($lines['ÖT1#2']->employee_id)->toBe($this->linda->id)
        ->and($lines['ÖT1#2']->person_ref)->toBeNull()
        ->and($lines['ÖT1#2']->amount_ore->ore)->toBe(60_000)
        ->and($lines['OB1#3']->employee_id)->toBeNull()
        ->and($lines['OB1#3']->problems)->toBe(['unknown_employee', 'unmapped_code'])
        // Absence is never paid from a file.
        ->and($lines['SJK#5']->status)->toBe(PayrollFileImportLine::SKIPPED)
        ->and($lines['SJK#5']->problems)->toBe(['absence_code'])
        ->and(PayrollInput::query()->count())->toBe(0)
        ->and($this->run->fresh()->status)->toBe(PayrollRun::DRAFT);

    // The codes are added to the source system, unmapped, for the settings.
    expect(PayrollImportMapping::query()->where('payroll_import_source_id', $this->source->id)->orderBy('external_code')->pluck('external_code')->all())
        ->toBe(['ARB', 'OB1', 'OB2', 'ÖT1']);

    // The original is kept on the private disk; the audit log never holds a personal number.
    Storage::disk('local')->assertExists($import->file_path);
    $audit = AuditLog::query()->where('action', 'payroll.file_imported')->sole();
    expect($audit->payload['lines'])->toBe(6)
        ->and(json_encode($audit->payload))->not->toContain('198503024567');
});

it('maps codes once per source system, so the next file maps by itself', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));

    $this->imports->map($this->owner, $this->run, $import, 'OB1', payCodeId('OBTILLAGG'));
    $this->imports->map($this->owner, $this->run, $import, 'ÖT1', payCodeId('OVERTID'));
    $this->imports->map($this->owner, $this->run, $import, 'ARB', payCodeId('TIMLON'));
    $this->imports->map($this->owner, $this->run, $import, 'OB2', payCodeId('OBTILLAGG'));
    $lines = importLines($import);

    expect($lines['OB1#1']->problems)->toBe([])
        ->and($lines['OB1#1']->pay_code_id)->toBe(payCodeId('OBTILLAGG'))
        ->and($lines['ARB#4']->problems)->toBe([])
        // OB without a price would be paid at the full hourly rate: refused.
        ->and($lines['OB2#6']->problems)->toBe(['rate_required']);

    // A CSV file from the same system for November uses the saved mappings.
    $november = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    $csv = $this->imports->upload($this->owner, $november, $this->source, 'november.csv', importFixture('testkrogen.csv'));
    $csvLines = importLines($csv);

    expect($csv->format)->toBe('CSV')
        ->and($csvLines['OB1#1']->pay_code_id)->toBe(payCodeId('OBTILLAGG'))
        ->and($csvLines['ÖT1#2']->employee_id)->toBe($this->linda->id)
        ->and($csvLines['ARB#3']->problems)->toBe([])
        ->and($csvLines['OB1#1']->warnings)->toBe(['outside_period']);
});

it('pays approved lines only after a person approves them, and the run is calculated again', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));
    $this->imports->map($this->owner, $this->run, $import, 'OB1', payCodeId('OBTILLAGG'));
    $this->imports->map($this->owner, $this->run, $import, 'ÖT1', payCodeId('OVERTID'));
    app(PayrollRuns::class)->calculate($this->owner, $this->run);

    expect($this->run->fresh()->status)->toBe(PayrollRun::CALCULATED);

    $approved = $this->imports->approve($this->owner, $this->run, $import);
    $inputs = PayrollInput::query()->orderBy('id')->get();

    // OB1 for Anna and ÖT1 for Linda; the unknown employee's OB1 and the unmapped codes wait.
    expect($approved)->toBe(2)
        ->and($inputs->pluck('source')->unique()->all())->toBe(['IMPORT'])
        ->and($inputs->pluck('employee_id')->all())->toBe([$this->anna->id, $this->linda->id])
        ->and($this->run->fresh()->status)->toBe(PayrollRun::DRAFT)
        ->and(importLines($import)['OB1#3']->status)->toBe(PayrollFileImportLine::PROPOSED);

    app(PayrollRuns::class)->calculate($this->owner, $this->run->fresh());
    $annasLines = Payslip::query()->with('lines')->where('employee_id', $this->anna->id)->sole()->lines;
    $ob = $annasLines->firstWhere('pay_code_id', payCodeId('OBTILLAGG'));

    // 4,5 hours × 27,50 kr.
    expect($ob->amount_ore->ore)->toBe(12_375)
        ->and($ob->description)->toBe('OB-tillägg kväll');
});

it('reads the same file once per period', function () {
    $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));

    expect(fn () => $this->imports->upload($this->owner, $this->run, $this->source, 'kopia.xml', importFixture('testkrogen-2.2.xml')))
        ->toThrow(ValidationException::class, 'redan inläst');

    $november = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');

    expect($this->imports->upload($this->owner, $november, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'))->period)->toBe('2026-11');
});

it('reads the file again without touching approved lines', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));
    $this->imports->map($this->owner, $this->run, $import, 'OB1', payCodeId('OBTILLAGG'));
    $approvedLine = importLines($import)['OB1#1'];
    $this->imports->approve($this->owner, $this->run, $import, [$approvedLine->id]);
    $rejected = importLines($import)['OB1#3'];
    $this->imports->reject($this->owner, $this->run, $import, $rejected->id);

    // The unknown employee is added with anställningsnummer 99 … and the file is read again.
    Payroll::employee($this->owner, $this->company, ['first_name' => 'Nils', 'personal_number' => '199202021110'])->forceFill(['employee_number' => 99])->save();
    $this->imports->reprocess($this->owner, $this->run, $import);
    $lines = importLines($import);

    expect($lines['OB1#1']->id)->toBe($approvedLine->id)
        ->and($lines['OB1#1']->status)->toBe(PayrollFileImportLine::APPROVED)
        ->and(PayrollInput::query()->count())->toBe(1)
        ->and($lines['OB1#3']->status)->toBe(PayrollFileImportLine::PROPOSED)
        ->and($lines['OB1#3']->problems)->toBe([])
        ->and(PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id)->count())->toBe(6);
});

it('puts a line back as a proposal when its input is removed, and removes inputs with the file', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));
    $this->imports->map($this->owner, $this->run, $import, 'OB1', payCodeId('OBTILLAGG'));
    $this->imports->map($this->owner, $this->run, $import, 'ÖT1', payCodeId('OVERTID'));
    $this->imports->approve($this->owner, $this->run, $import);
    $first = PayrollInput::query()->orderBy('id')->firstOrFail();

    app(PayrollRuns::class)->removeInput($this->owner, $this->run, $first);

    expect(importLines($import)['OB1#1']->status)->toBe(PayrollFileImportLine::PROPOSED)
        ->and(importLines($import)['OB1#1']->payroll_input_id)->toBeNull();

    $this->imports->delete($this->owner, $this->run, $import);

    expect(PayrollInput::query()->count())->toBe(0)
        ->and(PayrollFileImport::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payroll.file_import_deleted')->exists())->toBeTrue();
    Storage::disk('local')->assertMissing($import->file_path);
});

it('takes back an approved line', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));
    $this->imports->map($this->owner, $this->run, $import, 'OB1', payCodeId('OBTILLAGG'));
    $line = importLines($import)['OB1#1'];
    $this->imports->approve($this->owner, $this->run, $import, [$line->id]);

    $this->imports->unapprove($this->owner, $this->run, $import, $line->id);

    expect($line->fresh()->status)->toBe(PayrollFileImportLine::PROPOSED)
        ->and(PayrollInput::query()->count())->toBe(0);
});

it('refuses lines that cannot be approved, and runs that are attested or users without payroll:run', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));

    expect(fn () => $this->imports->approve($this->owner, $this->run, $import))->toThrow(ValidationException::class, 'inga rader');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    expect(fn () => $this->imports->upload($bookkeeper, $this->run, $this->source, 'annan.csv', importFixture('testkrogen.csv')))
        ->toThrow(AuthorizationException::class);

    $this->run->forceFill(['status' => PayrollRun::APPROVED])->saveQuietly();
    expect(fn () => $this->imports->upload($this->owner, $this->run->fresh(), $this->source, 'annan.csv', importFixture('testkrogen.csv')))
        ->toThrow(AuthorizationException::class);
});

it('refuses a file it cannot read, with the reason', function () {
    expect(fn () => $this->imports->upload($this->owner, $this->run, $this->source, 'farlig.xml', '<?xml version="1.0"?><!DOCTYPE paxml [<!ENTITY x SYSTEM "file:///etc/passwd">]><paxml/>'))
        ->toThrow(ValidationException::class, 'DOCTYPE');

    expect(PayrollFileImport::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('reads a file from the run page and shows its lines for approval', function () {
    $file = UploadedFile::fake()->createWithContent('oktober.xml', importFixture('testkrogen-2.2.xml'));

    Livewire::test(PayrollRunPage::class, ['run' => $this->run])
        ->set('importSource', 'new')
        ->set('newSourceName', 'Personalkollen')
        ->set('payrollFile', $file)
        ->call('importPayrollFile')
        ->assertHasNoErrors()
        ->assertRedirect();

    $import = PayrollFileImport::query()->sole();

    expect($import->source->name)->toBe('Personalkollen')
        ->and(collect(session(Toast::SESSION_KEY))->pluck('message')->all())->toBe(['oktober.xml är inläst. Kontrollera raderna och godkänn dem.']);

    $this->get(route('payroll.runs.imports.show', [$this->run, $import]))->assertOk()->assertSee('Koppla koder till lönearter');

    Livewire::test(PayrollFileImportPage::class, ['run' => $this->run, 'import' => $import])
        ->set('filter', 'anmarkning')
        ->assertSee('Ingen anställd har det anställningsnumret eller personnumret.')
        ->call('map', 'OB1', (string) payCodeId('OBTILLAGG'))
        ->assertDispatched('toast')
        ->call('map', 'ÖT1', 'ignore')
        ->set('filter', 'att-godkanna')
        ->assertSee('Godkänn 1 rader utan anmärkning')
        ->call('approveAll')
        ->assertDispatched('toast');

    expect(PayrollInput::query()->count())->toBe(1)
        ->and(PayrollImportMapping::query()->where('external_code', 'ÖT1')->value('ignored'))->toBeTrue();

    Livewire::test(PayrollRunPage::class, ['run' => $this->run->fresh()])->assertSee('oktober.xml');
});

it('lets only payroll:view-sensitive download the original file, and audits it', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));

    $this->get(route('payroll.runs.imports.file', [$this->run, $import]))->assertNotFound();

    $this->actingAs(Bookkeeping::member($this->company, 'payroll_admin'))
        ->get(route('payroll.runs.imports.file', [$this->run, $import]))
        ->assertOk()
        ->assertDownload('oktober.xml');

    expect(AuditLog::query()->where('action', 'payroll.file_downloaded')->count())->toBe(1);
});

it('never shows another company its files', function () {
    $import = $this->imports->upload($this->owner, $this->run, $this->source, 'oktober.xml', importFixture('testkrogen-2.2.xml'));
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $other, $otherOwner);

    $this->actingAs($otherOwner)->get(route('payroll.runs.imports.show', [$this->run, $import]))->assertNotFound();
    $this->actingAs($otherOwner)->get(route('payroll.runs.imports.file', [$this->run, $import]))->assertNotFound();
});

it('keeps source systems and mappings in the payroll settings', function () {
    Livewire::test(PayrollImportSettings::class, ['companyId' => $this->company->id])
        ->set('newSourceName', 'Caspeco')
        ->call('addSource')
        ->assertDispatched('toast');

    $caspeco = PayrollImportSource::query()->where('name', 'Caspeco')->sole();

    Livewire::test(PayrollImportSettings::class, ['companyId' => $this->company->id])
        ->set("newMapping.{$caspeco->id}.code", 'ob2')
        ->set("newMapping.{$caspeco->id}.choice", (string) payCodeId('OBTILLAGG'))
        ->call('addMapping', $caspeco->id)
        ->assertHasNoErrors()
        ->assertSee('OB2');

    expect(PayrollImportMapping::query()->where('payroll_import_source_id', $caspeco->id)->sole()->pay_code_id)->toBe(payCodeId('OBTILLAGG'))
        ->and(AuditLog::query()->where('action', 'payroll.import_mapping_saved')->exists())->toBeTrue();

    // Absence pay codes are never a target for a file.
    expect(fn () => app(PayrollImportSources::class)->map($this->owner, $this->company, $caspeco, 'SJ', payCodeId('SJUKLON')))
        ->toThrow(ValidationException::class);

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    expect(fn () => app(PayrollImportSources::class)->create($bookkeeper, $this->company, 'Planday'))->toThrow(AuthorizationException::class);
});
