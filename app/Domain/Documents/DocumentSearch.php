<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

/**
 * The archive's search: PostgreSQL full text (Swedish) on the title and on
 * the current version's PDF text, and a plain substring match on the title
 * and the tags, so a part of a word is found too.
 */
final class DocumentSearch
{
    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public static function apply(Builder $query, string $text): Builder
    {
        $text = trim(mb_substr($text, 0, 200));

        if ($text === '') {
            return $query;
        }

        $like = '%'.addcslashes($text, '%_\\').'%';

        return $query->where(function (Builder $query) use ($text, $like): void {
            $query->whereRaw("to_tsvector('swedish', documents.title) @@ websearch_to_tsquery('swedish', ?)", [$text])
                ->orWhere('documents.title', 'ilike', $like)
                ->orWhereExists(fn ($tags) => $tags->selectRaw('1')->from('document_tags')
                    ->whereColumn('document_tags.document_id', 'documents.id')
                    ->where('document_tags.tag', 'ilike', $like))
                ->orWhereExists(fn ($versions) => $versions->selectRaw('1')->from('document_versions')
                    ->whereColumn('document_versions.id', 'documents.current_version_id')
                    ->whereRaw("to_tsvector('swedish', coalesce(document_versions.extracted_text, '')) @@ websearch_to_tsquery('swedish', ?)", [$text]));
        });
    }
}
