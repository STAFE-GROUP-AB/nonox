<?php

declare(strict_types=1);

namespace App\Migration\Import\Http;

use App\Migration\Import\Actions\AnalyseRegisterImport;
use App\Migration\Import\Actions\ConfirmRegisterImport;
use App\Migration\Import\Actions\UploadRegisterFile;
use App\Migration\Import\Guides\FlyttGuider;
use App\Migration\Import\OpenItemReconciliation;
use App\Migration\Import\PresetRepository;
use App\Migration\Import\RegisterKind;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\RegisterImport;
use App\Models\RegisterImportPreset;
use App\Models\User;
use App\Support\Toast;
use App\Support\WorkQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Läs in register" (ADR-076): customers, suppliers, articles, unpaid
 * invoices and underlag from another program, in two steps. The upload is a
 * plain form post, like the SIE import.
 */
class RegisterImportController
{
    public function index(Request $request): View
    {
        $company = $this->company($request);

        Gate::authorize('viewAny', [RegisterImport::class, $company]);

        return view('import::index', [
            'imports' => RegisterImport::query()->with('user')->latest('id')->limit(20)->get(),
            'kinds' => RegisterKind::cases(),
            'programs' => $this->programs(),
            'presets' => RegisterImportPreset::query()->orderBy('name')->get(),
            'guides' => FlyttGuider::enabled(),
        ]);
    }

    public function store(Request $request, UploadRegisterFile $upload): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->company($request);

        $kind = RegisterKind::tryFrom((string) $request->input('kind'));

        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => __('Välj vad filen innehåller.')]);
        }

        WorkQuota::take($company, 'register_upload', 'file');

        $import = $upload($user, $company, $kind, (string) $request->input('source', 'nonox'), $request->file('file'));

        Toast::flash($kind === RegisterKind::Attachments
            ? __('Filen är uppladdad. Granska den och läs sedan in underlagen.')
            : __('Filen är uppladdad. Koppla kolumnerna och granska filen.'));

        return redirect()->route('import.registers.show', $import);
    }

    public function show(Request $request, RegisterImport $registerImport): View
    {
        Gate::authorize('view', $registerImport);

        $kind = $registerImport->registerKind();

        return view('import::show', [
            'import' => $registerImport,
            'kind' => $kind,
            'headers' => (array) ($registerImport->options['detected']['headers'] ?? []),
            'detected' => (array) ($registerImport->options['detected'] ?? []),
            'fiscalYears' => $kind->isOpenItems() ? FiscalYear::query()->orderByDesc('start_date')->get() : collect(),
            'defaultYearId' => $kind->isOpenItems() ? OpenItemReconciliation::defaultYear()?->id : null,
        ]);
    }

    public function analyse(Request $request, RegisterImport $registerImport, AnalyseRegisterImport $analyse): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $registerImport);

        $input = array_filter([
            'mapping' => $request->input('mapping'),
            'value_maps' => $request->input('value_maps'),
            'reader' => $request->input('reader'),
            'excluded' => $request->input('excluded', []),
            'fiscal_year_id' => $request->input('fiscal_year_id'),
            'preset_name' => $request->input('preset_name'),
            'extra_accounts' => $request->input('extra_accounts'),
        ], fn (mixed $value): bool => $value !== null);

        if ($request->has('mapping') || $request->has('reader')) {
            $input['fill_empty'] = $request->boolean('fill_empty');
            $input['match_nonox_number'] = $request->boolean('match_nonox_number');
        }

        $import = $analyse($user, $registerImport, $input);

        Toast::flash($import->blockers() === []
            ? __('Filen är granskad. Läs igenom rapporten och läs sedan in den.')
            : __('Filen är granskad, men den kan inte läsas in som den är. Se vad som behöver rättas.'), $import->blockers() === [] ? 'success' : 'error');

        return redirect()->route('import.registers.show', $import);
    }

    public function confirm(Request $request, RegisterImport $registerImport, ConfirmRegisterImport $confirm): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $registerImport);

        if (! $registerImport->canBeConfirmed()) {
            Toast::flash(__('Filen kan inte läsas in som den är. Se vad som behöver rättas.'), 'error');

            return redirect()->route('import.registers.show', $registerImport);
        }

        $import = $confirm($user, $registerImport)->fresh() ?? $registerImport;

        Toast::flash($import->status === RegisterImport::COMPLETED
            ? __('Inläsningen är klar.')
            : ($import->status === RegisterImport::FAILED ? __('Inläsningen stoppades. Läs felet på sidan.') : __('Inläsningen pågår. Ladda om sidan om en stund.')), $import->status === RegisterImport::FAILED ? 'error' : 'success');

        return redirect()->route('import.registers.show', $import);
    }

    public function template(Request $request, string $kind): Response
    {
        Gate::authorize('viewAny', [RegisterImport::class, $this->company($request)]);

        $registerKind = RegisterKind::tryFrom($kind);

        abort_if($registerKind === null || $registerKind === RegisterKind::Attachments, 404);

        return response("\u{FEFF}".PresetRepository::template($registerKind), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mall-'.str_replace('_', '-', $registerKind->value).'.csv"',
        ]);
    }

    /** @return array<string, string> program slug => name, for every kind with a shipped preset */
    private function programs(): array
    {
        $programs = [];

        foreach (RegisterKind::cases() as $kind) {
            $programs += PresetRepository::programs($kind);
        }

        return $programs;
    }

    private function company(Request $request): Company
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company */
        return $user->currentCompany();
    }
}
