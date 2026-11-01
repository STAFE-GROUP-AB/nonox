<?php

declare(strict_types=1);

namespace App\Livewire\Overview;

use App\Domain\Overview\Overview;
use App\Models\Company;
use App\Models\User;
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

    public function render(Overview $overview): View
    {
        $user = $this->user();
        $company = $this->company();
        $data = $overview->for($user, $company);

        return view('livewire.overview.page', [
            'user' => $user,
            'company' => $company,
            'month' => CarbonImmutable::today()->translatedFormat('F Y'),
            'updatedAt' => CarbonImmutable::parse($data['generated_at'])->setTimezone(config('app.timezone'))->format('H:i'),
            'kpis' => $data['kpis'],
            'todos' => $data['todos'],
            'vouchers' => $data['vouchers'],
            'canSeeStatistics' => $user->hasTeamPermission($company, 'reports:view'),
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
