<?php

declare(strict_types=1);

/*
 * #44 (AUD-BOK-9): BFL 5 kap. 11 § and BFNAR 2013:2, comment on p. 9.1
 * (read 2026-11-02): the systemdokumentation is räkenskapsinformation; when
 * it changes, the replaced version is kept so the bookkeeping can be
 * followed at every point in time. The header names the latest version,
 * the change table is in order, and the archive package of a fiscal year
 * carries the versions that applied during it.
 */

use App\Actions\Accounting\CreateArchivePackage;
use App\Models\FiscalYear;
use App\Models\SystemDocumentationVersion;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Build\SystemDocumentation;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;

afterEach(fn () => SystemDocumentation::fake(null));

function fakeDocumentation(string $version, string $date): string
{
    $content = "# Systemdokumentation\n\n- Version: {$version}\n- Datum: {$date}\n\nTest ".bin2hex(random_bytes(6))."\n";
    SystemDocumentation::fake($content);

    return $content;
}

it('keeps the change table in ascending order without gaps, with the header at its last row', function () {
    $doc = (string) file_get_contents(base_path(SystemDocumentation::FILE));
    preg_match_all('/^\| 0\.(\d+) \| (\d{4}-\d{2}-\d{2}) \|/m', $doc, $table);
    $minors = array_map('intval', $table[1]);

    expect($minors)->toBe(range(1, count($minors)))
        ->and(SystemDocumentation::version($doc))->toBe('0.'.end($minors))
        ->and(SystemDocumentation::date($doc))->toBe(end($table[2]));
});

it('records a new text of the systemdokumentation once, with the program version', function () {
    $this->travelTo(CarbonImmutable::parse('2030-03-01 08:00:00'));
    $content = fakeDocumentation('0.97', '2030-02-28');

    $first = SystemDocumentation::record();

    expect($first)->not->toBeNull()
        ->and($first->version)->toBe('0.97')
        ->and($first->document_date->toDateString())->toBe('2030-02-28')
        ->and($first->sha256)->toBe(hash('sha256', $content))
        ->and($first->content)->toBe($content)
        ->and(SystemDocumentation::record())->toBeNull();
});

it('records after every migrate, also when nothing was pending', function () {
    fakeDocumentation('0.96', '2030-01-01');

    Event::dispatch(new NoPendingMigrations('up'));

    expect(SystemDocumentationVersion::query()->where('version', '0.96')->exists())->toBeTrue();
});

it('refuses to change or remove a recorded version, in the model and in the database', function () {
    fakeDocumentation('0.95', '2030-01-01');
    $version = SystemDocumentation::record();

    expect(fn () => $version->update(['version' => '9.9']))->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::update('update system_documentation_versions set version = ? where id = ?', ['9.9', $version->id])))->toThrow(Exception::class)
        ->and(fn () => DB::transaction(fn () => DB::delete('delete from system_documentation_versions where id = ?', [$version->id])))->toThrow(Exception::class);
});

it('picks the version in use on the first day of the year and those taken into use during it', function () {
    [, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $year = FiscalYear::create(['start_date' => '2031-01-01', 'end_date' => '2031-12-31']);

    $record = function (string $at, string $version): SystemDocumentationVersion {
        $this->travelTo(CarbonImmutable::parse($at));
        fakeDocumentation($version, substr($at, 0, 10));

        return SystemDocumentation::record();
    };

    $record('2030-06-01 12:00:00', '0.90');
    $atStart = $record('2030-09-01 12:00:00', '0.91');
    $during = $record('2031-05-01 12:00:00', '0.92');
    $record('2032-02-01 12:00:00', '0.93');

    expect(SystemDocumentation::forFiscalYear($year)->pluck('id')->all())->toBe([$atStart->id, $during->id]);
});

it('puts the versions that applied during the year into the archive package, with a list', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2031-03-01 09:00:00'));
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);
    $year = FiscalYear::create(['start_date' => '2031-01-01', 'end_date' => '2031-12-31']);
    $content = fakeDocumentation('0.94', '2031-02-28');
    $version = SystemDocumentation::record();
    SystemDocumentation::fake(null);

    $package = app(CreateArchivePackage::class)($owner, $year);
    $zipPath = (string) tempnam(sys_get_temp_dir(), 'arkiv');
    file_put_contents($zipPath, Storage::disk('local')->get((string) $package->file_path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $root = ArchivePackageBuilder::name($company, $year);
    $copy = (string) $zip->getFromName("{$root}/systemdokumentation/versioner/0.94-".substr($version->sha256, 0, 12).'.md');
    $list = (string) $zip->getFromName("{$root}/systemdokumentation/versioner.txt");
    $current = (string) $zip->getFromName("{$root}/systemdokumentation/README.md");
    $zip->close();
    unlink($zipPath);

    // The app runs in Europe/Stockholm, so the time is shown as recorded.
    expect($copy)->toBe($content)
        ->and($list)->toContain('versioner/0.94-')
        ->and($list)->toContain('i bruk från 2031-03-01 09:00')
        ->and($current)->toContain('- Version: '.SystemDocumentation::version());
});
