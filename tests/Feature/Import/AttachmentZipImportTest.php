<?php

declare(strict_types=1);

use App\Actions\Accounting\AnalyseSieFile;
use App\Actions\Accounting\ConfirmSieImport;
use App\Migration\Import\AttachmentZip;
use App\Migration\Import\RegisterKind;
use App\Models\RegisterImport;
use App\Models\VoucherAttachment;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, S6: underlag in a ZIP, linked to the vouchers a SIE import made.
 */
const PDF_BYTES = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

beforeEach(function () {
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556334-3689'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $sie = (string) iconv('UTF-8', 'CP437', implode("\r\n", [
        '#FLAGGA 0', '#FORMAT PC8', '#SIETYP 4', '#ORGNR 556334-3689', '#RAR 0 20260101 20261231',
        "#VER A 1 20260301 \"Ett\"\r\n{\r\n#TRANS 1930 {} 100.00\r\n#TRANS 3001 {} -100.00\r\n}",
        "#VER A 2 20260302 \"Två\"\r\n{\r\n#TRANS 1930 {} 50.00\r\n#TRANS 3001 {} -50.00\r\n}",
    ])."\r\n");
    $import = app(AnalyseSieFile::class)($this->owner, $this->company, UploadedFile::fake()->createWithContent('bokforing.se', $sie));
    app(ConfirmSieImport::class)($this->owner, $import);
});

function importedAttachments(object $test): Collection
{
    return CurrentCompany::run($test->company, fn () => VoucherAttachment::query()->orderBy('id')->get());
}

function registerZipOf(array $files): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
    register_shutdown_function(fn () => @unlink($path));

    return new UploadedFile($path, 'bilagor.zip', null, null, true);
}

it('links files by the mapping file and by the folder layout, and reports the rest', function () {
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Attachments, RegisterImports::fixture('bilagor.zip'));

    expect($import->status)->toBe(RegisterImport::COMPLETED)
        ->and($import->report['counts'])->toMatchArray(['read' => 5, 'linked' => 2, 'unlinked' => 1, 'rejected' => 2])
        ->and($import->result)->toMatchArray(['created' => 2])
        ->and(importedAttachments($this))->toHaveCount(2)
        ->and(importedAttachments($this)->pluck('original_name')->sort()->values()->all())->toBe(['A-2.pdf', 'kvitto-1.pdf']);

    $reasons = collect($import->report['rows'])->pluck('reason')->filter()->implode(' | ');
    expect($reasons)->toContain('utanför arkivet')->toContain('Filtypen tas inte emot')->toContain('Ingen koppling');
});

it('rejects traversal, absolute paths, drive letters, other extensions and oversized files', function () {
    $big = str_repeat('a', AttachmentZip::MAX_FILE_BYTES + 1);
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Attachments, registerZipOf([
        '../ut.pdf' => PDF_BYTES,
        '/etc/x.pdf' => PDF_BYTES,
        'C:/x.pdf' => PDF_BYTES,
        'a/../../b.pdf' => PDF_BYTES,
        'program.exe' => 'MZ',
        '2026/A-1.pdf.php' => '<?php',
        '2026/A-1.pdf' => $big,
    ]));
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['counts'])->toMatchArray(['read' => 7, 'rejected' => 7, 'linked' => 0]);
});

it('refuses an entry that unpacks to absurdly more than it weighs', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Attachments, registerZipOf(['2026/A-1.pdf' => PDF_BYTES.str_repeat("\0", 5_000_000)]));
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['counts']['rejected'])->toBe(1)
        ->and(collect($import->report['rows'])->first()['reason'])->toContain('orimligt');
});

it('skips a file that is already on the voucher, also on a rerun', function () {
    $zip = fn () => registerZipOf(['2026/A-1.pdf' => PDF_BYTES, '2026/A-1-kopia.pdf' => PDF_BYTES, '2026/A-2.pdf' => PDF_BYTES.'2']);

    $first = RegisterImports::run($this->owner, $this->company, RegisterKind::Attachments, $zip());
    expect($first->report['counts'])->toMatchArray(['linked' => 2, 'duplicate' => 1])
        ->and(importedAttachments($this))->toHaveCount(2);

    // Same content, new file: nothing is added twice.
    $second = RegisterImports::run($this->owner, $this->company, RegisterKind::Attachments, registerZipOf(['2026/A-1.pdf' => PDF_BYTES, '2026/A-2.pdf' => PDF_BYTES.'2', 'x.txt' => 'x']));
    expect($second->report['counts'])->toMatchArray(['linked' => 0, 'duplicate' => 2])
        ->and(importedAttachments($this))->toHaveCount(2);
});

it('does not guess when the voucher cannot be told apart or is not among the imported', function () {
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Attachments, registerZipOf([
        'lank.csv' => "filnamn;serie;nummer;ar\nett.pdf;A;1;2025\ntva.pdf;B;9;2026\n",
        'ett.pdf' => PDF_BYTES,
        'tva.pdf' => PDF_BYTES.'2',
    ]));
    $import = RegisterImports::analyse($this->owner, $import);

    expect($import->report['counts'])->toMatchArray(['unlinked' => 2, 'linked' => 0]);
});

it('says so when the file is not a ZIP and keeps the archive out of the voucher folder', function () {
    expect(fn () => RegisterImports::upload($this->owner, $this->company, RegisterKind::Attachments, RegisterImports::contents('inte en zip', 'x.zip')))
        ->toThrow(ValidationException::class);

    RegisterImports::run($this->owner, $this->company, RegisterKind::Attachments, RegisterImports::fixture('bilagor.zip'));

    expect(collect(Storage::disk('local')->allFiles('underlag'))->every(fn (string $path) => str_ends_with($path, '.pdf')))->toBeTrue();
});

it('holds nothing back for another company: a voucher of another company is never linked', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $import = RegisterImports::run($otherOwner, $other, RegisterKind::Attachments, RegisterImports::fixture('bilagor.zip'));

    expect($import->report['counts']['linked'])->toBe(0)
        ->and(importedAttachments($this))->toHaveCount(0);
});
