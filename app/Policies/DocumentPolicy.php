<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentRetention;
use App\Domain\Documents\RetentionClass;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;

/**
 * The document archive (ADR-081 gap 9, E19): documents:view reads,
 * documents:manage uploads, edits, links and deletes when retention allows,
 * documents:hold sets and lifts legal hold. A personnel document also needs
 * payroll:view-sensitive (owner decision 21, DocumentAccess); without it the
 * document does not exist for the user (callers answer 404).
 */
class DocumentPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return DocumentAccess::allowsAny($user, $company, 'documents:view');
    }

    public function view(User $user, Document $document): bool
    {
        return $this->can($user, $document, 'documents:view');
    }

    public function create(User $user, Company $company): bool
    {
        return DocumentAccess::allowsAny($user, $company, 'documents:manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $this->can($user, $document, 'documents:manage');
    }

    public function delete(User $user, Document $document): bool
    {
        return DocumentRetention::blocker($document) === null && $this->can($user, $document, 'documents:manage');
    }

    public function hold(User $user, Document $document): bool
    {
        return $this->can($user, $document, 'documents:hold');
    }

    /** Whether the user may see personnel documents at all. */
    public static function seesPersonnel(User $user, Company $company): bool
    {
        return DocumentAccess::allows($user, $company, 'documents:view', RetentionClass::Personnel);
    }

    private function can(User $user, Document $document, string $permission): bool
    {
        $company = Company::query()->find($document->company_id);

        return $company instanceof Company && DocumentAccess::allows($user, $company, $permission, $document->retention_class);
    }
}
