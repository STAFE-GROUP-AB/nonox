<?php

declare(strict_types=1);

use App\Models\RegistryChange;
use App\Models\RegistryCompany;
use App\Models\RegistryImport;
use App\Support\CompanyRegistry\Import\ScbBulkFile;
use App\Support\CompanyRegistry\Sni;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The company register (ADR-047): SCB's and Bolagsverket's bulk files and
 * SCB's API into company_registry, legal persons only, idempotent,
 * resumable, with changes recorded for watched companies. Synthetic
 * fixtures only (tests/Fixtures/registry, organisation numbers 559999 6xxx).
 */

function registryFixture(string $name): string
{
    return base_path("tests/Fixtures/registry/{$name}");
}

/** The fixture zipped, as the real files come. */
function registryZip(string $name): string
{
    $zip = new ZipArchive;
    $path = sys_get_temp_dir().'/nonox-registry-'.uniqid().'.zip';
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFile(registryFixture($name), str_replace('.txt', '_20261102.txt', $name));
    $zip->close();

    return $path;
}

beforeEach(function () {
    // The register is instance data; start every test from an empty one inside the test's transaction.
    DB::table('company_registry_changes')->delete();
    DB::table('company_registry')->delete();
    DB::table('company_registry_imports')->delete();
});

it('loads the legal persons of SCB\'s bulk file and skips natural persons and estates', function () {
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryZip('scb_bulkfil.txt')])->assertSuccessful();

    expect(RegistryCompany::query()->count())->toBe(9)
        ->and(RegistryCompany::query()->where('name', 'like', '%Exempelsson%')->exists())->toBeFalse()
        ->and(RegistryCompany::query()->where('name', 'like', 'Dödsbo%')->exists())->toBeFalse();

    $company = RegistryCompany::query()->where('org_number', '5599996047')->firstOrFail();

    expect($company->name)->toBe('Åkeri Ödmjuk Exempel AB')
        ->and($company->city)->toBe('MALMÖ')
        ->and($company->town())->toBe('Malmö')
        ->and($company->legal_form)->toBe('49')
        ->and($company->sni_code)->toBe('49410')
        ->and($company->sni_division)->toBe('49')
        ->and($company->registered_on?->toDateString())->toBe('2012-05-15')
        ->and($company->sources)->toBe([ScbBulkFile::KEY])
        ->and($company->isInBusiness())->toBeTrue();

    expect(RegistryCompany::query()->where('org_number', '5599996021')->value('advertising_block'))->toBeTrue()
        ->and(RegistryCompany::query()->inBusiness()->count())->toBe(8);

    $import = RegistryImport::query()->sole();
    expect($import->status)->toBe(RegistryImport::DONE)
        ->and($import->rows_read)->toBe(11)
        ->and($import->rows_written)->toBe(9)
        ->and($import->rows_skipped)->toBe(2);
});

it('completes the companies from Bolagsverket\'s file without adding any', function () {
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt')])->assertSuccessful();
    $this->artisan('registry:import', ['source' => 'bolagsverket-bulk', '--file' => registryZip('bolagsverket_bulkfil.txt')])->assertSuccessful();

    expect(RegistryCompany::query()->count())->toBe(9)
        ->and(RegistryCompany::query()->where('org_number', '5599996112')->exists())->toBeFalse();

    $bankrupt = RegistryCompany::query()->where('org_number', '5599996062')->firstOrFail();
    $deregistered = RegistryCompany::query()->where('org_number', '5599996054')->firstOrFail();
    $coder = RegistryCompany::query()->where('org_number', '5599996013')->firstOrFail();

    expect($bankrupt->proceedings)->toBe('KK')
        ->and($bankrupt->proceedings_since?->toDateString())->toBe('2026-10-01')
        ->and($bankrupt->isInBusiness())->toBeFalse()
        ->and($deregistered->deregistered_on?->toDateString())->toBe('2025-06-30')
        ->and($deregistered->deregistration_reason)->toBe('AVREG')
        ->and($coder->registered_on?->toDateString())->toBe('2019-03-01')
        ->and($coder->sources)->toBe(['bolagsverket-bulk', 'scb-bulk']);

    // Run the SCB file again: Bolagsverket's registration date is kept.
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt'), '--force' => true])->assertSuccessful();
    expect($coder->fresh()?->registered_on?->toDateString())->toBe('2019-03-01');
});

it('is idempotent, skips an unchanged file and resumes an unfinished run', function () {
    $file = registryFixture('scb_bulkfil.txt');
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $file])->assertSuccessful();
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $file])->expectsOutputToContain('har inte ändrats')->assertSuccessful();

    expect(RegistryImport::query()->count())->toBe(1);

    // An unfinished run that had read four rows continues after them.
    $first = RegistryImport::query()->sole();
    $unfinished = RegistryImport::create(['source' => 'scb-bulk', 'version' => $first->version, 'status' => RegistryImport::FAILED, 'position' => '4', 'rows_read' => 4, 'started_at' => now()]);
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $file, '--resume' => true])->assertSuccessful();

    expect($unfinished->fresh()?->status)->toBe(RegistryImport::DONE)
        ->and($unfinished->fresh()?->rows_read)->toBe(11)
        ->and(RegistryCompany::query()->count())->toBe(9);
});

