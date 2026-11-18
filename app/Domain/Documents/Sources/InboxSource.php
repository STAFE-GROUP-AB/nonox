<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Attachments of messages in Inkorgen (ADR-023). The same check as
 * InboxMessagePolicy::view: inbox:view. Blocked attachments are never
 * listed, as InboxFileController never serves them.
 */
final class InboxSource extends BaseSource
{
    public function key(): string
    {
        return 'inbox';
    }

    public function label(): string
    {
        return __('Inkorgen');
    }

    public function allows(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inbox:view');
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = $this->select(
            'inbox_attachments as a join inbox_messages as m on m.id = a.inbox_message_id and m.company_id = a.company_id',
            [
                'source_id' => 'a.id',
                'parent_id' => 'a.inbox_message_id',
                'title' => 'a.filename',
                'doc_date' => 'coalesce(m.received_at, a.created_at)',
                'mime' => 'a.mime',
                'size' => 'a.size',
            ],
        )->where('a.company_id', $company->id)->whereNull('a.blocked_reason');

        return $this->search($query, 'a.filename', $search);
    }

    public function url(DocumentRef $row): string
    {
        return route('inbox.attachment', ['message' => $row->parentId, 'attachment' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        return __('Bilaga i Inkorgen');
    }
}
