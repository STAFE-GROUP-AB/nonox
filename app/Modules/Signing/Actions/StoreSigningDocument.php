<?php

declare(strict_types=1);

namespace App\Modules\Signing\Actions;

use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\PdfInspection;
use App\Domain\Signing\Pdf\PdfLimits;
use App\Domain\Signing\Pdf\PdfRefusal;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SigningRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Uploads a PDF as a standalone document for signing (ADR-046 amendment,
 * gap 10, package S7). The file is checked for what it really is, never for
 * what the browser says: size, the %PDF- header, the type by content, and
 * then by the PDF engine, which reads the file itself and refuses one with
 * more than 100 pages, encryption, JavaScript, launch actions, embedded
 * files, XFA forms or actions that send data out or open other files. A
 * refused file is never cleaned up, since that would rewrite the bytes the
 * signer is shown. Without the engine nothing is uploaded: a PDF cannot be
 * judged safe by looking at its first bytes.
 *
 * The file is stored on the private disk under a random name, once, and
 * never changes; its SHA-256 is the document's fingerprint. The audit entry
 * holds the document's id, its page count and its size, never its title,
 * its name or its content.
 */
final class StoreSigningDocument
{
    /** At most this many uploaded documents per company wait unsent. */
    public const int MAX_UNSENT = 200;

    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * Checks a file without storing it, for the wizard's first step. Throws
     * the same errors a store would.
     *
     * @throws ValidationException
     */
    public function inspect(string $path): PdfInspection
    {
        $size = @filesize($path);

        if ($size === false || $size < 8) {
            $this->refuse(PdfRefusal::NotPdf);
        }

        if ($size > PdfLimits::MAX_BYTES) {
            $this->refuse(PdfRefusal::TooLarge);
        }

        $head = @file_get_contents($path, false, null, 0, 5);

        if ($head !== '%PDF-') {
            $this->refuse(PdfRefusal::NotPdf);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($mime !== 'application/pdf') {
            $this->refuse(PdfRefusal::NotPdf);
        }

        if (! $this->engine->isInstalled()) {
            throw ValidationException::withMessages(['file' => PdfEngineFailure::NotInstalled->message()]);
        }

        try {
            return $this->engine->inspect($path)->assertAcceptable();
        } catch (PdfEngineException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
    }

    /**
     * @param  string  $path  the uploaded file on disk
     * @param  string  $clientName  the name the browser gave; only a sanitised form of it is kept
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function __invoke(User $user, Company $company, string $path, string $clientName, string $title, ?int $customerId = null, ?int $supplierId = null): SigningDocument
    {
        abort_unless(app(ModuleManager::class)->isEnabled(SigningModule::KEY, $company), 404);

        if (! $user->hasTeamPermission($company, SigningModule::SEND)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(
            ['title' => trim($title), 'customer_id' => $customerId, 'supplier_id' => $supplierId],
            ['title' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]*$/u'], 'customer_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer']],
            [],
            ['title' => __('rubrik')],
        )->validate();

        $inspection = $this->inspect($path);
        $bytes = file_get_contents($path);

        // What was inspected is what is stored: the file is read once more and must be the same bytes.
        if (! is_string($bytes) || hash('sha256', $bytes) !== $inspection->sha256 || strlen($bytes) !== $inspection->bytes) {
            $this->refuse(PdfRefusal::Unreadable);
        }

        return DB::transaction(fn (): SigningDocument => CurrentCompany::run($company, function () use ($user, $company, $validated, $inspection, $bytes, $clientName): SigningDocument {
            $unsent = SigningDocument::query()->whereRaw("id::text not in (select signable_id from signing_envelopes where company_id = ? and document_type = 'DOCUMENT' and signable_id is not null)", [$company->id])->count();

            if ($unsent >= self::MAX_UNSENT) {
                throw ValidationException::withMessages(['file' => __('Det finns redan :count uppladdade dokument som inte är skickade. Skicka eller ta bort några innan du laddar upp fler.', ['count' => self::MAX_UNSENT])]);
            }

            $customer = $this->register($user, $company, Customer::class, $validated['customer_id'], 'customer_id');
            $supplier = $this->register($user, $company, Supplier::class, $validated['supplier_id'], 'supplier_id');

            $stored = "signing-documents/{$company->id}/".Str::uuid()->toString().'.pdf';
            Storage::disk(SigningRequest::DISK)->put($stored, $bytes);

            $document = SigningDocument::create([
                'company_id' => $company->id,
                'title' => $validated['title'],
                'path' => $stored,
                'name' => self::safeName($clientName),
                'sha256' => $inspection->sha256,
                'bytes' => $inspection->bytes,
                'page_count' => $inspection->pages,
                'customer_id' => $customer?->id,
                'supplier_id' => $supplier?->id,
                'uploaded_by' => $user->id,
            ]);

            Audit::record('signing.document_uploaded', $document, ['signing_document_id' => $document->id, 'pages' => $inspection->pages, 'bytes' => $inspection->bytes, 'customer_id' => $customer?->id, 'supplier_id' => $supplier?->id], $company, $user);

            return $document;
        }));
    }

    /**
     * A customer or a supplier of the company, and only when the member may
     * see that register (the link shows its name on the envelope).
     *
     * @param  class-string<Customer|Supplier>  $model
     */
    private function register(User $user, Company $company, string $model, mixed $id, string $field): Customer|Supplier|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        $row = $model::query()->find((int) $id);

        if ((! $row instanceof Customer && ! $row instanceof Supplier) || ! $user->can('viewAny', [$model, $company])) {
            throw ValidationException::withMessages([$field => $model === Customer::class ? __('Kunden finns inte.') : __('Leverantören finns inte.')]);
        }

        return $row;
    }

    /** A file name that is safe to show and to send in a header: letters, digits, spaces and a few marks, ending in .pdf. */
    public static function safeName(string $clientName): string
    {
        $base = pathinfo(str_replace('\\', '/', $clientName), PATHINFO_FILENAME);
        $base = trim(ltrim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $base), '.'));
        $base = mb_substr($base === '' ? 'dokument' : $base, 0, 100);

        return "{$base}.pdf";
    }

    /** @throws ValidationException */
    private function refuse(PdfRefusal $refusal): never
    {
        throw ValidationException::withMessages(['file' => $refusal->message()]);
    }
}
