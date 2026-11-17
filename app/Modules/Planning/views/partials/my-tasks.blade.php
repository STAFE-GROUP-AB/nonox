{{--
    The tasks on one of my shifts, ticked off in Min sida (ADR-037 gap 4). A
    task can be ticked from the shift's day to the day after it ends, and
    unticked only on the day it was ticked. Shows when it was done, never who.
--}}
@php
    $tasks = $tasksByShift->get($shift->id, collect());
    $open = ! $shift->cancelled && $now->greaterThanOrEqualTo($shift->start->startOfDay()) && $now->lessThanOrEqualTo($shift->end->addDay()->endOfDay());
    $doneCount = $tasks->filter(fn ($task) => $task->done_at !== null)->count();
@endphp
@if($tasks->isNotEmpty() && ! $shift->cancelled)
    <div class="basis-full" data-shift-tasks="{{ $shift->id }}">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Uppgifter') }} · <span class="tabular-nums">{{ $doneCount }}/{{ $tasks->count() }}</span></p>
        <ul class="mt-1 space-y-1">
            @foreach($tasks as $task)
                @php($locked = ! $open || ($task->done_at !== null && $task->done_at->toDateString() !== $now->toDateString()))
                <li wire:key="my-task-{{ $task->id }}">
                    <label @class(['flex items-start gap-3 rounded-lg px-2 py-2 text-sm', 'hover:bg-gray-50' => ! $locked])>
                        <input type="checkbox" class="mt-0.5 size-5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500 disabled:opacity-50" wire:click="toggleTask({{ $task->id }})" @checked($task->done_at !== null) @disabled($locked)>
                        <span class="min-w-0 flex-1">
                            <span @class(['block text-gray-900', 'text-gray-500 line-through' => $task->done_at !== null])>{{ $task->title }}@if($task->due_time) <span class="tabular-nums text-gray-500">({{ __('kl. :time', ['time' => $task->due_time]) }})</span>@endif</span>
                            @if($task->instructions)<span class="block text-xs text-gray-500">{{ $task->instructions }}</span>@endif
                            @if($task->done_at)<span class="block text-xs text-green-800">{{ __('Klar :time', ['time' => $task->done_at->format('H:i')]) }}</span>@endif
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    </div>
@endif
