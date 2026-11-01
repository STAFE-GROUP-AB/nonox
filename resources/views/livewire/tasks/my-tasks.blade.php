<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        @if($canCreate)
            <livewire:tasks.task-form />
        @endif
        <div class="flex items-center gap-2 text-sm">
            <label for="task_digest" class="text-gray-600">{{ __('Sammanfattning med e-post') }}</label>
            <select id="task_digest" class="rounded-xl border-gray-300 py-2 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model.live="digest">
                <option value="NONE">{{ __('Av') }}</option>
                <option value="DAILY">{{ __('Varje vardag kl. 06.45') }}</option>
            </select>
        </div>
    </div>

    <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="{{ __('Visa uppgifter') }}">
        @foreach(['tilldelade' => __('Till mig'), 'skapade' => __('Lämnade till andra'), 'klara' => __('Klara senaste 30 dagarna')] as $key => $label)
            <button type="button" wire:click="$set('show', '{{ $key }}')" @class([
                'shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $show === $key,
                'bg-stone-200/70 text-gray-800 hover:bg-stone-200' => $show !== $key,
            ]) @if($show === $key) aria-current="true" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if($tasks->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga uppgifter här')">{{ __('När någon lämnar en uppgift till dig, i vilket av dina företag som helst, syns den här.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <ul class="space-y-3">
            @foreach($tasks as $task)
                <li wire:key="mine-{{ $task->id }}" class="flex flex-col gap-3 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="font-semibold uppercase tracking-wide text-gray-700">{{ $names[$task->company_id] ?? '' }}</span>
                            <span>{{ $task->type->label() }}</span>
                            @include('tasks.status-chip', ['status' => $task->status])
                        </p>
                        <p class="mt-1 font-semibold text-ink">{{ $task->title }}</p>
                        <p class="mt-1 flex flex-wrap gap-x-3 text-sm text-gray-600">
                            @if($task->due_date)
                                <span @class(['tabular-nums', 'font-semibold text-accent-700' => $task->isOverdue()])>{{ $task->isOverdue() ? __('Försenad, sista dag var :date', ['date' => $task->due_date->toDateString()]) : __('Sista dag :date', ['date' => $task->due_date->toDateString()]) }}</span>
                            @endif
                            @if($show !== 'tilldelade')
                                <span>{{ $task->assignee ? __('Ansvarig: :name', ['name' => $task->assignee->name]) : __('Ingen ansvarig') }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="shrink-0">
                        @include('firm.open-in-company', [
                            'companyId' => $task->company_id,
                            'taskId' => $task->id,
                            'label' => __('Öppna uppgiften'),
                            'class' => 'w-full rounded-xl bg-paper px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-stone-200 hover:ring-stone-300 sm:w-auto',
                        ])
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
