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
 * Tillverkning → Planering (ADR-071 §13.1): the timeline. One row per
 * production order, its operations as bars over two weeks, the promised
 * date as a marker and the forecast where the bars end. A fixed number of
 * queries, whatever the number of orders.
 */
class TimelinePage extends Component
{
    use PlanningScreen;

    public const int DAYS = 14;

    #[Url(as: 'fran')]
    public string $from = '';

    public function shift(int $days): void
    {
        $this->from = $this->start()->addDays($days)->toDateString();
    }

    public function today(): void
    {
        $this->from = '';
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
        $start = $this->start();
        $end = $start->addDays(self::DAYS);
        $forecasts = $run === null ? collect() : $board->forecasts($this->company(), $run);
        $slots = $run === null ? collect() : $board->slots($this->company(), $run, $start, $end)->groupBy('production_order_id');
        $span = Clock::minute($end) - Clock::minute($start);
        $position = fn (CarbonImmutable $at): float => max(0, min(100, (Clock::minute($at) - Clock::minute($start)) / $span * 100));

        $days = [];

        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $days[] = $day;
        }

        return view('manufacturing::livewire.planning.timeline', [
            'run' => $run,
            'canPlan' => $this->can('plan'),
            'forecasts' => $forecasts,
            'bars' => $slots,
            'days' => $days,
            'start' => $start,
            'end' => $end,
            'position' => $position,
        ]);
    }
}
