<?php

declare(strict_types=1);

namespace App\Modules\Signing\Actions;

use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes an uploaded document that has never been sent for signing (ADR-046
 * amendment, gap 10, package S7), with its file: a PDF uploaded by mistake,
 * which may hold personal data, must not stay for ever (GDPR art. 5.1 e). A
 * document that has been sent is evidence and is never removed here (the
 * database refuses it too). The uploader removes their own; signing:manage
 * removes anyone's.
 */
final class DeleteSigningDocument
{
    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function __invoke(User $user, Company $company, SigningDocument $document): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled(SigningModule::KEY, $company) && $document->company_id === $company->id, 404);

        if (! $user->hasTeamPermission($company, SigningModule::SEND)
            || ($document->uploaded_by !== $user->id && ! $user->hasTeamPermission($company, SigningModule::MANAGE))) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($user, $company, $document): void {
            CurrentCompany::run($company, function () use ($user, $company, $document): void {
                $locked = SigningDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

                if (SigningEnvelope::query()->where('document_type', SigningDocument::SIGNING_TYPE)->where('signable_id', $locked->id)->exists()) {
                    throw ValidationException::withMessages(['document' => __('Dokumentet har skickats för signering och kan inte tas bort.')]);
                }

                $path = $locked->path;
                $id = $locked->id;
                $locked->delete();

                StoredFiles::deleteUnreferenced([$path]);
                Audit::record('signing.document_deleted', null, ['signing_document_id' => $id], $company, $user);
            });
        });
    }
}
