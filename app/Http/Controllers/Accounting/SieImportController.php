<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\AnalyseSieFile;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Reading in SIE files (AGENTS.md §9.2). The upload is a plain form post, so
 * a file up to the server's limit works without temporary upload storage.
 */
class SieImportController
{
    public function index(Request $request): View
    {
        $company = $this->company($request);

        Gate::authorize('viewAny', [SieImport::class, $company]);

        return view('accounting.sie-imports.index', [
            'imports' => SieImport::query()->with(['user', 'fiscalYear'])->latest('id')->limit(20)->get(),
            'maxMegabytes' => intdiv(AnalyseSieFile::MAX_KILOBYTES, 1024),
        ]);
    }

    public function store(Request $request, AnalyseSieFile $analyseSieFile): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $import = $analyseSieFile($user, $this->company($request), $request->file('file'));

        Toast::flash($import->blockers() === []
            ? __('Filen är granskad. Läs igenom rapporten och läs sedan in den.')
            : __('Filen är granskad, men den kan inte läsas in som den är. Se vad som behöver rättas.'), $import->blockers() === [] ? 'success' : 'error');

        return redirect()->route('accounting.sieImports.show', $import);
    }

    public function show(SieImport $sieImport): View
    {
        Gate::authorize('view', $sieImport);

        return view('accounting.sie-imports.show', ['import' => $sieImport]);
    }

    private function company(Request $request): Company
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company */
        return $user->currentCompany();
    }
}
