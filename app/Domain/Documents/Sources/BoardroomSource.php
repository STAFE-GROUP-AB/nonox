<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\ArchivedCompany;
use Illuminate\Database\Query\Builder;

/**
 * Approved board and general meeting minutes (ADR-070). The same check as
 * BoardroomDocumentsController::minutes: meetings:view, read through
 * ArchivedCompany::reading as the controller does, so the minutes of an
 * archived company stay listed. Only approved minutes with their stored PDF are
 * listed, as the controller serves only those.
 */
final class BoardroomSource extends BaseSource
{
    public function key(): string
    {
        return 'boardroom';
    }

    public function label(): string
    {
        return __('Styrelserum, protokoll');
    }

    public function allows(User $user, Company $company): bool
    {
        return (bool) ArchivedCompany::reading(fn (): bool => $user->hasTeamPermission($company, 'meetings:view'));
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = $this->select('boardroom_minutes as m', [
            'source_id' => 'm.id',
            'title' => "'protokoll-' || m.id::text || '.pdf'",
            'doc_date' => 'coalesce(m.approved_at, m.created_at)',
            'mime' => "'application/pdf'",
            'ref' => "coalesce(m.number::text, '')",
        ])->where('m.company_id', $company->id)->where('m.status', 'APPROVED')->whereNotNull('m.pdf_path');

        return $this->search($query, "('protokoll-' || m.id::text || '.pdf')", $search);
    }

    public function url(DocumentRef $row): string
    {
        return route('boardroom.minutes.pdf', ['minutes' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        return $row->ref === '' ? __('Protokoll') : __('Protokoll nr :number', ['number' => $row->ref]);
    }
}
