<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\SaveGoal;
use App\Modules\Crm\Domain\Forecast;
use App\Modules\Crm\Domain\GoalMetric;
use App\Modules\Crm\Domain\GoalProgress;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Prognos och mål (ADR-043 stage 2): the forecast per month, the open
 * deals' value weighted by their stage's probability by expected close
 * date, next to what was won, with overdue, undated and later deals apart;
 * and the month's goals per seller with the progress measured from data.
 * Those with crm:settings set the goals; a seller sees their own.
 */
class ForecastPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    public const array HORIZONS = [6, 12];

    #[Url(as: 'manader', except: '6')]
    public string $horizon = '6';

    /** '' for the company, 'mina' for the user's own. */
    #[Url(as: 'vem', except: '')]
    public string $who = '';

    /** The goals' month, Y-m; this month when empty. */
    #[Url(as: 'manad', except: '')]
    public string $month = '';

    /** @var array<string, string> */
    public array $goal = ['user_id' => '', 'metric' => 'MEETINGS', 'target' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Deal::class, $this->company()]), 403);
    }

    public function saveGoal(SaveGoal $save): void
    {
        $saved = $save($this->user(), $this->company(), [
            'user_id' => $this->goal['user_id'] === '' ? null : (int) $this->goal['user_id'],
            'month' => $this->monthStart()->format('Y-m'),
            'metric' => $this->goal['metric'],
            'target' => $this->goal['target'],
        ]);

        $this->goal['target'] = '';
        $this->toast($saved === null ? __('Målet är borttaget.') : __('Målet är sparat.'));
    }

    public function removeGoal(int $goalId, SaveGoal $save): void
    {
        $goal = Goal::query()->findOrFail($goalId);

        $save($this->user(), $this->company(), ['user_id' => $goal->user_id, 'month' => $goal->month->format('Y-m'), 'metric' => $goal->metric->value, 'target' => '']);

        $this->toast(__('Målet är borttaget.'));
    }

    public function render(Forecast $forecast, GoalProgress $progress): View
    {
        $company = $this->company();
        $user = $this->user();
        $now = CarbonImmutable::now();
        $months = in_array((int) $this->horizon, self::HORIZONS, true) ? (int) $this->horizon : 6;
        $monthStart = $this->monthStart();
        $canSet = $user->can('settings', [Goal::class, $company]);

        return view('crm::livewire.forecast', [
            'forecast' => $forecast->for($now, $months, 3, $this->who === 'mina' ? $user->id : null),
            'goals' => $progress->for($monthStart, $canSet ? null : [$user->id]),
            'goalRows' => $canSet ? Goal::query()->where('month', $monthStart->toDateString())->with('user:id,name')->orderBy('user_id')->orderBy('metric')->get() : collect(),
            'members' => $canSet ? CompanyMembers::all($company) : collect(),
            'metrics' => GoalMetric::cases(),
            'monthStart' => $monthStart,
            'canSet' => $canSet,
            'currentMonth' => $now->setTimezone(Forecast::ZONE)->format('Y-m'),
            'money' => fn (int $ore): Money => Money::ofOre($ore),
        ]);
    }

    private function monthStart(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1) {
            return CarbonImmutable::parse($this->month.'-01', Forecast::ZONE);
        }

        return CarbonImmutable::now(Forecast::ZONE)->startOfMonth();
    }
}
