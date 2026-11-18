<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

/**
 * The company documents linked to one data subject (a customer, supplier or
 * employee), for the registerutdrag (GDPR art. 15). Title, class, retention
 * and every version with dates, checksum and file address. Only documents
 * the requester may read are listed (personnel documents need
 * payroll:view-sensitive); the others are counted, so the extract says that
 * something is left out without naming it.
 */
final class DocumentExtract
{
    /** @return array{dokument: list<array<string, mixed>>, dokument_utan_behorighet: int} */
    public static function for(DocumentLinkType $type, int $id, User $user, Company $company): array
    {
        $linked = Document::query()
            ->whereHas('links', fn ($links) => $links->where('linkable_type', $type->value)->where('linkable_id', $id))
            ->with(['versions' => fn ($versions) => $versions->orderBy('version')])
            ->orderBy('id')
            ->get();

        $documents = [];
        $hidden = 0;
        $readable = array_map(fn (RetentionClass $class): string => $class->value, DocumentAccess::classes($user, $company, 'documents:view'));

        foreach ($linked as $document) {
            $files = in_array($document->retention_class->value, $readable, true);

            // A document the requester may not read is counted, never listed: not its title nor its category.
            if (! $files) {
                $hidden++;

                continue;
            }

            $documents[] = [
                'id' => $document->id,
                'rubrik' => $document->title,
                'kategori' => DocumentCategory::labelOf($document->category),
                'sparas_som' => $document->retention_class->value,
                'sparas_till_och_med' => $document->retain_until?->toDateString(),
                'bevarandesparr' => $document->legal_hold,
                'skapad' => $document->created_at->toIso8601String(),
                'versioner' => $document->versions->map(fn (DocumentVersion $version): array => [
                    'version' => $version->version,
                    'filnamn' => $version->original_name,
                    'typ' => $version->mime,
                    'storlek_byte' => $version->size,
                    'sha256' => $version->sha256,
                    'uppladdad' => $version->created_at->toIso8601String(),
                    'fil' => route('documents.file', [$document->id, $version->id]),
                ])->all(),
            ];
        }

        return ['dokument' => $documents, 'dokument_utan_behorighet' => $hidden];
    }
}
