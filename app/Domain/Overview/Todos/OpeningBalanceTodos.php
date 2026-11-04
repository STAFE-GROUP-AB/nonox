<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Accounting\StaleOpeningBalances;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;

/**
 * Opening balances that no longer agree with the year before
 * (StaleOpeningBalances), for those who may update them.
 */
final class OpeningBalanceTodos implements TodoProvider
{
    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            return [];
        }

        return array_map(fn (array $stale): TodoItem => new TodoItem(
            "stale-opening-balances-{$stale['to_id']}",
            'calendar',
            __('Uppdatera ingående balanser för :year', ['year' => $stale['to']]),
            $stale['locked']
                ? ($stale['from_open'] ? __('Bokföringen är låst från första dagen i :to. De ingående balanserna förs över på nytt när du stänger :from under Bokslut.', ['to' => $stale['to'], 'from' => $stale['from']]) : __('Bokföringen är låst från första dagen i :to, så de ingående balanserna kan inte uppdateras.', ['to' => $stale['to']]))
                : trans_choice(':count konto skiljer från de utgående balanserna för :from.|:count konton skiljer från de utgående balanserna för :from.', count($stale['accounts']), ['count' => count($stale['accounts']), 'from' => $stale['from']]),
            route('accounting.fiscalYears'),
            TodoItem::PRIORITY_CLOSING,
        ), StaleOpeningBalances::for($company));
    }

    public function invalidatedBy(): array
    {
        return [OpeningBalance::class, Voucher::class];
    }
}
