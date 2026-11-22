<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Models\SigningRequest;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Http\Resources\SigningPresenter;
use App\Modules\Signing\Actions\DeleteSigningDocument;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PlainResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Documents for signing over the API (ADR-046 S19, ADR-032): a PDF is
 * uploaded (multipart, field `file`) with StoreSigningDocument, which checks
 * what the file really is (size, type, the PDF engine's inspection) exactly
 * as the screen does, and is then sent in an envelope. As in the send
 * wizard, an uploaded document is its uploader's, and signing:manage sees
 * everyone's; any other document answers 404.
 */
final class SigningDocumentController
{
    use ActsAsCredential;

    public function index(Request $request): JsonResponse
    {
        return CursorList::respond($request, $this->visible(), fn (SigningDocument $document): array => SigningPresenter::document($document));
    }

    public function show(string $document): JsonResponse
    {
        return CursorList::one(SigningPresenter::document($this->find($document)));
    }

    public function store(Request $request, StoreSigningDocument $store): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getRealPath() === false) {
            throw ValidationException::withMessages(['file' => __('Skicka PDF-filen i fältet file (multipart/form-data).')]);
        }

        $document = $store(
            $this->user(),
            $this->company(),
            $file->getRealPath(),
            $file->getClientOriginalName(),
            (string) $request->input('title', ''),
            $this->id($request->input('customer_id')),
            $this->id($request->input('supplier_id')),
        );

        return CursorList::one(SigningPresenter::document($document), 201)
            ->header('Location', route('connect.api.signing.documents.show', $document->id));
    }

    /** The uploaded file, byte for byte. */
    public function content(string $document): PlainResponse
    {
        $found = $this->find($document);
        $bytes = CurrentCompany::run($this->company(), fn (): ?string => Storage::disk(SigningRequest::DISK)->get($found->path));
        abort_unless(is_string($bytes), 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.self::headerName($found->name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $found->sha256,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Removes a document that has not been sent (DeleteSigningDocument refuses one that has). */
    public function destroy(string $document, DeleteSigningDocument $delete): PlainResponse
    {
        $delete($this->user(), $this->company(), $this->find($document));

        return response()->noContent();
    }

    /**
     * A file name safe for a header: letters, digits, spaces and a few marks.
     */
    public static function headerName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $name));

        return $clean === '' ? 'dokument.pdf' : $clean;
    }

    /** @return Builder<SigningDocument> */
    private function visible(): Builder
    {
        $user = $this->user();

        return SigningDocument::query()->when(
            ! $user->hasTeamPermission($this->company(), SigningModule::MANAGE),
            fn (Builder $query) => $query->where('uploaded_by', $user->id),
        );
    }

    private function find(string $id): SigningDocument
    {
        return $this->visible()->findOrFail($id);
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
