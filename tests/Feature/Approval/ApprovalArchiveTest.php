<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Approval\ManageApprovalDelegations;
use App\Actions\Approval\RejectApproval;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Domain\Approval\ApprovalSubjectType;
use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * ADR-081 gap 8, E2: the approval history of the year goes into the archive
 * package as CSV, listed in the manifest with SHA-256, so it can be read
 * without NONOX.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

/** @return array<string, string> file name => contents */
function e2UnpackArchive(ArchivePackage $package): array
{
    $zipPath = (string) tempnam(sys_get_temp_dir(), 'e2arkiv');
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

it('packs requests with their policy copy, decisions and substitutes, with checksums', function () {
    $supplier = ApprovalFixtures::supplier($this->owner, $this->company);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $manager = Bookkeeping::member($this->company, 'approver');
    $company = $this->company->fresh();
    ApprovalFixtures::policy($this->owner, $company, [
        ['approver_kind' => 'USERS', 'approver_user_ids' => [$manager->id]],
        ['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage'],
    ], name: 'Två steg');
    $invoice = ApprovalFixtures::invoice($bookkeeper, $company, $supplier);
    app(ApproveSupplierInvoice::class)($manager, $invoice);
    app(RejectApproval::class)($this->owner, $company, ApprovalSubjectType::SupplierInvoice, $invoice->id, 'Saknar följesedel');
    app(ManageApprovalDelegations::class)->add($manager, $company, ['user_id' => $manager->id, 'substitute_user_id' => $this->owner->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-20']);

    $files = e2UnpackArchive(app(CreateArchivePackage::class)($this->owner, FiscalYear::query()->orderBy('start_date')->firstOrFail()));
    $manifest = json_decode($files['manifest.json'], true);
    $listed = collect($manifest['files'])->pluck('sha256', 'path');

    expect($files['attest/Attestarenden.csv'])->toContain('Två steg')->toContain('Avvisad')
        ->and($files['attest/Attestbeslut.csv'])->toContain($manager->name)->toContain('Saknar följesedel')
        ->and($files['attest/Ersattare.csv'])->toContain($manager->name)->toContain('2026-03-20')
        ->and($files['LASMIG.txt'])->toContain('attest/')
        ->and($files['SHA256SUMS.txt'])->toContain(hash('sha256', $files['attest/Attestbeslut.csv']).'  attest/Attestbeslut.csv')
        ->and($listed['attest/Attestarenden.csv'])->toBe(hash('sha256', $files['attest/Attestarenden.csv']))
        ->and($listed['attest/Ersattare.csv'])->toBe(hash('sha256', $files['attest/Ersattare.csv']));
});

it('adds no approval folder for a company without a policy of its own', function () {
    $files = e2UnpackArchive(app(CreateArchivePackage::class)($this->owner, FiscalYear::query()->orderBy('start_date')->firstOrFail()));

    expect(array_keys($files))->not->toContain('attest/Attestarenden.csv')
        ->and($files['LASMIG.txt'])->not->toContain('attest/');
});
