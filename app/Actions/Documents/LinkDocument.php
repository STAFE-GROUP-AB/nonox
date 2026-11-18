<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentLinkType;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Links a document to a record of the same company, or removes a link. The
 * record is looked up through its company scope, so another company's id
 * is refused like a missing one.
 */
class LinkDocument
{
    public function __invoke(User $user, Document $document, string $type, int|string $id): DocumentLink
    {
        Gate::forUser($user)->authorize('update', $document);

        $company = Company::query()->findOrFail($document->company_id);
        $linkType = DocumentLinkType::tryFrom($type);

        if ($linkType === null || ! $linkType->availableFor($company, $document->retention_class)) {
            throw ValidationException::withMessages(['link_type' => __('Välj vad dokumentet ska kopplas till.')]);
        }

        return CurrentCompany::run($company->id, function () use ($user, $document, $linkType, $id): DocumentLink {
            $class = $linkType->modelClass();

            if (! is_numeric($id) || ! $class::query()->whereKey((int) $id)->exists()) {
                throw ValidationException::withMessages(['link_id' => __('Välj en post i företaget.')]);
            }

            return DB::transaction(function () use ($user, $document, $linkType, $id): DocumentLink {
                $link = DocumentLink::query()->firstOrCreate(
                    ['document_id' => $document->id, 'linkable_type' => $linkType->value, 'linkable_id' => (int) $id],
                    ['company_id' => $document->company_id, 'created_by' => $user->id],
                );

                if ($link->wasRecentlyCreated) {
                    Audit::record('document.linked', $document, ['link_id' => $link->id, 'linkable_type' => $linkType->value, 'linkable_id' => (int) $id], $document->company_id, $user);
                }

                return $link;
            });
        });
    }

    public function remove(User $user, Document $document, int $linkId): void
    {
        Gate::forUser($user)->authorize('update', $document);

        CurrentCompany::run($document->company_id, function () use ($user, $document, $linkId): void {
            $link = DocumentLink::query()->where('document_id', $document->id)->findOrFail($linkId);

            DB::transaction(function () use ($user, $document, $link): void {
                $link->delete();

                Audit::record('document.unlinked', $document, ['link_id' => $link->id, 'linkable_type' => $link->linkable_type->value, 'linkable_id' => $link->linkable_id], $document->company_id, $user);
            });
        });
    }
}