it('records changes only for companies some CRM watches, and marks companies that left the file', function () {
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt')])->assertSuccessful();
    expect(RegistryChange::query()->count())->toBe(0);

    [$owner, $company] = Bookkeeping::company();
    Crm::enable($company, $owner);
    Crm::prospect($company, $owner, 'Kodverket Exempel AB', ['org_number' => '559999-6013']);

    // The same companies, one moved and one renamed (watched and not watched).
    $changed = str_replace(['Exempelgatan 1', 'Molntjänst Exempel AB'], ['Nya gatan 10', 'Molnet Exempel AB'], (string) file_get_contents(registryFixture('scb_bulkfil.txt')));
    $path = sys_get_temp_dir().'/nonox-scb-'.uniqid().'.txt';
    file_put_contents($path, $changed);
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->assertSuccessful();

    expect(RegistryChange::query()->get(['org_number', 'field', 'old_value', 'new_value'])->toArray())
        ->toBe([['org_number' => '5599996013', 'field' => 'address_line1', 'old_value' => 'Exempelgatan 1', 'new_value' => 'Nya gatan 10']]);

    // A file without most companies marks none as gone (a broken download); a complete one marks the missing.
    $lines = explode("\r\n", $changed);
    file_put_contents($path, implode("\r\n", array_slice($lines, 0, 3))."\r\n");
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->expectsOutputToContain('Inga markeras som borta')->assertSuccessful();
    expect(RegistryCompany::query()->whereNotNull('gone_at')->count())->toBe(0);

    file_put_contents($path, implode("\r\n", array_merge(array_slice($lines, 0, 9), array_slice($lines, 10))));
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->assertSuccessful();
    expect(RegistryCompany::query()->whereNotNull('gone_at')->pluck('org_number')->all())->toBe(['5599996096']);
});

it('reads SCB\'s API with the key, page by page, and refuses to run without one', function () {
    config(['services.company_register.scb_api_key' => null]);
    $this->artisan('registry:import', ['source' => 'scb-api'])->expectsOutputToContain('SCB_AFR_API_KEY')->assertFailed();

    config(['services.company_register.scb_api_key' => 'test-nyckel', 'services.company_register.scb_api_url' => 'https://afr.example.test/v1/']);
    Sleep::fake();
    $entity = fn (string $org, string $name, array $extra = []): array => [
        'peOrgNr' => "16{$org}", 'orgNr' => $org, 'namn' => $name, 'jurform' => '49', 'ftgStat' => '1',
        'postAdress' => ['gatuAdress' => 'Exempelgatan 1', 'coAdress' => '', 'postNr' => '111 22', 'postOrt' => 'STOCKHOLM'],
        'kommunSate' => '0180', 'lanSate' => '01', 'anstKl' => '3', 'omsKl' => '4', 'omsAr' => 2025, 'tel' => '08-46500400', 'epost' => 'info@exempel.test',
        'reklamSparrTyp' => 3, 'telefonSparrTyp' => 2, 'epostSparrTyp' => 1, 'bolStat' => '0',
        'naringsgrenar' => [['rangordning' => 2, 'naringsgren' => '62201'], ['rangordning' => 1, 'naringsgren' => '62100']],
        ...$extra,
    ];

    Http::fake([
        'afr.example.test/v1/kodtabeller/kommunkoder' => Http::response([['kod' => '0180', 'klartext' => 'Stockholm']]),
        'afr.example.test/v1/kodtabeller/bolstatkoder' => Http::response([['kod' => '0', 'klartext' => 'Normalläge']]),
        'afr.example.test/v1/juridiskaenheter/full?limit=5000' => Http::response(['jes' => [$entity('5599996013', 'Kodverket Exempel AB'), ['peOrgNr' => '198001019996', 'namn' => 'Exempelsson, Erik', 'jurform' => '10']], 'pagination' => ['nextCursorId' => 77, 'hasMore' => true]]),
        'afr.example.test/v1/juridiskaenheter/full?limit=5000&cursorId=77' => Http::sequence()->push(['title' => 'Too Many Requests'], 429, ['Retry-After' => '2'])->push(['jes' => [$entity('5599996021', 'Molntjänst Exempel AB', ['anstKl' => '0'])], 'pagination' => ['nextCursorId' => 0, 'hasMore' => false]]),
    ]);

    $this->artisan('registry:import', ['source' => 'scb-api'])->assertSuccessful();

    $coder = RegistryCompany::query()->where('org_number', '5599996013')->firstOrFail();
    expect($coder->employee_class)->toBe(3)
        ->and($coder->turnover_class)->toBe(4)
        ->and($coder->county_code)->toBe('01')
        ->and($coder->municipality_code)->toBe('0180')
        ->and($coder->postal_code)->toBe('11122')
        ->and($coder->sni_code)->toBe('62100')
        ->and($coder->sni_codes)->toBe(['62100', '62201'])
        ->and($coder->hasPhoneBlock())->toBeTrue()
        ->and(RegistryCompany::query()->where('org_number', '5599996021')->value('employee_class'))->toBeNull()
        ->and(RegistryCompany::query()->count())->toBe(2)
        ->and(DB::table('company_registry_codes')->where('kind', 'municipality')->value('name'))->toBe('Stockholm');

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-nyckel'));
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 2);
});

it('names SNI 2025 codes and divisions from SCB\'s classification', function () {
    expect(Sni::name('62100'))->toBe('Dataprogrammering')
        ->and(Sni::name('62.100'))->toBe('Dataprogrammering')
        ->and(Sni::label('69201'))->toBe('69201 Redovisning och bokföring; skatterådgivning')
        ->and(Sni::divisions())->toHaveCount(87)
        ->and(Sni::sections())->toHaveCount(22)
        ->and(array_map('strval', array_keys(Sni::search('måleri'))))->toContain('43341');
});
