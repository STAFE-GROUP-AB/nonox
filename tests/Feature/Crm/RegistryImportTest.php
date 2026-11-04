<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\RegistryChange;
use App\Models\RegistryCompany;
use App\Models\RegistryImport;
use App\Modules\Crm\Models\Activity;
use App\Support\CompanyRegistry\Import\ScbBulkFile;
use App\Support\CompanyRegistry\Sni;
use App\Support\Tenancy\CurrentCompany;
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

it('records changes only for companies some CRM watches, and deletes companies missing from two runs in a row', function () {
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt')])->assertSuccessful();
    expect(RegistryChange::query()->count())->toBe(0);

    [$owner, $company] = Bookkeeping::company();
    Crm::enable($company, $owner);
    Crm::customer($company, $owner, 'Kodverket Exempel AB', ['org_number' => '559999-6013']);
    // A prospect is watched only where the company has "Bevaka även prospekt" on.
    Crm::prospect($company, $owner, 'Molntjänst Exempel AB', ['org_number' => '559999-6021']);

    // The same companies, one moved and one renamed (a watched customer and an unwatched prospect).
    $changed = str_replace(['Exempelgatan 1', 'Molntjänst Exempel AB'], ['Nya gatan 10', 'Molnet Exempel AB'], (string) file_get_contents(registryFixture('scb_bulkfil.txt')));
    $path = sys_get_temp_dir().'/nonox-scb-'.uniqid().'.txt';
    file_put_contents($path, $changed);
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->assertSuccessful();

    expect(RegistryChange::query()->get(['org_number', 'field', 'old_value', 'new_value'])->toArray())
        ->toBe([['org_number' => '5599996013', 'field' => 'address_line1', 'old_value' => 'Exempelgatan 1', 'new_value' => 'Nya gatan 10']]);

    // A file without most companies deletes none (a broken download); a complete one deletes the missing, and their changes.
    $lines = explode("\r\n", $changed);
    file_put_contents($path, implode("\r\n", array_slice($lines, 0, 3))."\r\n");
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->expectsOutputToContain('Inga tas bort')->assertSuccessful();
    expect(RegistryCompany::query()->count())->toBe(9);

    // Missing from one complete run: kept (one bad week, or the file and the API disagree).
    RegistryChange::create(['org_number' => '5599996096', 'field' => 'name', 'old_value' => 'A', 'new_value' => 'B', 'source' => 'scb-bulk', 'detected_at' => now()]);
    $without = implode("\r\n", array_merge(array_slice($lines, 0, 9), array_slice($lines, 10)));
    file_put_contents($path, $without);
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->assertSuccessful();
    expect(RegistryCompany::query()->where('org_number', '5599996096')->value('missed_runs'))->toBe(1);

    // Seen again: the count starts over. Then missing from two runs in a row: deleted, with its changes (unwatched).
    file_put_contents($path, $changed);
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path, '--force' => true])->assertSuccessful();
    expect(RegistryCompany::query()->where('org_number', '5599996096')->value('missed_runs'))->toBe(0);

    foreach ([1, 2] as $run) {
        file_put_contents($path, $without.str_repeat("\r\n", $run));
        $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->assertSuccessful();
    }

    expect(RegistryCompany::query()->where('org_number', '5599996096')->exists())->toBeFalse()
        ->and(RegistryCompany::query()->count())->toBe(8)
        ->and(RegistryChange::query()->where('org_number', '5599996096')->exists())->toBeFalse();
});

