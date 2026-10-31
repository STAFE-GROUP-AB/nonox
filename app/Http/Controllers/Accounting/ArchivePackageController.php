<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a finished archive package. Route model binding goes through
 * the company scope, so another company's package answers 404.
 */
class ArchivePackageController
{
    public function show(Request $request, FiscalYear $fiscalYear, ArchivePackage $archivePackage): StreamedResponse
    {
        Gate::authorize('exportArchive', $fiscalYear);

        abort_unless($archivePackage->fiscal_year_id === $fiscalYear->id && $archivePackage->status === ArchivePackage::DONE && $archivePackage->file_path !== null, 404);
        abort_unless(Storage::disk(AttachToVoucher::DISK)->exists($archivePackage->file_path), 404);

        // A package with payslips (salaries, sick pay) only for those who may read the payroll archive.
        $company = $fiscalYear->company;
        abort_if(($archivePackage->contents['lon'] ?? 0) > 0 && ! ($company !== null && $request->user()?->hasTeamPermission($company, 'payroll:archive-view')), 404);

        Audit::record('archive.package_downloaded', $archivePackage, ['sha256' => $archivePackage->sha256]);

        return Storage::disk(AttachToVoucher::DISK)->download($archivePackage->file_path, (string) $archivePackage->file_name, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
