<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Files attached in customer portal threads (ADR-075). The same check as
 * CompanyAttachmentController: customer-portal:view. The subject of the
 * thread is written by the customer and is shown as plain text only.
 */
final class PortalSource extends BaseSource
{
    public function key(): string
    {
        return 'portal';
    }

    public function label(): string
    {
        return __('Kundportalen');
    }

    public function allows(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'customer-portal:view');
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = $this->select(
            'customer_portal_attachments as a join customer_portal_messages as m on m.id = a.message_id and m.company_id = a.company_id join customer_portal_threads as t on t.id = m.thread_id and t.company_id = m.company_id',
            [
                'source_id' => 'a.id',
                'parent_id' => 't.id',
                'title' => 'a.original_name',
                'doc_date' => 'a.created_at',
                'mime' => 'a.mime',
                'size' => 'a.size',
                'ref' => 'left(t.subject, 120)',
            ],
        )->where('a.company_id', $company->id);

        return $this->search($query, 'a.original_name', $search);
    }

    public function url(DocumentRef $row): string
    {
        return route('portal.admin.attachment', ['thread' => $row->parentId, 'attachment' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        return $row->ref === '' || $row->ref === null ? __('Fråga i kundportalen') : __('Fråga i kundportalen: :subject', ['subject' => $row->ref]);
    }
}
