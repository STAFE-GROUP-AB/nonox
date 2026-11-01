@php
    $select = 'block w-full rounded-xl border-gray-300 py-2 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $views = array_filter([
        'mina' => [__('Till mig'), $counts['mina']],
        'alla' => $seesOthers ? [__('Alla klienter'), $counts['alla']] : null,
        'vantar' => [__('Väntar på kund'), $counts['vantar']],
        'lamnade' => [__('Lämnade till andra'), $counts['lamnade']],
        'klara' => [__('Klara'), null],
    ]);
    $initials = fn (string $name): string => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode(''));
    $due = function (?\Carbon\CarbonImmutable $date) use ($today): array {
        if ($date === null) {
            return ['', 'text-gray-400'];
        }
        $days = (int) $today->diffInDays($date, false);

        return match (true) {
            $days < 0 => [trans_choice('{1} :count dag sen|[2,*] :count dagar sen', -$days, ['count' => -$days]), 'font-semibold text-accent-700'],
            $days === 0 => [__('I dag'), 'font-semibold text-ink'],
            $days === 1 => [__('I morgon'), 'text-ink'],
            $days < 7 => [__('Om :count dagar', ['count' => $days]), 'text-ink'],
            default => [$date->toDateString(), 'text-gray-600'],
        };
    };
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            @if($canCreate)
                <livewire:tasks.task-form :key="'task-center-form'" />
            @endif
            @if($counts['forsenade'] > 0)
                <button type="button" wire:click="$set('deadline', 'forsenad')" class="inline-flex items-center gap-2 rounded-full bg-accent-50 px-3 py-1.5 text-sm font-semibold text-accent-800 ring-1 ring-accent-200 hover:ring-accent-300">
                    <span class="size-2 rounded-full bg-accent-600" aria-hidden="true"></span>
                    {{ trans_choice('{1} :count försenad|[2,*] :count försenade', $counts['forsenade'], ['count' => $counts['forsenade']]) }}
                </button>
            @endif
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <span class="hidden sm:inline">{{ __('E-post om mina uppgifter') }}</span>
            <span class="sr-only sm:hidden">{{ __('E-post om mina uppgifter') }}</span>
            <select class="rounded-xl border-gray-300 py-1.5 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model.live="digest">
                <option value="NONE">{{ __('Ingen e-post') }}</option>
                <option value="DAILY">{{ __('E-post varje vardag 06.45') }}</option>
            </select>
        </label>
    </div>

    <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0" aria-label="{{ __('Visa uppgifter') }}">
        @foreach($views as $key => [$label, $count])
            <button type="button" wire:click="$set('view', '{{ $key }}')" @class([
                'inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $view === $key,
                'bg-white text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300' => $view !== $key,
            ]) @if($view === $key) aria-current="true" @endif>
                {{ $label }}
                @if($count !== null)
                    <span @class(['rounded-full px-2 text-xs tabular-nums', 'bg-white/20' => $view === $key, 'bg-stone-100 text-gray-700' => $view !== $key])>{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    <section class="overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
        <div class="grid grid-cols-2 gap-2 border-b border-stone-200 p-3 sm:gap-3 sm:p-4 sm:px-5 lg:grid-cols-6">
            <div class="col-span-2">
                <label for="task_search" class="sr-only">{{ __('Sök uppgift') }}</label>
                <input id="task_search" type="search" class="{{ $select }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök uppgift') }}">
            </div>
            <div>
                <label for="task_filter_company" class="sr-only">{{ __('Företag') }}</label>
                <select id="task_filter_company" class="{{ $select }}" wire:model.live="company">
                    <option value="">{{ __('Alla företag') }}</option>
                    @foreach($names as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="task_filter_side" class="sr-only">{{ __('Hos') }}</label>
                <select id="task_filter_side" class="{{ $select }}" wire:model.live="side">
                    <option value="">{{ __('Hos alla') }}</option>
                    <option value="byran">{{ __('Hos byrån') }}</option>
                    <option value="kunden">{{ __('Hos kunden') }}</option>
                    <option value="ingen">{{ __('Ingen ansvarig') }}</option>
                </select>
            </div>
            <div>
                <label for="task_filter_deadline" class="sr-only">{{ __('Sista dag') }}</label>
                <select id="task_filter_deadline" class="{{ $select }}" wire:model.live="deadline">
                    <option value="">{{ __('När som helst') }}</option>
                    <option value="forsenad">{{ __('Försenade') }}</option>
                    <option value="vecka">{{ __('Denna vecka') }}</option>
                    <option value="manad">{{ __('Denna månad') }}</option>
                </select>
            </div>
            <div>
                <label for="task_filter_type" class="sr-only">{{ __('Typ') }}</label>
                <select id="task_filter_type" class="{{ $select }}" wire:model.live="type">
                    <option value="">{{ __('Alla typer') }}</option>
                    @foreach($types as $option)
                        <option value="{{ $option->slug() }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if($tasks->isEmpty())
            <div class="p-5">
                <x-ui.empty-state :title="$filtered ? __('Inget matchar filtren') : __('Inga uppgifter här')">
                    @if($filtered)
                        {{ __('Ändra eller rensa filtren för att se mer.') }}
                    @elseif($view === 'mina')
                        {{ __('När någon lämnar en uppgift till dig, i vilket av dina företag som helst, syns den här.') }}
                    @else
                        {{ __('Skapa en uppgift med knappen Ny uppgift och välj vem som ska göra den, på byrån eller hos kunden.') }}
                    @endif
                    <x-slot name="action">
                        @if($filtered)
                            <button type="button" wire:click="clearFilters" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Rensa filter') }}</button>
                        @endif
                    </x-slot>
                </x-ui.empty-state>
            </div>
        @else
            <div class="hidden grid-cols-[2.5rem_minmax(0,1fr)_11rem_12rem_7.5rem_8.5rem] items-center gap-3 border-b border-stone-200 bg-paper/60 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 lg:grid" aria-hidden="true">
                <span></span><span>{{ __('Uppgift') }}</span><span>{{ __('Företag') }}</span><span>{{ __('Hos') }}</span><span>{{ __('Sista dag') }}</span><span>{{ __('Status') }}</span>
            </div>
            <ul class="divide-y divide-stone-200">
                @foreach($tasks as $task)
                    @php
                        $canWork = in_array($task->company_id, $manageIds, true) || $task->assignee_id === auth()->id();
                        $side = $task->assignee_id === null ? null : ($sides["{$task->company_id}:{$task->assignee_id}"] ?? null);
                        [$dueLabel, $dueTone] = $due($task->status->isOpen() ? $task->due_date : null);
                        $done = $task->status === App\Domain\Tasks\TaskStatus::Done;
                    @endphp
                    <li wire:key="task-{{ $task->id }}" class="grid grid-cols-[2.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 px-4 py-3.5 hover:bg-paper/50 sm:px-5 lg:grid-cols-[2.5rem_minmax(0,1fr)_11rem_12rem_7.5rem_8.5rem] lg:items-center">
                        <div class="row-span-2 pt-0.5 lg:row-span-1 lg:pt-0">
                            @if($canWork)
                                <button type="button" wire:click="setStatus({{ $task->id }}, '{{ $done ? 'TODO' : 'DONE' }}')"
                                        @class([
                                            'flex size-7 items-center justify-center rounded-full ring-2 transition focus:outline-hidden focus-visible:ring-accent-500',
                                            'bg-green-600 text-white ring-green-600' => $done,
                                            'bg-white text-transparent ring-stone-300 hover:text-green-700 hover:ring-green-600' => ! $done,
                                        ])
                                        aria-label="{{ $done ? __('Öppna ”:title” igen', ['title' => $task->title]) : __('Markera ”:title” som klar', ['title' => $task->title]) }}">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                </button>
                            @else
                                <span class="block size-7 rounded-full ring-2 ring-stone-200" aria-hidden="true"></span>
                            @endif
                        </div>
                        <div class="min-w-0">
                            @include('firm.open-in-company', [
                                'companyId' => $task->company_id,
                                'taskId' => $task->id,
                                'label' => $task->title,
                                'class' => 'text-left font-semibold leading-snug hover:text-accent-700 '.($done ? 'text-gray-500 line-through decoration-stone-300' : 'text-ink'),
                            ])
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500">
                                <span>{{ $task->type->label() }}</span>
                                @if($task->priority === App\Domain\Tasks\TaskPriority::High)
                                    <span class="font-semibold text-accent-700">{{ __('Hög prioritet') }}</span>
                                @endif
                                @if($task->checklist_items_count > 0)
                                    <span class="tabular-nums">☑ {{ $task->checklist_done_count }}/{{ $task->checklist_items_count }}</span>
                                @endif
                                @if($task->comments_count > 0)
                                    <span class="tabular-nums">{{ trans_choice(':count kommentar|:count kommentarer', $task->comments_count, ['count' => $task->comments_count]) }}</span>
                                @endif
                                <span class="font-medium text-gray-700 lg:hidden">· {{ $names[$task->company_id] ?? '' }}</span>
                            </p>
                        </div>
                        <p class="hidden truncate text-sm text-gray-700 lg:block" title="{{ $names[$task->company_id] ?? '' }}">{{ $names[$task->company_id] ?? '' }}</p>
                        <div class="col-start-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 lg:contents">
                            <div class="flex min-w-0 items-center gap-2">
                                @if($task->assignee)
                                    <span @class([
                                        'flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold',
                                        'bg-ink text-white' => $side === App\Domain\Tasks\TaskPeople::FIRM,
                                        'bg-amber-100 text-amber-900' => $side !== App\Domain\Tasks\TaskPeople::FIRM,
                                    ]) aria-hidden="true">{{ $initials($task->assignee->name) }}</span>
                                    <span class="truncate text-sm text-gray-800">{{ $task->assignee->name }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ App\Domain\Tasks\TaskPeople::label((string) $side) }}</span>
                                @else
                                    <span class="text-sm text-gray-400">{{ __('Ingen ansvarig') }}</span>
                                @endif
                            </div>
                            <span class="text-sm tabular-nums {{ $dueTone }}" @if($task->due_date) title="{{ $task->due_date->toDateString() }}" @endif>{{ $dueLabel ?: ($done && $task->completed_at ? __('Klar :date', ['date' => $task->completed_at->toDateString()]) : '–') }}</span>
                            <span>@include('tasks.status-chip', ['status' => $task->status])</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if($tasks->hasPages())
                <div class="border-t border-stone-200 px-5 py-3">{{ $tasks->links() }}</div>
            @endif
        @endif
    </section>
</div>
