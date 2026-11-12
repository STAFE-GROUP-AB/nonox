<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Planning;

use App\Modules\Manufacturing\Livewire\Concerns\PlanningScreen;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanBoard;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Tillverkning → Planering → Resurser (ADR-071 §13.1): one row per person
 * and per workstation, per day the working time, the booked work and the
 * load. Absence is shown only as "Frånvarande", whatever its kind (§13.7).
 */
class ResourcesPage extends Component
{
    use PlanningScreen;

    public const int DAYS = 14;

    #[Url(as: 'fran')]
    public string $from = '';

    public function shift(int $days): void
    {
        $this->from = $this->start()->addDays($days)->toDateString();
    }

    private function start(): CarbonImmutable
    {
        try {
            $start = $this->from === '' ? CarbonImmutable::now(Clock::ZONE) : CarbonImmutable::parse($this->from, Clock::ZONE);
        } catch (Throwable) {
            $start = CarbonImmutable::now(Clock::ZONE);
        }

        return $start->startOfWeek()->startOfDay();
    }

    public function render(PlanBoard $board): View
    {
        $run = $this->run();

        return view('manufacturing::livewire.planning.resources', [
            'run' => $run,
            'canPlan' => $this->can('plan'),
            'load' => $run === null ? null : $board->load($this->company(), $run, $this->start(), self::DAYS),
        ]);
    }
}
