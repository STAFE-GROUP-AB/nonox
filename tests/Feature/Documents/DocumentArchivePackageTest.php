<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Documents\AddDocumentVersion;
use App\Models\ArchivePackage;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Feature\Documents\DocumentSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 gap 9, scope 4: the documents filed under a fiscal year go into
 * that year's archive package (export:archive) with every version, and each
 * file's SHA-256 is in the manifest and in Dokumentforteckning.csv next to
 * the checksum stored at upload.
 */

beforeEach(function () {
    $this->root = DocumentSupport::useTemporaryDisk();
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = DocumentSupport::fiscalYear($this->company);
});

afterEach(fn () => DocumentSupport::removeTemporaryDisk($this->root));

it('packs the year\'s documents with every version and their SHA-256', function () {
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Avtal om lokal', 'retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $this->year->id], DocumentSupport::pdf('lokal.pdf', 'Första versionen av avtalet'));
    app(AddDocumentVersion::class)($this->owner, $document, DocumentSupport::pdf('lokal-v2.pdf', 'Andra versionen av avtalet'));
    DocumentSupport::store($this->owner, $this->company, ['title' => 'Utan räkenskapsår']);

    app(CreateArchivePackage::class)($this->owner, $this->year);
    $files = DocumentSupport::unpack(ArchivePackage::query()->sole());
    $manifest = json_decode($files['manifest.json'], true);
    $listed = array_column($manifest['files'], 'sha256', 'path');
    $versions = DocumentVersion::query()->where('document_id', $document->id)->orderBy('version')->get();
    $folder = "dokument/{$document->id} Avtal om lokal";

    expect($files)->toHaveKey("{$folder}/v1-lokal.pdf")
        ->and($files)->toHaveKey("{$folder}/v2-lokal-v2.pdf")
        ->and(hash('sha256', $files["{$folder}/v1-lokal.pdf"]))->toBe($versions[0]->sha256)
        ->and($listed["{$folder}/v2-lokal-v2.pdf"])->toBe($versions[1]->sha256)
        ->and($files['dokument/Dokumentforteckning.csv'])->toContain($versions[0]->sha256)
        ->and($files['dokument/Dokumentforteckning.csv'])->toContain('Stämmer')
        ->and($files['dokument/Dokumentforteckning.csv'])->not->toContain('Utan räkenskapsår')
        ->and($files['LASMIG.txt'])->toContain('dokument/');
});

it('leaves personnel documents out of a package made by someone who may not see them', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    DocumentSupport::store($payrollAdmin, $this->company, ['title' => 'Anställningsavtal', 'retention_class' => 'PERSONNEL', 'keep_until' => '2030-01-01', 'contains_personal_data' => '1']);
    $accounting = DocumentSupport::store($this->owner, $this->company, ['title' => 'Kvitto', 'retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $this->year->id]);

    // A personnel document carries no fiscal year from the form; set one directly, the package must still keep it out.
    Document::query()->where('retention_class', 'PERSONNEL')->update(['fiscal_year_id' => $this->year->id]);

    app(CreateArchivePackage::class)($this->owner, $this->year);
    $files = DocumentSupport::unpack(ArchivePackage::query()->sole());

    expect($files['dokument/Dokumentforteckning.csv'])->toContain('Kvitto')
        ->and($files['dokument/Dokumentforteckning.csv'])->not->toContain('Anställningsavtal')
        ->and($files['dokument/Dokumentforteckning.csv'])->toContain('1 personaldokument togs inte med: den som skapade paketet får inte se dem.')
        ->and($files['LASMIG.txt'])->toContain('1 personaldokument togs inte med')
        ->and($accounting->id)->toBeInt();
});

it('makes no documents folder for a year without documents', function () {
    app(CreateArchivePackage::class)($this->owner, $this->year);

    expect(array_filter(array_keys(DocumentSupport::unpack(ArchivePackage::query()->sole())), fn (string $path): bool => str_starts_with($path, 'dokument/')))->toBe([]);
});

it('packs a contract marked as räkenskapsinformation with its fiscal year', function () {
    DocumentSupport::store($this->owner, $this->company, ['title' => 'Leasingavtal bil', 'retention_class' => 'CONTRACT', 'is_accounting_record' => '1', 'fiscal_year_id' => $this->year->id, 'contract_ends_on' => '2028-12-31']);

    app(CreateArchivePackage::class)($this->owner, $this->year);

    expect(DocumentSupport::unpack(ArchivePackage::query()->sole())['dokument/Dokumentforteckning.csv'])->toContain('Leasingavtal bil');
});
