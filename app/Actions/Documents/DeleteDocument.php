<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentRetention;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentTag;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a document with all its versions, which is also the way to erase
 * one on request (GDPR art. 17). Refused while the document is on legal
 * hold, inside its retention period or a contract that still runs: where
 * the bookkeeping act requires the document to be kept, the obligation
 * wins (art. 17.3 b). A file another row still refers to, such as the same
 * file as underlag to a voucher, stays (StoredFiles::deleteUnreferenced).
 * The PDF text kept for the search is deleted with its version.
 */
class DeleteDocument
{
    public function __invoke(User $user, Document $document): void
    {
        Gate::forUser($user)->authorize('update', $document);

        CurrentCompany::run($document->company_id, function () use ($user, $document): void {
            DB::transaction(function () use ($user, $document): void {
                $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                $blocker = DocumentRetention::blocker($locked);

                if ($blocker !== null) {
                    throw ValidationException::withMessages(['document' => $blocker]);
                }

                $paths = DocumentVersion::query()->where('document_id', $locked->id)->pluck('path')->all();
                $versions = count($paths);

                DocumentLink::query()->where('document_id', $locked->id)->delete();
                DocumentTag::query()->where('document_id', $locked->id)->delete();
                DocumentVersion::query()->where('document_id', $locked->id)->delete();
                $locked->refresh()->delete();

                StoredFiles::deleteUnreferenced($paths);

                Audit::record('document.deleted', $locked, [
                    'retention_class' => $locked->retention_class->value,
                    'version_count' => $versions,
                    'contained_personal_data' => $locked->contains_personal_data,
                ], $locked->company_id, $user);
            });
        });
    }
}
