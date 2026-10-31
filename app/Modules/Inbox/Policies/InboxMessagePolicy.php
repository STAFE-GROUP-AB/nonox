<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Models\InboxMessage;

/**
 * Who may see and handle a company's inbox: membership of the company and
 * the inbox permissions (AGENTS.md §4).
 */
class InboxMessagePolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inbox:view');
    }

    public function view(User $user, InboxMessage $message): bool
    {
        return $this->can($user, $message, 'inbox:view');
    }

    public function manage(User $user, InboxMessage $message): bool
    {
        return $this->can($user, $message, 'inbox:manage');
    }

    private function can(User $user, InboxMessage $message, string $permission): bool
    {
        $company = Company::query()->find($message->company_id);

        return $company instanceof Company && $user->belongsToTeam($company) && $user->hasTeamPermission($company, $permission);
    }
}
