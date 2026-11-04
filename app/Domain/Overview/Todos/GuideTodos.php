<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\CompanyGuideStep;
use App\Models\User;
use App\Support\Guide\Guide;
use App\Support\Guide\StartPlanStep;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Företagsguiden's start plan (ADR-051) while steps remain, for those who
 * may tick them, unless the company has turned the guide off. One query.
 */
final class GuideTodos implements TodoProvider
{
    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $company->guide_enabled || ! $user->hasTeamPermission($company, 'company:update')) {
            return [];
        }

        $keys = array_map(fn (StartPlanStep $step): string => $step->key, Guide::forCompany($company));

        if ($keys === []) {
            return [];
        }

        $done = CurrentCompany::run($company, fn (): int => CompanyGuideStep::query()->whereIn('step_key', $keys)->count());

        if ($done >= count($keys)) {
            return [];
        }

        return [new TodoItem(
            'guide-start-plan',
            'task',
            __('Fortsätt din startplan (:done av :total klara)', ['done' => $done, 'total' => count($keys)]),
            __('Det ett nytt företag behöver göra, steg för steg, i Företagsguiden.'),
            route('guide.plan'),
            TodoItem::PRIORITY_OTHER,
        )];
    }

    public function invalidatedBy(): array
    {
        return [CompanyGuideStep::class, Company::class];
    }
}
