<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Signable;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The document the send wizard has chosen, found and authorised in one place
 * for the wizard and for the file the field editor shows (ADR-046, S7, S8):
 * a quote needs invoices:view, an agreement CRM, an uploaded document is its
 * uploader's until it is sent (signing:manage may take it over). A key that
 * is not a key, a document of another company or one the member may not see
 * is a 404, never a database error and never a hint that it exists.
 */
final class ChosenDocument
{
    public const string AGREEMENT = 'AGREEMENT';

    public static function resolve(User $user, Company $company, ?string $type, ?string $key): Signable
    {
        abort_unless($type !== null && $key !== null, 404);
        abort_unless($type === self::AGREEMENT ? ctype_digit($key) : Str::isUuid($key), 404);

        $document = match ($type) {
            SigningRequest::QUOTE => SalesDocument::query()->where('kind', SalesDocument::QUOTE)->findOrFail($key),
            self::AGREEMENT => Agreement::query()->findOrFail((int) $key),
            SigningDocument::SIGNING_TYPE => SigningDocument::query()->findOrFail($key),
            default => abort(404),
        };

        abort_unless(SendForSigning::moduleOn($document, $company), 404);

        if ($document instanceof SigningDocument) {
            abort_unless($document->uploaded_by === $user->id || $user->hasTeamPermission($company, SigningModule::MANAGE), 404);

            return $document;
        }

        abort_unless(Gate::forUser($user)->allows('view', $document), 404);

        return $document;
    }
}
