<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;

/**
 * Quotes and orders: read by those who see invoices, written by those who
 * write them, and only while they are open.
 */
class SalesDocumentPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view');
    }

    public function view(User $user, SalesDocument $document): bool
    {
        return $this->can($user, $document, 'invoices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:create');
    }

    public function update(User $user, SalesDocument $document): bool
    {
        return $document->isOpen() && $this->can($user, $document, 'invoices:create');
    }

    public function send(User $user, SalesDocument $document): bool
    {
        return $this->can($user, $document, 'invoices:send');
    }

    private function can(User $user, SalesDocument $document, string $permission): bool
    {
        $company = Company::query()->find($document->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }
}
