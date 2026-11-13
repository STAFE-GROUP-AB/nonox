<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Planning;

use App\Modules\Manufacturing\Livewire\Concerns\PlanningScreen;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanBoard;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Tillverkning → Planering → Kalender (ADR-071 §13.1): when the work on each
 * order is done (with any waiting time after it) and the forecast delivery
 * dates, in a month or a week, each order marked with its
 * traffic light.
 */
class CalendarPage extends Component
{
    use PlanningScreen;

    #[Url(as: 'vy')]
    public string $view = 'manad';

    #[Url(as: 'datum')]
    public string $date = '';

    public function show(string $view): void
    {
        $this->view = $view === 'vecka' ? 'vecka' : 'manad';
    }

    public function shift(int $steps): void
    {
        $base = $this->base();
        $this->date = ($this->view === 'vecka' ? $base->addWeeks($steps) : $base->addMonthsNoOverflow($steps))->toDateString();
    }

    private function base(): CarbonImmutable
    {
        try {
            return $this->date === '' ? CarbonImmutable::now(Clock::ZONE)->startOfDay() : CarbonImmutable::parse($this->date, Clock::ZONE)->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::now(Clock::ZONE)->startOfDay();
        }
    }

    public function render(PlanBoard $board): View
    {
        $run = $this->run();
        $base = $this->base();
        [$from, $to] = $this->view === 'vecka'
            ? [$base->startOfWeek(), $base->startOfWeek()->addDays(7)]
            : [$base->startOfMonth()->startOfWeek(), $base->endOfMonth()->endOfWeek()->addDay()->startOfDay()];

        $forecasts = $run === null ? collect() : $board->forecasts($this->company(), $run);
        $deliveries = $forecasts->filter(fn ($forecast): bool => $forecast->forecast_delivery_on !== null)->groupBy(fn ($forecast): string => $forecast->forecast_delivery_on->toDateString());
        // The day the work ends is the last slot's end; the finish can be later by a waiting time (drying, curing).
        $workEnds = $run === null ? collect() : ProductionPlanSlot::query()->where('run_id', $run->id)->groupBy('production_order_id')
            ->selectRaw('production_order_id, MAX(ends_at) AS ends_at')->pluck('ends_at', 'production_order_id')
            ->map(fn ($end): CarbonImmutable => CarbonImmutable::parse((string) $end)->setTimezone(Clock::ZONE));
        $finishes = $forecasts
            ->filter(fn ($forecast): bool => $forecast->finish_at !== null && $workEnds->has($forecast->production_order_id))
            ->map(fn ($forecast): array => ['forecast' => $forecast, 'work' => $workEnds->get($forecast->production_order_id), 'finish' => $forecast->finish_at->setTimezone(Clock::ZONE)])
            ->groupBy(fn (array $done): string => $done['work']->toDateString());

        $days = [];

        for ($day = $from; $day->lessThan($to); $day = $day->addDay()) {
            $days[] = $day;
        }

        return view('manufacturing::livewire.planning.calendar', [
            'run' => $run,
            'canPlan' => $this->can('plan'),
            'days' => $days,
            'base' => $base,
            'deliveries' => $deliveries,
            'finishes' => $finishes,
            'beyond' => $forecasts->filter(fn ($forecast): bool => $forecast->forecast_delivery_on === null),
        ]);
    }
}
