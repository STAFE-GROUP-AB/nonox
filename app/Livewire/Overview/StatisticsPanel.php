<?php

declare(strict_types=1);

namespace App\Livewire\Overview;

use App\Domain\Overview\Overview;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Översikt → Statistik och prognos (ADR-027). Loaded only when the tab is
 * opened, drawn with Chart.js in the browser, every chart with a table of
 * the same figures.
 */
#[Lazy]
class StatisticsPanel extends Component
{
    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);
    }

    #[On('overview-refreshed')]
    public function refreshed(): void
    {
        // Rendering again reads the new figures; the charts are drawn anew.
    }

    public function placeholder(): View
    {
        return view('livewire.overview.statistics-placeholder');
    }

    public function render(Overview $overview): View
    {
        return view('livewire.overview.statistics', [
            'stats' => $overview->statistics($this->user(), $this->company()),
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
