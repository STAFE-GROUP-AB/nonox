<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\AccessFingerprint;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Översikt for one user in one company (ADR-027): the key figures, "Att
 * göra" and the latest vouchers, and on its own tab the statistics.
 *
 * Everything is computed once and cached per company, access fingerprint
 * (role and modules) and day, so everyone with the same role shares the
 * entry; personal todos are cached per user. OverviewCache makes the
 * entries stale when something changes, and the reload arrow does it by
 * hand. A warm read costs one query: the access fingerprint.
 */
final class Overview
{
    public function __construct(
        private readonly OverviewKpis $kpis,
        private readonly TodoProviders $providers,
        private readonly RecentVouchers $vouchers,
        private readonly Statistics $statistics,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     kpis: list<array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}>,
     *     todos: list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}>,
     *     vouchers: ?list<array{id: string, series: string, number: ?int, description: string, date: string, amount: int, signed: bool}>
     * }
     */
    public function for(User $user, Company $company, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $fingerprint = AccessFingerprint::for($user, $company->id);
        $day = $today->toDateString();

        /** @var array{generated_at: string, kpis: list<array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}>, todos: list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}>, vouchers: ?list<array{id: string, series: string, number: ?int, description: string, date: string, amount: int, signed: bool}>} $shared */
        $shared = OverviewCache::remember($company->id, "shared:{$fingerprint}:{$day}", fn (): array => CurrentCompany::run($company, fn (): array => [
            'generated_at' => now()->toIso8601String(),
            'kpis' => $this->kpis->for($user, $company, $today),
            'todos' => $this->todos($this->providers->shared($company), $user, $company, $today),
            'vouchers' => $user->hasTeamPermission($company, 'vouchers:view') ? $this->vouchers->latest() : null,
        ]));

        $personal = $this->providers->personal($company);

        if ($personal !== []) {
            /** @var list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}> $mine */
            $mine = OverviewCache::remember($company->id, "user:{$user->id}:{$fingerprint}:{$day}", fn (): array => CurrentCompany::run($company, fn (): array => $this->todos($personal, $user, $company, $today)));
            $shared['todos'] = TodoItem::sort([...$shared['todos'], ...$mine]);
        }

        return $shared;
    }

    /**
     * The statistics tab, cached like the rest; null for a role without
     * reports:view or a company without a fiscal year.
     *
     * @return array<string, mixed>|null
     */
    public function statistics(User $user, Company $company, ?CarbonImmutable $today = null): ?array
    {
        if (! $user->hasTeamPermission($company, 'reports:view')) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $fingerprint = AccessFingerprint::for($user, $company->id);

        /** @var array<string, mixed>|null */
        return OverviewCache::remember($company->id, "statistics:{$fingerprint}:{$today->toDateString()}", fn (): ?array => CurrentCompany::run($company, fn (): ?array => $this->statistics->for($user, $company, $today)));
    }

    /** The reload arrow: everything of the company is computed again on the next read. */
    public function refresh(Company $company): void
    {
        OverviewCache::forget($company->id);
    }

    /**
     * @param  list<Contracts\TodoProvider>  $providers
     * @return list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}>
     */
    private function todos(array $providers, User $user, Company $company, CarbonImmutable $today): array
    {
        $items = [];

        foreach ($providers as $provider) {
            foreach ($provider->todos($user, $company, $today) as $item) {
                $items[] = $item->toArray();
            }
        }

        return TodoItem::sort($items);
    }
}
