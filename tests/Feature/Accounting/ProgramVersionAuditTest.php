<?php

declare(strict_types=1);

/*
 * #43 (AUD-BOK-8): BFNAR 2013:2 p. 9.16 andra stycket with comment (read
 * 2026-11-02): the behandlingshistorik also describes changes to the
 * bookkeeping system that affect how entries are processed, and when they
 * were made — for example when a new program version is taken into use.
 * NONOX writes `system.version_deployed` once per new version or commit and
 * carries it into every company's archive package.
 */

use App\Actions\Accounting\CreateArchivePackage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Build\BuildInfo;
use App\Support\Build\VersionRecorder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;

afterEach(fn () => BuildInfo::fake(null));

function programVersionDeploy(int $build, string $shaChar): ?AuditLog
{
    BuildInfo::fake(['build' => $build, 'sha' => str_repeat($shaChar, 40), 'built_at' => '2026-11-02T10:00:00+01:00']);

    return VersionRecorder::recordProgram();
}

it('records a program version once, and again when the version or commit changes', function () {
    $first = programVersionDeploy(9001, 'b');

    expect($first)->not->toBeNull()
        ->and($first->company_id)->toBeNull()
        ->and($first->user_id)->toBeNull()
        ->and($first->payload)->toMatchArray(['version' => config('app.version').'.9001', 'build' => 9001, 'sha' => str_repeat('b', 40)])
        ->and(programVersionDeploy(9001, 'b'))->toBeNull();

    $second = programVersionDeploy(9002, 'c');

    expect($second->payload)->toMatchArray(['version' => config('app.version').'.9002', 'previous_version' => config('app.version').'.9001']);
});

it('runs from the command', function () {
    BuildInfo::fake(['build' => 9003, 'sha' => str_repeat('d', 40), 'built_at' => null]);

    $this->artisan('nonox:record-version')->expectsOutputToContain('registrerad i behandlingshistoriken')->assertSuccessful();
    $this->artisan('nonox:record-version')->expectsOutputToContain('redan registrerad')->assertSuccessful();
});

it('carries the program versions into every company\'s behandlingshistorik in the archive package, but no other company\'s entries', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    programVersionDeploy(9004, 'e');
    $other = Company::factory()->create();
    AuditLog::create(['company_id' => $other->id, 'action' => 'company.updated', 'payload' => ['secret' => 'annat bolag']]);

    $package = app(CreateArchivePackage::class)($owner, $year);
    $zipPath = (string) tempnam(sys_get_temp_dir(), 'arkiv');
    file_put_contents($zipPath, Storage::disk('local')->get((string) $package->file_path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $json = (string) $zip->getFromName(ArchivePackageBuilder::name($company, $year).'/behandlingshistorik/Behandlingshistorik.json');
    $zip->close();
    unlink($zipPath);
    $entries = collect(json_decode($json, true));

    // Other installs of the test database may already hold earlier version entries; ours is the latest.
    expect($entries->where('action', 'system.version_deployed')->last()['payload'] ?? null)->toMatchArray(['build' => 9004])
        ->and($json)->not->toContain('annat bolag');
});
