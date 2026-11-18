<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * One feature's files in the unified document list (ADR-081 gap 9, E20).
 * An adapter is a read model over its feature's own table: it never copies
 * a file, and it applies exactly the permission check of the page or file
 * route that serves the source (allows). A row is a plain database row, so
 * a page of the list costs the same number of queries whatever the history.
 *
 * Every row has the same columns: source, source_id, parent_id, title,
 * title_encrypted (the title as stored when the source encrypts it, else
 * null), doc_date, mime, size and ref (a short reference such as a voucher
 * number, translated by subtitle()).
 */
interface DocumentSource
{
    /**
     * A stable key, used in the address filter.
     *
     * @return literal-string
     */
    public function key(): string;

    public function label(): string;

    /** The source's own permission check, for the current company. */
    public function allows(User $user, Company $company): bool;

    /** Rows of this company only, already narrowed to what the user may see. */
    public function query(User $user, Company $company, string $search): Builder;

    /** Where the row opens: the file when the source serves one, else the source's page. */
    public function url(DocumentRef $row): string;

    /** What the row belongs to ("Verifikation A12"), without personal data from the registers. */
    public function subtitle(DocumentRef $row): ?string;
}
