<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SigningHook;
use App\Domain\Signing\SigningHooks;
use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\SigningRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PDF that was sent for signing, or its signing certificate, for the
 * company's own users (ADR-046): a quote's with invoices:view, an
 * agreement's with crm:view, a module's own type (an ÄTA) as the company
 * copy is given (SigningHook::mayReceiveCopy). Another company's request,
 * and anyone not allowed, answers 404.
 */
final class SigningFileController
{
    public function __invoke(int $signing, string $file): Response
    {
        $request = SigningRequest::query()->with(['quote', 'agreement'])->findOrFail($signing);
        abort_unless($this->allowed($request), 404);

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

    /**
     * A quote or an agreement by its own policy. Another registered type
     * (a module's, through SignableTypes, such as an ÄTA) with the
     * authorisation the company copy uses: the document's module is on and
     * every SigningHook lets the user have the copy (a field worker never).
     */
    private function allowed(SigningRequest $request): bool
    {
        if ($request->sales_document_id !== null || $request->agreement_id !== null) {
            $document = $request->isQuote() ? $request->quote : $request->agreement;

            return $document !== null && Gate::allows('view', $document);
        }

        $document = app(SignableTypes::class)->documentFor($request);
        $user = auth()->user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        return $document instanceof Signable
            && $user instanceof User
            && $company instanceof Company
            && $company->id === $request->company_id
            && SendForSigning::moduleOn($document, $company)
            && collect(app(SigningHooks::class)->all())->every(fn (SigningHook $hook): bool => $hook->mayReceiveCopy($company, $user, $request));
    }
}