it('tells a company when a customer leaves the register, then forgets the change', function () {
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt')])->assertSuccessful();

    [$owner, $company] = Bookkeeping::company();
    Crm::enable($company, $owner);
    $account = Crm::accountFor(Crm::customer($company, $owner, 'Kodverket Exempel AB', ['org_number' => '559999-6013']));
    $this->travel(1)->minutes();

    $lines = explode("\r\n", (string) file_get_contents(registryFixture('scb_bulkfil.txt')));
    $path = sys_get_temp_dir().'/nonox-scb-'.uniqid().'.txt';

    foreach ([1, 2] as $run) {
        file_put_contents($path, implode("\r\n", array_merge([$lines[0]], array_slice($lines, 2))).str_repeat("\r\n", $run));
        $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->assertSuccessful();
    }

    expect(RegistryCompany::query()->where('org_number', '5599996013')->exists())->toBeFalse()
        ->and(RegistryChange::query()->where('org_number', '5599996013')->pluck('field')->all())->toBe(['removed']);

    $this->artisan('crm:registry-watch')->assertSuccessful();

    CurrentCompany::set($company);
    $task = Activity::query()->where('account_id', $account->id)->sole();
    expect($task->subject)->toBe('Ändring hos kunden: finns inte längre i SCB:s register')
        ->and($task->body)->toContain('Företaget finns inte längre i SCB:s register')
        ->and(RegistryChange::query()->where('org_number', '5599996013')->exists())->toBeFalse();
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

it('never brings back what an objection or erasure has excluded, whatever the source', function () {
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt')])->assertSuccessful();

    $this->artisan('registry:exclude', ['org_number' => '559999-6013', '--field' => 'ALL'])->assertSuccessful();
    $this->artisan('registry:exclude', ['org_number' => '559999-6039', '--field' => 'NAME', '--reason' => 'ERASURE'])->assertSuccessful();
    $this->artisan('registry:exclude', ['org_number' => '199001019996', '--field' => 'ALL'])->assertFailed();

    expect(RegistryCompany::query()->where('org_number', '5599996013')->exists())->toBeFalse()
        ->and(RegistryCompany::query()->where('org_number', '5599996039')->value('name'))->toBe('Företag 559999-6039');

    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt'), '--force' => true])->assertSuccessful();
    $this->artisan('registry:import', ['source' => 'bolagsverket-bulk', '--file' => registryFixture('bolagsverket_bulkfil.txt')])->assertSuccessful();

    expect(RegistryCompany::query()->where('org_number', '5599996013')->exists())->toBeFalse()
        ->and(RegistryCompany::query()->where('org_number', '5599996039')->value('name'))->toBe('Företag 559999-6039')
        ->and(AuditLog::query()->where('action', 'hq.registry_exclusion_added')->count())->toBe(2)
        ->and(json_encode(AuditLog::query()->where('action', 'hq.registry_exclusion_added')->pluck('payload')))->not->toContain('559999');

    // Phone and e-mail from the API are left empty for a PHONE or EMAIL exclusion.
    $this->artisan('registry:exclude', ['org_number' => '5599996021', '--field' => 'PHONE'])->assertSuccessful();
    config(['services.company_register.scb_api_key' => 'test-nyckel', 'services.company_register.scb_api_url' => 'https://afr.example.test/v1/']);
    Sleep::fake();
    Http::fake([
        'afr.example.test/v1/kodtabeller/*' => Http::response([]),
        'afr.example.test/v1/juridiskaenheter/full*' => Http::response(['jes' => [['peOrgNr' => '165599996021', 'namn' => 'Molntjänst Exempel AB', 'jurform' => '49', 'ftgStat' => '1', 'tel' => '08-46500400', 'epost' => 'info@molnet.test']], 'pagination' => ['hasMore' => false]]),
    ]);
    $this->artisan('registry:import', ['source' => 'scb-api'])->assertSuccessful();

    $cloud = RegistryCompany::query()->where('org_number', '5599996021')->firstOrFail();
    expect($cloud->phone)->toBeNull()->and($cloud->email)->toBe('info@molnet.test');

    // Removing the exclusion lets the next import bring the data back.
    $this->artisan('registry:exclude', ['org_number' => '559999-6013', '--field' => 'ALL', '--remove' => true])->assertSuccessful();
    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => registryFixture('scb_bulkfil.txt'), '--force' => true])->assertSuccessful();
    expect(RegistryCompany::query()->where('org_number', '5599996013')->exists())->toBeTrue();
});

it('warns and records it when most companies of a file come out as opted out', function () {
    $blocked = str_replace("\t1\t1\t", "\t1\t2\t", (string) file_get_contents(registryFixture('scb_bulkfil.txt')));
    $lines = array_map(fn (string $line): string => preg_replace('/\t(\d{8})\t1\t/', "\t$1\t2\t", $line) ?? $line, explode("\r\n", $blocked));
    $path = sys_get_temp_dir().'/nonox-scb-'.uniqid().'.txt';
    file_put_contents($path, implode("\r\n", $lines));

    $this->artisan('registry:import', ['source' => 'scb-bulk', '--file' => $path])->expectsOutputToContain('reklamspärr enligt filen')->assertSuccessful();

    expect(RegistryImport::query()->sole()->warning)->toContain('scbforetag@scb.se');
});

it('names SNI 2025 codes and divisions from SCB\'s classification', function () {
    expect(Sni::name('62100'))->toBe('Dataprogrammering')
        ->and(Sni::name('62.100'))->toBe('Dataprogrammering')
        ->and(Sni::label('69201'))->toBe('69201 Redovisning och bokföring; skatterådgivning')
        ->and(Sni::divisions())->toHaveCount(87)
        ->and(Sni::sections())->toHaveCount(22)
        ->and(array_map('strval', array_keys(Sni::search('måleri'))))->toContain('43341');
});
