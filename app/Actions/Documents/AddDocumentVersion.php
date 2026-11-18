<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\RetentionClass;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Adds a new version of a document. The earlier versions stay as they were
 * (append-only); the new one becomes the current version.
 */
class AddDocumentVersion
{
    public function __invoke(User $user, Document $document, UploadedFile $file): DocumentVersion
    {
        Gate::forUser($user)->authorize('update', $document);

        $stored = null;

        try {
            return CurrentCompany::run($document->company_id, function () use ($user, $document, $file, &$stored): DocumentVersion {
                return DB::transaction(function () use ($user, $document, $file, &$stored): DocumentVersion {
                    // One version number at a time per document.
                    $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                    $next = (int) DocumentVersion::query()->where('document_id', $locked->id)->max('version') + 1;

                    $stored = DocumentFile::store($locked->company_id, $locked->id, $file, $locked->retention_class !== RetentionClass::Personnel);

                    $version = DocumentVersion::create([
                        ...$stored,
                        'company_id' => $locked->company_id,
                        'document_id' => $locked->id,
                        'version' => $next,
                        'uploaded_by' => $user->id,
                    ]);

                    $locked->forceFill(['current_version_id' => $version->id])->save();

                    Audit::record('document.version_added', $locked, [
                        'version_id' => $version->id,
                        'version' => $next,
                        'size' => $version->size,
                        'sha256' => $version->sha256,
                    ], $locked->company_id, $user);

                    return $version;
                });
            });
        } catch (Throwable $exception) {
            if ($stored !== null && StoredFiles::referenced([$stored['path']]) === []) {
                Storage::disk(StoredFiles::DISK)->delete($stored['path']);
            }

            throw $exception;
        }
    }
}
