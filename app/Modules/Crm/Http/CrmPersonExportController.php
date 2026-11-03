<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CrmExport;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Registerutdrag for a CRM contact person (GDPR art. 15 and 20, ADR-043),
 * for those who manage the CRM, and while the module is off for those who
 * manage the company's settings. Another company's person answers 404. The
 * export is written to the audit log by the person's id.
 */
final class CrmPersonExportController
{
    public function __invoke(Request $request, int $person): JsonResponse
    {
        $model = Person::query()->findOrFail($person);
        $company = Company::query()->findOrFail($model->company_id);
        /** @var User $requester */
        $requester = $request->user();

        // With the module on: those who manage the CRM. With it off: those who manage the company's settings, since the data is still kept.
        $allowed = ArchivedCompany::reading(fn (): bool => app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company)
            ? Gate::forUser($requester)->allows('update', $model)
            : $requester->hasTeamPermission($company, 'settings:manage'));

        abort_unless($allowed, 403);

        /** @var User $user */
        $user = $request->user();
        Audit::record('privacy.exported', $model, ['subject' => 'crm_person'], Company::query()->find($model->company_id), $user);

        $data = [
            'utdrag' => [
                'typ' => 'Kontaktperson i CRM',
                'skapat' => now()->toIso8601String(),
                'rattslig_grund' => 'Registerutdrag enligt artikel 15 och dataportabilitet enligt artikel 20 i dataskyddsförordningen (GDPR).',
            ],
            ...CrmExport::person($model),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="registerutdrag-kontaktperson-'.$model->id.'.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
