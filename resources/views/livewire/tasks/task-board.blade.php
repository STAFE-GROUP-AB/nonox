@php
    $select = 'block w-full rounded-xl border-gray-300 py-2 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $tabs = ['oppna' => __('Öppna')];
    foreach ($statuses as $option) {
        $tabs[$option->slug()] = $option->label();
    }
    $tabs['alla'] = __('Alla');
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <livewire:tasks.task-form :company-id="$company->id" :key="'task-form-'.$company->id" />
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <a href="{{ route('tasks.mine') }}" class="font-semibold text-accent-700 hover:text-accent-800">{{ __('Mina uppgifter i alla företag') }}</a>
            @if($canManage)
                <a href="{{ route('tasks.recurring') }}" class="font-semibold text-accent-700 hover:text-accent-800">{{ __('Återkommande uppgifter') }}</a>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="{{ __('Visa uppgifter') }}">
            @foreach($tabs as $key => $label)
                <button type="button" wire:click="$set('show', '{{ $key }}')" @class([
                    'shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition',
                    'bg-ink text-white' => $show === $key,
                    'bg-stone-200/70 text-gray-800 hover:bg-stone-200' => $show !== $key,
                ]) @if($show === $key) aria-current="true" @endif>{{ $label }}</button>
            @endforeach
        </nav>
        @if($seesAll)
            <div class="w-full sm:w-64">
                <label for="board_assignee" class="text-xs font-medium text-gray-500">{{ __('Ansvarig') }}</label>
                <select id="board_assignee" class="{{ $select }} mt-1" wire:model.live="assignee">
                    <option value="">{{ __('Alla') }}</option>
                    <option value="ingen">{{ __('Ingen ansvarig') }}</option>
                    @foreach($members as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @if(! $seesAll)
        <p class="text-sm text-gray-600">{{ __('Här ser du de uppgifter som har lämnats till dig i :company.', ['company' => $company->name]) }}</p>
    @endif

    @if($tasks->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga uppgifter här')">
                {{ $canManage ? __('Skapa en uppgift med knappen Ny uppgift, eller lägg upp en återkommande som Moms varje månad.') : __('När någon lämnar en uppgift till dig syns den här.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <ul class="space-y-3">
            @foreach($tasks as $task)
                @php($canWork = $canManage || $task->assignee_id === auth()->id())
                <li wire:key="task-{{ $task->id }}" class="rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200 sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span>{{ $task->type->label() }}</span>
                                @include('tasks.status-chip', ['status' => $task->status])
                                @if($task->priority === App\Domain\Tasks\TaskPriority::High)
                                    <span class="rounded-full bg-accent-600 px-2 py-0.5 font-semibold text-white">{{ __('Hög prioritet') }}</span>
                                @endif
                            </p>
                            <a href="{{ route('tasks.show', $task) }}" class="mt-1 block font-semibold text-ink hover:text-accent-700">{{ $task->title }}</a>
                            <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-gray-600">
                                @if($task->due_date)
                                    <span @class(['tabular-nums', 'font-semibold text-accent-700' => $task->isOverdue()])>{{ $task->isOverdue() ? __('Försenad, sista dag var :date', ['date' => $task->due_date->toDateString()]) : __('Sista dag :date', ['date' => $task->due_date->toDateString()]) }}</span>
                                @endif
                                <span>{{ $task->assignee ? __('Ansvarig: :name', ['name' => $task->assignee->name]) : __('Ingen ansvarig') }}</span>
                                @if($task->checklist_items_count > 0)
                                    <span class="tabular-nums">{{ __('Checklista :done/:total', ['done' => $task->checklist_done_count, 'total' => $task->checklist_items_count]) }}</span>
                                @endif
                                @if($task->comments_count > 0)
                                    <span class="tabular-nums">{{ trans_choice(':count kommentar|:count kommentarer', $task->comments_count, ['count' => $task->comments_count]) }}</span>
                                @endif
                            </p>
                        </div>
                        @if($canWork && $task->status->isOpen())
                            <div class="flex shrink-0 flex-wrap gap-2">
                                @if($task->status !== App\Domain\Tasks\TaskStatus::InProgress)
                                    <button type="button" wire:click="setStatus({{ $task->id }}, 'IN_PROGRESS')" class="rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Påbörja') }}</button>
                                @endif
                                <button type="button" wire:click="setStatus({{ $task->id }}, 'DONE')" class="rounded-xl bg-ink px-3 py-1.5 text-sm font-semibold text-white hover:bg-black">{{ __('Markera som klar') }}</button>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        {{ $tasks->links() }}
    @endif
</div>
