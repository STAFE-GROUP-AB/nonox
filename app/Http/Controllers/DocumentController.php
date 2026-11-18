<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\RetentionClass;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\InlineFile;
use App\Support\Files\StoredFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A company document and its files (ADR-081 gap 9). Route model binding goes
 * through the company scope, so another company's document is a 404; so is a
 * personnel document for someone without payroll:view-sensitive, which must
 * not learn that it exists. Every file opened is written to the audit log.
 */
final class DocumentController
{
    public function show(Request $request, Document $document): View
    {
        $this->authorizeView($request, $document);

        if ($this->ownerFallback($request, $document)) {
            Audit::record('document.personnel_owner_access', $document, ['retention_class' => $document->retention_class->value], $document->company_id, $this->user($request));
        }

        return view('documents.show', ['document' => $document]);
    }

    public function file(Request $request, Document $document, int $version): StreamedResponse
    {
        $this->authorizeView($request, $document);

        $file = DocumentVersion::query()->where('document_id', $document->id)->findOrFail($version);

        Audit::record('document.file_opened', $document, [
            'version_id' => $file->id,
            'version' => $file->version,
            'owner_fallback' => $this->ownerFallback($request, $document),
        ], $document->company_id, $this->user($request));

        if (! in_array($file->mime, DocumentFile::INLINE, true)) {
            return Storage::disk(StoredFiles::DISK)->download($file->path, $file->original_name, InlineFile::headers('application/octet-stream'));
        }

        return Storage::disk(StoredFiles::DISK)->response($file->path, $file->original_name, InlineFile::headers($file->mime), 'inline');
    }

    /** The owner opening a personnel document while payroll is off (DocumentAccess::ownerFallback). */
    private function ownerFallback(Request $request, Document $document): bool
    {
        $user = $this->user($request);
        $company = Company::query()->find($document->company_id);

        return $document->retention_class === RetentionClass::Personnel
            && $user instanceof User
            && $company instanceof Company
            && DocumentAccess::ownerFallback($user, $company);
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function authorizeView(Request $request, Document $document): void
    {
        if (Gate::forUser($request->user())->allows('view', $document)) {
            return;
        }

        abort($document->retention_class === RetentionClass::Personnel ? 404 : 403);
    }
}
