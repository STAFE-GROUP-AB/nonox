<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftTask;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A task written for one shift, or taken off it (ADR-037 gap 4). A task that
 * came from a template is changed in the template, not here. Audited with
 * ids only, never the text; the text must not hold personal data.
 */
final class SaveShiftTask
{
    use GuardsPlanning;

    public const int MAX_PER_SHIFT = 30;

    /** @param  array{title?: mixed, instructions?: mixed, due_time?: mixed}  $input */
    public function __invoke(User $user, Company $company, Shift $shift, array $input): ShiftTask
    {
        $this->guard($user, $company);

        if ($shift->status === Shift::CANCELLED) {
            throw ValidationException::withMessages(['title' => __('Passet är inställt och kan inte få fler uppgifter.')]);
        }

        $validated = Validator::make($input, [
            'title' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ], [], ['title' => __('Uppgift'), 'instructions' => __('Instruktion'), 'due_time' => __('Klockslag')])->validate();

        return DB::transaction(function () use ($user, $company, $shift, $validated): ShiftTask {
            $tasks = ShiftTask::query()->where('shift_id', $shift->id)->lockForUpdate()->get(['id', 'position']);

            if ($tasks->count() >= self::MAX_PER_SHIFT) {
                throw ValidationException::withMessages(['title' => __('Ett pass kan ha högst :max uppgifter.', ['max' => self::MAX_PER_SHIFT])]);
            }

            $task = ShiftTask::create([
                'shift_id' => $shift->id,
                'title' => trim((string) $validated['title']),
                'instructions' => ($validated['instructions'] ?? '') === '' ? null : $validated['instructions'],
                'due_time' => ($validated['due_time'] ?? '') === '' ? null : $validated['due_time'],
                'position' => (int) ($tasks->max('position') ?? -1) + 1,
            ]);

            Audit::record('planning.shift_task_added', $task, ['shift_id' => $shift->id], $company, $user);

            return $task;
        });
    }

    public function delete(User $user, Company $company, ShiftTask $task): void
    {
        $this->guard($user, $company);

        if ($task->template_id !== null) {
            throw ValidationException::withMessages(['task' => __('Uppgiften kommer från en mall. Ändra eller stäng av den under Planering → Uppgifter.')]);
        }

        DB::transaction(function () use ($user, $company, $task): void {
            Audit::record('planning.shift_task_removed', $task, ['shift_id' => $task->shift_id], $company, $user);
            $task->delete();
        });
    }
}
