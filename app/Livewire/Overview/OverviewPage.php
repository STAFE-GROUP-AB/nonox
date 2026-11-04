<?php

declare(strict_types=1);

namespace App\Livewire\Overview;

use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Overview\Overview;
use App\Domain\Overview\OverviewCache;
use App\Models\Company;
use App\Models\User;
use App\Support\Guide\Guide;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Översikt (ADR-027): greeting, key figures, "Att göra" and the latest
 * vouchers, read from the cache. The reload arrow computes everything again
 * and tells the statistics tab to follow.
 */
class OverviewPage extends Component
{
    public function reload(Overview $overview): void
    {
        $overview->refresh($this->company());
        $this->dispatch('overview-refreshed');
    }

    public function render(Overview $overview, CompanyDeadlines $deadlines): View
    {
        $user = $this->user();
        $company = $this->company();
        $data = $overview->for($user, $company);
        $today = CarbonImmutable::today();
        // Nästa datum (ADR-051, stage 2): the next three dates on Mina datum, cached with the overview.
        /** @var list<array{key: string, type: string, title: string, date: string, period: string, applies_because: string, route: ?string, article: ?string, source_url: ?string, recommendation: bool, legal_verify: bool}> $nextDates */
        $nextDates = $company->guide_enabled
            ? OverviewCache::remember($company->id, "deadlines:{$today->toDateString()}:".Guide::version(), fn (): array => array_map(
                fn (Deadline $deadline): array => $deadline->toArray(),
                array_slice($deadlines->upcoming($company, $today), 0, 3),
            ))
            : [];

        return view('livewire.overview.page', [
            'user' => $user,
            'company' => $company,
            'month' => CarbonImmutable::today()->translatedFormat('F Y'),
            'updatedAt' => CarbonImmutable::parse($data['generated_at'])->setTimezone(config('app.timezone'))->format('H:i'),
            'kpis' => $data['kpis'],
            'todos' => $data['todos'],
            'vouchers' => $data['vouchers'],
            'canSeeStatistics' => $user->hasTeamPermission($company, 'reports:view'),
            'nextDates' => array_map(fn (array $item): Deadline => Deadline::fromArray($item), $nextDates),
        ]);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
