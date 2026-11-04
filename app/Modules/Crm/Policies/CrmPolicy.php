<?php

declare(strict_types=1);

namespace App\Modules\Crm\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;

/**
 * Every CRM record (ADR-043): hasTeamPermission checks the membership first,
 * and a module permission is false while the module is off. Only crm:manage
 * writes; the key figures are part of crm:view, since a seller needs to know
 * whether a customer pays.
 *
 * Stage 2: deals are seen with crm:view and created, moved, won and lost
 * with crm:manage. Every seller sees every deal of the company: the simple
 * model, so a colleague can pick up a deal. Pipelines, stages, loss reasons
 * and goals are crm:settings.
 *
 * Stage 3 (ADR-046): agreements are seen with crm:view and written with
 * crm:manage, like deals.
 */
class CrmPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:view');
    }

    public function view(User $user, Account|Person|Activity|Deal|Pipeline|PipelineStage|LossReason|Goal|Agreement $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $this->viewAny($user, $company);
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:manage');
    }

    public function update(User $user, Account|Person|Activity|Deal|Pipeline|PipelineStage|LossReason|Goal|Agreement $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $this->manage($user, $company);
    }

    public function settings(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:settings');
    }

    /** Pipelines, stages, loss reasons and goals. */
    public function configure(User $user, Pipeline|PipelineStage|LossReason|Goal $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $this->settings($user, $company);
    }
}
