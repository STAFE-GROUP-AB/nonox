<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Documents\Sources\AgreementSource;
use App\Domain\Documents\Sources\BoardroomSource;
use App\Domain\Documents\Sources\CompanyDocumentSource;
use App\Domain\Documents\Sources\DocumentRef;
use App\Domain\Documents\Sources\DocumentSource;
use App\Domain\Documents\Sources\InboxSource;
use App\Domain\Documents\Sources\PortalSource;
use App\Domain\Documents\Sources\SigningSource;
use App\Domain\Documents\Sources\StaffSource;
use App\Domain\Documents\Sources\SupplierInvoiceSource;
use App\Domain\Documents\Sources\VoucherSource;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * The unified document list (ADR-081 gap 9, E20): the company's own
 * documents and the files of the feature sources in one list, newest first.
 * Each source is a DocumentSource adapter with its own permission check; a
 * source the user may not read contributes no row. The sources are combined
 * in one UNION query, so a page costs a count and a select whatever the
 * number of documents. No file is copied: a row links to the source.
 */
final class DocumentOverview
{
    /** @return list<DocumentSource> */
    public static function sources(): array
    {
        return [
            new CompanyDocumentSource,
            new VoucherSource,
            new SupplierInvoiceSource,
            new InboxSource,
            new AgreementSource,
            new SigningSource,
            new PortalSource,
            new BoardroomSource,
            new StaffSource,
        ];
    }

    /** @return list<DocumentSource> the sources the user may read in the company */
    public static function allowed(User $user, Company $company): array
    {
        return array_values(array_filter(self::sources(), fn (DocumentSource $source): bool => $source->allows($user, $company)));
    }

    /** @return LengthAwarePaginator<int, DocumentRow> */
    public static function page(User $user, Company $company, string $sourceKey, string $search, int $perPage): LengthAwarePaginator
    {
        $allowed = self::allowed($user, $company);
        $chosen = array_values(array_filter($allowed, fn (DocumentSource $source): bool => $sourceKey === '' || $source->key() === $sourceKey));
        $search = trim(mb_substr($search, 0, 100));

        $union = null;

        foreach ($chosen as $source) {
            $part = $source->query($user, $company, $search);
            $union = $union === null ? $part : $union->unionAll($part);
        }

        $page = $union === null
            ? new LengthAwarePaginator([], 0, $perPage)
            : DB::query()->fromSub($union, 'u')
                ->orderByRaw('doc_date desc nulls last')->orderBy('source')->orderByDesc('source_id')
                ->paginate($perPage);

        $bySource = [];

        foreach ($allowed as $source) {
            $bySource[$source->key()] = $source;
        }

        /** @var LengthAwarePaginator<int, DocumentRow> $rows */
        $rows = $page->through(function (stdClass $row) use ($bySource): DocumentRow {
            $source = $bySource[(string) $row->source];
            $ref = new DocumentRef((string) $row->source_id, $row->parent_id === null ? null : (string) $row->parent_id, $row->ref === null ? null : (string) $row->ref);

            return new DocumentRow(
                source: $source->key(),
                sourceId: $ref->sourceId,
                parentId: $ref->parentId,
                title: self::title($row),
                date: $row->doc_date === null ? null : CarbonImmutable::parse((string) $row->doc_date),
                size: $row->size === null ? null : (int) $row->size,
                label: $source->label(),
                subtitle: $source->subtitle($ref),
                url: $source->url($ref),
            );
        });

        return $rows;
    }

    private static function title(stdClass $row): string
    {
        if ($row->title_encrypted === null) {
            return (string) $row->title;
        }

        try {
            return Crypt::decryptString((string) $row->title_encrypted);
        } catch (Throwable) {
            return '–';
        }
    }
}
