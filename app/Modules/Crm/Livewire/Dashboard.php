<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Modules\Crm\Domain\GoalProgress;
use App\Modules\Crm\Domain\PipelineFigures;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The CRM's dashboard (ADR-043 stage 2): the open pipeline's value and
 * weighted value, the share of deals won and the average sales cycle over
 * a chosen period, completed activities per week for the last eight weeks,
 * this month's goal progress and the deals without a next step. Everyone's
 * by default, or the user's own. A fixed number of queries.
 */
class Dashboard extends Component
{
    use ActsInCompany;

    /** The period, in days, for the win rate and the sales cycle. */
    public const array PERIODS = [30, 90, 365];

    #[Url(as: 'period', except: '90')]
    public string $period = '90';

    /** '' for the company, 'mina' for the user's own. */
    #[Url(as: 'vem', except: '')]
    public string $who = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Deal::class, $this->company()]), 403);
    }

    public function render(PipelineFigures $figures, GoalProgress $goals): View
    {
        $company = $this->company();
        $user = $this->user();
        $now = CarbonImmutable::now();
        $days = in_array((int) $this->period, self::PERIODS, true) ? (int) $this->period : 90;
        $from = $now->setTimezone(PipelineFigures::ZONE)->subDays($days - 1)->startOfDay();
        $ownerId = $this->who === 'mina' ? $user->id : null;
        // Sellers see their own goals; those who set goals see everyone's.
        $allGoals = $user->can('settings', [Goal::class, $company]) && $ownerId === null;

        return view('crm::livewire.dashboard', [
            'open' => $figures->open($ownerId),
            'winRate' => $figures->winRate($from, $now, $ownerId),
            'cycle' => $figures->cycleDays($from, $now, $ownerId),
            'weeks' => $figures->activitiesPerWeek($now, 8, $ownerId),
            'missing' => $figures->missingNextStep($ownerId),
            'goals' => $goals->for($now, $allGoals ? null : [$user->id]),
            'days' => $days,
            'allGoals' => $allGoals,
        ]);
    }
}
