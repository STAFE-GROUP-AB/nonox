<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Signing\SigningPdfs;
use App\Models\SigningRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PDF that was sent for signing, or its signing certificate, for the
 * company's own users (ADR-046): a quote's with invoices:view, an
 * agreement's with crm:view. Another company's request answers 404.
 */
final class SigningFileController
{
    public function __invoke(int $signing, string $file): Response
    {
        $request = SigningRequest::query()->with(['quote', 'agreement'])->findOrFail($signing);
        $document = $request->isQuote() ? $request->quote : $request->agreement;
        abort_unless($document !== null && Gate::allows('view', $document), 404);

        $path = $file === 'signeringsbevis' ? $request->signed_document_path : $request->document_path;
        $name = $file === 'signeringsbevis' ? SigningPdfs::certificateFilename($request) : $request->document_name;
        abort_if($path === null, 404);

        $bytes = Storage::disk(SigningRequest::DISK)->get($path);
        abort_unless(is_string($bytes), 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
