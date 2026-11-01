@php
    $input = 'mt-1 block w-full rounded-xl border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $done = $task->checklistItems->whereNotNull('done_at')->count();
@endphp
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card>
            @if($editing)
                <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="edit_title" :label="__('Vad ska göras?')" error="title" class="sm:col-span-2">
                        <input id="edit_title" type="text" maxlength="200" class="{{ $input }}" wire:model="title" required>
                    </x-ui.field>
                    <x-ui.field for="edit_assignee" :label="__('Ansvarig')" error="assignee_id">
                        <select id="edit_assignee" class="{{ $input }}" wire:model="assigneeId">
                            <option value="">{{ __('Ingen ännu') }}</option>
                            @foreach($members as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="edit_due" :label="__('Sista dag')" error="due_date">
                        <input id="edit_due" type="date" class="{{ $input }}" wire:model="dueDate">
                    </x-ui.field>
                    <x-ui.field for="edit_type" :label="__('Typ')" error="type">
                        <select id="edit_type" class="{{ $input }}" wire:model="type">
                            @foreach($types as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="edit_priority" :label="__('Prioritet')" error="priority">
                        <select id="edit_priority" class="{{ $input }}" wire:model="priority">
                            @foreach($priorities as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="edit_subject_type" :label="__('Koppla till')" error="subject_type">
                        <select id="edit_subject_type" class="{{ $input }}" wire:model.live="subjectType">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($linkTypes as $option)
                                <option value="{{ $option }}">{{ App\Domain\Tasks\TaskLinks::typeLabel($option) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="edit_subject" :label="__('Post')" error="subject">
                        <select id="edit_subject" class="{{ $input }}" wire:model="subjectId" @disabled($subjectType === '')>
                            <option value="">{{ __('Välj') }}</option>
                            @foreach($linkOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="edit_description" :label="__('Beskrivning')" error="description" class="sm:col-span-2">
                        <textarea id="edit_description" rows="4" maxlength="5000" class="{{ $input }}" wire:model="description"></textarea>
                    </x-ui.field>
                    <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                        <x-button>{{ __('Spara uppgiften') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('editing', false)">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            @else
                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <span>{{ $task->type->label() }}</span>
                    @include('tasks.status-chip', ['status' => $task->status])
                    <span>{{ __('Prioritet: :priority', ['priority' => $task->priority->label()]) }}</span>
                </div>
                <h2 class="mt-2 text-xl font-bold tracking-tight text-ink">{{ $task->title }}</h2>
                @if($task->description)
                    <p class="mt-3 whitespace-pre-line text-gray-700">{{ $task->description }}</p>
                @endif
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">{{ __('Ansvarig') }}</dt><dd class="font-medium text-ink">{{ $task->assignee?->name ?? __('Ingen ännu') }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Sista dag') }}</dt><dd @class(['font-medium tabular-nums', 'text-accent-700' => $task->isOverdue(), 'text-ink' => ! $task->isOverdue()])>{{ $task->due_date?->toDateString() ?? '–' }}@if($task->isOverdue()) · {{ __('försenad') }}@endif</dd></div>
                    <div><dt class="text-gray-500">{{ __('Skapad av') }}</dt><dd class="text-ink">{{ $task->creator?->name }} · <span class="tabular-nums">{{ $task->created_at->toDateString() }}</span></dd></div>
                    @if($linkLabel)
                        <div><dt class="text-gray-500">{{ __('Kopplad till') }}</dt><dd><a href="{{ $linkUrl }}" class="font-medium text-accent-700 hover:text-accent-800">{{ $linkLabel }}</a></dd></div>
                    @endif
                    @if($task->completed_at)
                        <div><dt class="text-gray-500">{{ __('Klar') }}</dt><dd class="tabular-nums text-ink">{{ $task->completed_at->toDateString() }}</dd></div>
                    @endif
                </dl>
                @if($canManage)
                    <div class="mt-5 flex flex-wrap gap-4">
                        <button type="button" wire:click="edit" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ändra uppgiften') }}</button>
                        @if($confirmingDelete)
                            <span class="flex flex-wrap items-center gap-3 text-sm">
                                <span class="text-gray-700">{{ __('Uppgiften, dess checklista och kommentarer tas bort för alla.') }}</span>
                                <button type="button" wire:click="delete" class="font-semibold text-accent-700">{{ __('Ja, ta bort') }}</button>
                                <button type="button" wire:click="$set('confirmingDelete', false)" class="text-gray-600 underline">{{ __('Avbryt') }}</button>
                            </span>
                        @else
                            <button type="button" wire:click="$set('confirmingDelete', true)" class="text-sm text-gray-600 hover:text-accent-700">{{ __('Ta bort') }}</button>
                        @endif
                    </div>
                @endif
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Checklista')" :description="$task->checklistItems->isEmpty() ? null : __(':done av :total klara', ['done' => $done, 'total' => $task->checklistItems->count()])">
            @if($task->checklistItems->isNotEmpty())
                <ul class="space-y-2">
                    @foreach($task->checklistItems as $item)
                        <li wire:key="check-{{ $item->id }}" class="flex items-center gap-3">
                            <input id="check_{{ $item->id }}" type="checkbox" @checked($item->done_at !== null) @disabled(! $canWork) wire:click="toggleChecklistItem({{ $item->id }})" class="size-5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                            <label for="check_{{ $item->id }}" @class(['flex-1 text-sm', 'text-gray-500 line-through' => $item->done_at !== null, 'text-ink' => $item->done_at === null])>{{ $item->label }}</label>
                            @if($canManage)
                                <button type="button" wire:click="removeChecklistItem({{ $item->id }})" class="text-gray-400 hover:text-accent-700" aria-label="{{ __('Ta bort :label', ['label' => $item->label]) }}">×</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($canWork)
                <form wire:submit="addChecklistItem" class="mt-3 flex flex-wrap gap-2">
                    <label for="checklist_label" class="sr-only">{{ __('Ny punkt') }}</label>
                    <input id="checklist_label" type="text" maxlength="200" class="min-w-0 flex-1 rounded-xl border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model="checklistLabel" placeholder="{{ __('Lägg till en punkt') }}">
                    <button type="submit" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Lägg till') }}</button>
                </form>
                <x-input-error for="checklistLabel" class="mt-1" />
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Kommentarer')">
            @if($task->comments->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inga kommentarer ännu.') }}</p>
            @else
                <ol class="space-y-4">
                    @foreach($task->comments as $entry)
                        <li wire:key="comment-{{ $entry->id }}">
                            <p class="text-xs text-gray-500"><span class="font-semibold text-gray-700">{{ $entry->user?->name }}</span> · <span class="tabular-nums">{{ $entry->created_at->format('Y-m-d H:i') }}</span></p>
                            <p class="mt-1 whitespace-pre-line rounded-2xl bg-paper px-4 py-3 text-sm text-gray-800">{{ $entry->body }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            @if($canWork)
                <form wire:submit="addComment" class="mt-4 space-y-2">
                    <label for="task_comment" class="text-sm font-medium text-gray-700">{{ __('Skriv en kommentar') }}</label>
                    <textarea id="task_comment" rows="3" maxlength="5000" class="{{ $input }}" wire:model="comment" required></textarea>
                    <x-input-error for="comment" />
                    <x-button wire:loading.attr="disabled" wire:target="addComment">{{ __('Skicka kommentaren') }}</x-button>
                </form>
            @endif
        </x-ui.card>
    </div>

    <aside class="space-y-4">
        <x-ui.card :title="__('Status')">
            @if($canWork)
                <div class="grid gap-2">
                    @foreach($statuses as $option)
                        <button type="button" wire:click="setStatus('{{ $option->value }}')" @class([
                            'rounded-xl px-4 py-2.5 text-left text-sm font-semibold ring-1 transition',
                            'bg-ink text-white ring-ink' => $task->status === $option,
                            'bg-white text-gray-800 ring-stone-200 hover:ring-stone-300' => $task->status !== $option,
                        ]) @if($task->status === $option) aria-current="true" @endif>{{ $option->label() }}</button>
                    @endforeach
                </div>
            @else
                @include('tasks.status-chip', ['status' => $task->status])
                <p class="mt-2 text-sm text-gray-600">{{ __('Du kan läsa uppgiften men inte ändra den.') }}</p>
            @endif
        </x-ui.card>
        <a href="{{ route('tasks.index') }}" class="block text-sm font-semibold text-accent-700 hover:text-accent-800">← {{ __('Alla uppgifter i företaget') }}</a>
    </aside>
</div>
