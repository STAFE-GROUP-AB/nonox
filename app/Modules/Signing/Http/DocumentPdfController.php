<?php

declare(strict_types=1);

namespace App\Modules\Signing\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\ChosenDocument;
use App\Support\Files\InlineFile;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The PDF the field editor (S8) shows to the sender while the fields are
 * placed: the document the wizard has chosen, byte for byte, from NONOX's
 * own origin so that PDF.js loads it under the page's content security
 * policy (no CDN, worker-src 'self'). Only a member who may send the
 * document gets it, and one who may not gets a 404, as the wizard does.
 */
final class DocumentPdfController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless($user->hasTeamPermission($company, SigningModule::SEND), 404);

        $document = ChosenDocument::resolve($user, $company, self::text($request->query('typ')), self::text($request->query('dokument')));
        $document->authorizeSigning($user);
        abort_unless($document->signingIsOpen(), 404);

        $pdf = CurrentCompany::run($company, fn (): array => $document->signingDocument());

        return response($pdf['bytes'], 200, [
            ...InlineFile::headers('application/pdf'),
            'Content-Disposition' => 'inline; filename="dokument.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** A query value that is a string, else null: ?typ[]=x is no document type and answers 404, never a TypeError. */
    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
