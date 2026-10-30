<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Livewire\Accounting\FiscalYears;
use App\Models\ArchivePackage;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['bankgiro' => '5050-1055', 'vat_number' => 'SE556334368901', 'f_skatt' => true])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->orderBy('start_date')->firstOrFail();
});

/**
 * The finished package, unpacked: file name => contents.
 *
 * @return array<string, string>
 */
function unpackArchive(ArchivePackage $package): array
{
    $zipPath = tempnam(sys_get_temp_dir(), 'arkiv');
    file_put_contents($zipPath, Storage::disk('local')->get((string) $package->file_path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[substr($name, strpos($name, '/') + 1)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($zipPath);

    return $files;
}

it('packs the year into a ZIP that can be read and checked without NONOX', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, 49_900, 5410, 1930, '2026-03-05');
    app(AttachToVoucher::class)($this->owner, $draft, UploadedFile::fake()->createWithContent('Kvitto skrivare.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n"));
    $voucher = app(PostVoucher::class)($this->owner, $draft);
    Bookkeeping::post($this->owner, $this->company, 250_000, 1930, 3001, '2026-03-10');
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'ekonomi@kontorshuset.test', 'payment_terms_days' => 20]);
    app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Lunchbuffé', 'quantity' => '12', 'price' => '119,00', 'vat_rate' => 1200]],
    ]));

    Livewire::test(FiscalYears::class)
        ->assertSee('Skapa arkivpaket')
        ->call('createArchive', $this->year->id)
        ->assertDispatched('toast')
        ->assertSee('Ladda ner arkivpaket');

    $package = ArchivePackage::query()->sole();
    expect($package->status)->toBe('DONE')
        ->and($package->sha256)->toBe(hash('sha256', Storage::disk('local')->get((string) $package->file_path)))
        ->and($package->file_name)->toBe(ArchivePackageBuilder::name($this->company, $this->year).'.zip');

    $files = unpackArchive($package);
    $names = array_keys($files);

    expect($names)->toContain('LASMIG.txt', 'manifest.json', 'SHA256SUMS.txt', 'rapporter/Saldobalans.pdf', 'rapporter/Saldobalans.csv', 'rapporter/Huvudbok.pdf', 'rapporter/Verifikationslista.csv', 'kundfakturor/Faktura-1.pdf', 'behandlingshistorik/Behandlingshistorik.csv', 'behandlingshistorik/Behandlingshistorik.json', 'systemdokumentation/README.md')
        ->and($files["verifikationer/{$voucher->reference()}/Kvitto skrivare.pdf"])->toStartWith('%PDF')
        ->and(collect($names)->first(fn (string $name): bool => str_starts_with($name, 'sie/')))->not->toBeNull()
        ->and($files['rapporter/Saldobalans.pdf'])->toStartWith('%PDF')
        ->and($files['rapporter/Verifikationslista.csv'])->toContain($voucher->reference())
        ->and($files['LASMIG.txt'])->toContain('shasum -a 256 -c SHA256SUMS.txt');

    // Every file but the two lists themselves is listed with its true checksum.
    $sums = collect(explode("\n", trim($files['SHA256SUMS.txt'])))->mapWithKeys(fn (string $line): array => [substr($line, 66) => substr($line, 0, 64)]);
    expect($sums->keys()->sort()->values()->all())->toBe(collect($names)->reject(fn (string $name): bool => in_array($name, ['SHA256SUMS.txt', 'manifest.json'], true))->sort()->values()->all());
    $sums->each(fn (string $sum, string $name) => expect(hash('sha256', $files[$name]))->toBe($sum, $name));

    $manifest = json_decode($files['manifest.json'], true);
    expect($manifest['company']['org_number'])->toBe($this->company->org_number)
        ->and(count($manifest['files']))->toBe($sums->count())
        ->and(AuditLog::query()->where('action', 'archive.package_created')->sole()->payload['sha256'])->toBe($package->sha256);

    $this->get(route('accounting.fiscalYears.archive', [$this->year, $package]))
        ->assertOk()
        ->assertDownload($package->file_name);
    expect(AuditLog::query()->where('action', 'archive.package_downloaded')->exists())->toBeTrue();
});

it('splits a long report into several PDFs', function () {
    foreach (range(1, 3) as $day) {
        Bookkeeping::post($this->owner, $this->company, 10_000 * $day, 1930, 3001, "2026-03-0{$day}");
    }

    ArchivePackageBuilder::$rowsPerPdf = 4;

    try {
        $files = unpackArchive(app(CreateArchivePackage::class)($this->owner, $this->year));
    } finally {
        ArchivePackageBuilder::$rowsPerPdf = 500;
    }

    expect(array_keys($files))->toContain('rapporter/Verifikationslista-del-01.pdf', 'rapporter/Verifikationslista-del-02.pdf')
        ->not->toContain('rapporter/Verifikationslista.pdf');
});

it('keeps the archive to roles that may export it and to the company', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(CreateArchivePackage::class)($bookkeeper, $this->year))->toThrow(AuthorizationException::class);

    $package = app(CreateArchivePackage::class)($this->owner, $this->year);
    [$otherOwner, $other] = Bookkeeping::company();
    $this->actingAs($otherOwner);
    CurrentCompany::set($other);
    $otherYear = FiscalYear::query()->firstOrFail();

    $this->get(route('accounting.fiscalYears.archive', [$otherYear, $package]))->assertNotFound();
});

it('builds one package at a time per year and keeps earlier ones', function () {
    $first = app(CreateArchivePackage::class)($this->owner, $this->year);
    ArchivePackage::query()->create(['fiscal_year_id' => $this->year->id, 'status' => ArchivePackage::RUNNING, 'created_by' => $this->owner->id]);

    Livewire::test(FiscalYears::class)
        ->assertSee('Arkivpaketet skapas…')
        ->call('createArchive', $this->year->id)
        ->assertDispatched('toast');

    expect(ArchivePackage::query()->count())->toBe(2)
        ->and($first->fresh()?->status)->toBe('DONE');
});
