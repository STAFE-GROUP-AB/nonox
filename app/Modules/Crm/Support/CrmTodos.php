<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Domain\Overview\Contracts\PersonalTodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;
use Carbon\CarbonImmutable;

/**
 * CRM's part of "Att göra" on Översikt (ADR-043): the user's own activities
 * that are overdue, and, for those who manage the CRM, the people and
 * prospects that privacy:prune erases within 30 days unless someone logs an
 * activity. Three queries. Personal: the overdue activities are the user's
 * own, so the list is cached per user.
 */
final class CrmTodos implements PersonalTodoProvider
{
    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'crm:view')) {
            return [];
        }

        $todos = [];
        $overdue = Activity::query()
            ->where('assigned_to', $user->id)
            ->whereNull('done_at')
            ->where('due_at', '<', now())
            ->count();

        if ($overdue > 0) {
            $todos[] = new TodoItem(
                'crm-overdue',
                'task',
                $overdue === 1 ? __('1 aktivitet i CRM är försenad') : __(':count aktiviteter i CRM är försenade', ['count' => $overdue]),
                __('Samtal, möten och uppgifter som skulle ha gjorts. Klarmarkera dem eller flytta dem framåt.'),
                route('crm.activities'),
                TodoItem::PRIORITY_OTHER,
                $today->subDay(),
            );
        }

        if (! $user->hasTeamPermission($company, 'crm:manage')) {
            return $todos;
        }

        $cutoff = CarbonImmutable::now()->addDays(CrmSettings::PRUNE_NOTICE_DAYS)->subMonthsNoOverflow(CrmSettings::for($company)['prune_after_months']);
        $people = PruneCandidates::people($cutoff)->count();
        $prospects = PruneCandidates::prospects($cutoff)->count();

        if ($people + $prospects > 0) {
            $todos[] = new TodoItem(
                'crm-prune',
                'clock',
                __(':count personer och prospekt i CRM raderas snart', ['count' => $people + $prospects]),
                __('Ingen aktivitet på länge. De raderas inom :days dagar enligt företagets gallringsregel, om ingen loggar en aktivitet.', ['days' => CrmSettings::PRUNE_NOTICE_DAYS]),
                route('crm.people', ['visa' => 'gallras']),
                TodoItem::PRIORITY_OTHER,
                $today->addDays(CrmSettings::PRUNE_NOTICE_DAYS),
            );
        }

        $review = Suppression::query()->where('updated_at', '<', CarbonImmutable::now()->subYears(CrmSettings::for($company)['suppression_review_years']))->count();

        if ($review > 0) {
            $todos[] = new TodoItem(
                'crm-suppressions',
                'clock',
                __(':count rader på spärrlistan i CRM ska granskas', ['count' => $review]),
                __('Personer som har invänt mot att bli kontaktade. Behåll raden om invändningen fortfarande gäller, annars ta bort den.'),
                route('crm.people', ['visa' => 'sparrlista']),
                TodoItem::PRIORITY_OTHER,
            );
        }

        return $todos;
    }

    public function invalidatedBy(): array
    {
        return [Activity::class, Person::class, Account::class, Suppression::class];
    }
}
