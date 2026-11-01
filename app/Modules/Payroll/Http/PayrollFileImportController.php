<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\PayrollFileImports;
use App\Modules\Payroll\Models\PayrollFileImport;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A payroll file read into a run: its page for those who run payroll, and
 * the original file for those who may see personal numbers
 * (payroll:view-sensitive), every download audited. Route model binding
 * goes through the company scope, so another company's file answers 404.
 */
class PayrollFileImportController
{
    public function show(Request $request, PayrollRun $run, PayrollFileImport $import): View
    {
        $this->authorize($request, $run, $import, 'payroll:run');

        return view('payroll::file-import', ['run' => $run, 'import' => $import]);
    }

    public function download(Request $request, PayrollRun $run, PayrollFileImport $import): StreamedResponse
    {
        $company = $this->authorize($request, $run, $import, 'payroll:view-sensitive');
        abort_unless(Storage::disk(PayrollFileImports::DISK)->exists($import->file_path), 404);

        /** @var User $user */
        $user = $request->user();
        Audit::record('payroll.file_downloaded', $import, ['run_id' => $run->id, 'sha256' => $import->sha256], $company, $user);

        return Storage::disk(PayrollFileImports::DISK)->download($import->file_path, $import->original_name, [
            'Content-Type' => $import->format === PayrollFileImport::PAXML ? 'application/xml' : 'text/csv',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    private function authorize(Request $request, PayrollRun $run, PayrollFileImport $import, string $permission): Company
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($run->company_id);

        abort_unless($import->payroll_run_id === $run->id, 404);
        abort_unless(Gate::forUser($user)->allows('view', $run) && $user->hasTeamPermission($company, $permission), 404);

        return $company;
    }
}
