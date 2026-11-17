<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftTask;
use App\Modules\Planning\Models\TaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Puts the recurring tasks on shifts (ADR-037 gap 4). For a set of shifts
 * it makes each shift's template tasks match the active templates: tasks
 * that are missing are added, tasks whose template no longer matches the
 * shift (or is switched off or removed) are taken away, and the text of the
 * rest follows the template. Tasks written for one shift (no template) are
 * never touched, and a shift where a task has been ticked is left as it is.
 * Only shifts that are not published yet, or that start in the future, are
 * touched: what has happened stays as it was. Three queries and at most
 * three writes, whatever the number of shifts.
 */
final class ShiftTasks
{
    /**
     * @param  iterable<Shift>  $shifts
     * @return int tasks added
     */
    public function sync(Company $company, iterable $shifts): int
    {
        $now = CarbonImmutable::now();
        $eligible = collect($shifts)->filter(fn (Shift $shift): bool => $shift->status !== Shift::CANCELLED
            && ($shift->published_at === null || $shift->starts_at->greaterThan($now)))->values();

        if ($eligible->isEmpty()) {
            return 0;
        }

        /** @var Collection<int, TaskTemplate> $templates */
        $templates = TaskTemplate::query()->get()->keyBy('id');
        $existing = ShiftTask::query()->whereIn('shift_id', $eligible->pluck('id')->all())->get()->groupBy('shift_id');
        $insert = [];
        $remove = [];
        $stamp = $now->utc()->format('Y-m-d H:i:sP');

        foreach ($eligible as $shift) {
            /** @var Collection<int, ShiftTask> $tasks */
            $tasks = $existing->get($shift->id, collect());

            if ($tasks->contains(fn (ShiftTask $task): bool => $task->isDone())) {
                continue;
            }

            $wanted = $templates
                ->filter(fn (TaskTemplate $template): bool => $template->matches($shift->area_id, $shift->skill_id, $shift->starts_at, $shift->ends_at))
                ->sortBy(fn (TaskTemplate $template): string => ($template->due_time ?? '99:99').sprintf('%08d', $template->id))
                ->values();
            $fromTemplate = $tasks->whereNotNull('template_id')->keyBy('template_id');
            $position = (int) ($tasks->max('position') ?? -1);

            foreach ($fromTemplate as $templateId => $task) {
                if (! $wanted->contains('id', $templateId)) {
                    $remove[] = $task->id;
                }
            }

            foreach ($wanted as $template) {
                $task = $fromTemplate->get($template->id);

                if ($task instanceof ShiftTask) {
                    if ($task->title !== $template->title || $task->instructions !== $template->instructions || $task->due_time !== $template->due_time) {
                        $task->forceFill(['title' => $template->title, 'instructions' => $template->instructions, 'due_time' => $template->due_time])->save();
                    }

                    continue;
                }

                $insert[] = [
                    'company_id' => $company->id,
                    'shift_id' => $shift->id,
                    'template_id' => $template->id,
                    'title' => $template->title,
                    'instructions' => $template->instructions,
                    'due_time' => $template->due_time,
                    'position' => ++$position,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        if ($remove !== []) {
            ShiftTask::query()->whereKey($remove)->delete();
        }

        if ($insert !== []) {
            ShiftTask::query()->insert($insert);
        }

        return count($insert);
    }

    /**
     * Applies the templates to every shift that has not started (or is not
     * published yet): after a template was added, changed or removed.
     */
    public function syncUpcoming(Company $company): int
    {
        $from = CarbonImmutable::now(LocalTimestamp::ZONE)->startOfDay();
        $shifts = Shift::query()
            ->where('status', '<>', Shift::CANCELLED)
            ->where('starts_at', '>=', ShiftQuery::instant($from))
            ->get();

        return $this->sync($company, $shifts);
    }
}
