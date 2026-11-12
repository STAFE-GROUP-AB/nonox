<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Concerns;

use App\Modules\Manufacturing\Actions\Planning\RecomputePlan;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Planning\PlanBoard;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What every planning screen shares (ADR-071 §13.1, §13.4): it needs
 * manufacturing:view, shows the latest run, and polls the cheap "latest run
 * id" every 15 seconds while visible, rendering again only when a new run
 * exists. A planner can compute the plan at once.
 *
 * @mixin Component
 */
trait PlanningScreen
{
    use ManufacturingPage;

    #[Locked]
    public ?int $runId = null;

    public function mountPlanningScreen(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->runId = app(PlanBoard::class)->latestRunId($this->company());
    }

    /** Polled: renders again only when the plan has changed. */
    public function checkRun(PlanBoard $board): void
    {
        $latest = $board->latestRunId($this->company());

        if ($latest === $this->runId) {
            $this->skipRender();

            return;
        }

        $this->runId = $latest;
    }

    public function recompute(RecomputePlan $recompute): void
    {
        abort_unless($this->can('plan'), 403);

        $this->attempt(function () use ($recompute): void {
            $this->runId = $recompute($this->user(), $this->company())->id;
        }, __('Planen är beräknad.'));
    }

    protected function run(): ?ProductionPlanRun
    {
        return $this->runId === null ? null : ProductionPlanRun::query()->find($this->runId);
    }

    /** Minutes as hours with one decimal: 90 → "1,5 h". */
    public static function hours(int $minutes): string
    {
        return str_replace('.', ',', (string) round($minutes / 60, 1)).' h';
    }
}
