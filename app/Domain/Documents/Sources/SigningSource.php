<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * The PDFs sent for signing and the signed copies with their certificates
 * (crm_signing_requests). The same check as SigningFileController for a
 * request of a quote (SalesDocumentPolicy::view: invoices:view) or of an
 * agreement (CrmPolicy::view: crm:view). Requests of a module's own type
 * (an ÄTA) go through that module's SigningHook and are not listed here.
 * One row per signing request and file.
 */
final class SigningSource extends BaseSource
{
    public function key(): string
    {
        return 'signing';
    }

    public function label(): string
    {
        return __('Signering');
    }

    public function allows(User $user, Company $company): bool
    {
        return $this->mayQuotes($user, $company) || $this->mayAgreements($user, $company);
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        /** @var list<array{literal-string, literal-string, literal-string}> $parts */
        $parts = [];

        if ($this->mayQuotes($user, $company)) {
            $parts[] = ['sales_documents', 'sales_document_id', "'Q' || d.number::text"];
        }

        if ($this->mayAgreements($user, $company)) {
            $parts[] = ['crm_agreements', 'agreement_id', "'A' || d.id::text"];
        }

        $query = null;

        foreach ($parts as [$table, $column, $number]) {
            foreach (['dokument', 'signeringsbevis'] as $file) {
                $part = $this->part($company, $search, $table, $column, $number, $file);
                $query = $query === null ? $part : $query->unionAll($part);
            }
        }

        return $query ?? $this->part($company, $search, 'sales_documents', 'sales_document_id', "'Q' || d.number::text", 'dokument')->whereRaw('false');
    }

    public function url(DocumentRef $row): string
    {
        return route('signing.file', ['signing' => $row->sourceId, 'file' => $row->parentId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        $number = substr((string) $row->ref, 1);
        $signed = $row->parentId === 'signeringsbevis';

        if (str_starts_with((string) $row->ref, 'A')) {
            return $signed ? __('Avtal :number, signeringsbevis', ['number' => $number]) : __('Avtal :number, skickat för signering', ['number' => $number]);
        }

        return $signed ? __('Offert :number, signeringsbevis', ['number' => $number]) : __('Offert :number, skickad för signering', ['number' => $number]);
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $column
     * @param  literal-string  $number  SQL for the quote's or agreement's number, prefixed Q or A
     * @param  literal-string  $file
     */
    private function part(Company $company, string $search, string $table, string $column, string $number, string $file): Builder
    {
        $signed = $file === 'signeringsbevis';
        $path = $signed ? 'r.signed_document_path' : 'r.document_path';

        $query = $this->select(
            "crm_signing_requests as r join {$table} as d on d.id = r.{$column} and d.company_id = r.company_id",
            [
                'source_id' => 'r.id',
                'parent_id' => "'{$file}'",
                'title' => 'r.document_name',
                'doc_date' => $signed ? 'r.signed_at' : 'r.created_at',
                'mime' => "'application/pdf'",
                'ref' => $number,
            ],
        )->where('r.company_id', $company->id)->whereNotNull($path);

        return $this->search($query, 'r.document_name', $search);
    }

    private function mayQuotes(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view');
    }

    private function mayAgreements(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:view');
    }
}
