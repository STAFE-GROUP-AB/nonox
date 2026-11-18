<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Domain\Documents\DocumentCategory;
use App\Domain\Documents\DocumentSearch;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Gate;

/**
 * The company's own documents (E19). The same checks as the archive page:
 * the DocumentPolicy viewAny and, per retention class, DocumentAccess (a
 * personnel document additionally needs payroll:view-sensitive), which
 * Document::visibleTo applies to the query. The archive's own search (title,
 * tags and extracted text) is used. A personnel document's title is stored
 * encrypted and is not searchable.
 */
final class CompanyDocumentSource extends BaseSource
{
    public function key(): string
    {
        return 'document';
    }

    public function label(): string
    {
        return __('Företagets dokument');
    }

    public function allows(User $user, Company $company): bool
    {
        return Gate::forUser($user)->allows('viewAny', [Document::class, $company]);
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = Document::query()->visibleTo($user, $company);

        return DocumentSearch::apply($query, $search)
            ->toBase()
            ->leftJoin('document_versions as dv', fn ($join) => $join->on('dv.id', '=', 'documents.current_version_id')->on('dv.company_id', '=', 'documents.company_id'))
            ->where('documents.company_id', $company->id)
            ->select(new Expression($this->columnsSql([
                'source_id' => 'documents.id',
                'title' => 'documents.title',
                'title_encrypted' => "case when documents.retention_class = 'PERSONNEL' then documents.title_encrypted end",
                'doc_date' => 'documents.updated_at',
                'mime' => 'dv.mime',
                'size' => 'dv.size',
                'ref' => 'documents.category',
            ])));
    }

    public function url(DocumentRef $row): string
    {
        return route('documents.show', ['document' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): ?string
    {
        return $row->ref === null || $row->ref === '' ? null : DocumentCategory::labelOf((string) $row->ref);
    }
}
