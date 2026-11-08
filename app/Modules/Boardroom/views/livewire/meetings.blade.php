@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    @if($canManage && ! $planning)
        <div class="flex flex-wrap gap-3">
            <x-button type="button" wire:click="plan('AGM')">{{ __('Planera årsstämma') }}</x-button>
            <x-secondary-button type="button" wire:click="plan('EXTRA_GM')">{{ __('Extra bolagsstämma') }}</x-secondary-button>
            <x-secondary-button type="button" wire:click="plan('BOARD')">{{ __('Styrelsemöte') }}</x-secondary-button>
        </div>
    @endif

    @if($planning)
        <x-ui.card :title="__('Nytt möte: :kind', ['kind' => \App\Modules\Boardroom\Domain\MeetingKind::from($form['kind'])->label()])">
            <form wire:submit="save" class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-ui.field for="meeting-title" :label="__('Rubrik')" error="form.title" class="sm:col-span-2">
                    <input id="meeting-title" wire:model="form.title" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="meeting-date" :label="__('Datum')" error="form.meeting_date">
                    <input id="meeting-date" type="date" wire:model="form.meeting_date" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="meeting-time" :label="__('Klockan')" error="form.start_time">
                    <input id="meeting-time" type="time" wire:model="form.start_time" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="meeting-place" :label="__('Plats')" error="form.place" class="sm:col-span-2">
                    <input id="meeting-place" wire:model="form.place" class="{{ $input }}">
                </x-ui.field>
                <label class="flex items-center gap-3 sm:col-span-2"><x-checkbox wire:model="form.digital" /> <span>{{ __('Det går att delta digitalt') }}</span></label>
                @if($form['kind'] === 'AGM')
                    <x-ui.field for="meeting-year" :label="__('Räkenskapsår')" error="form.fiscal_year_id" class="sm:col-span-2" :hint="__('Dagordningen fylls i från bokslutet för året.')">
                        <select id="meeting-year" wire:model="form.fiscal_year_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj') }}</option>
                            @foreach($years as $year)
                                <option value="{{ $year->id }}">{{ $year->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Planera mötet') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('planning', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($upcoming->isEmpty() && $held->isEmpty())
        @unless($planning)
            <x-ui.empty-state :title="__('Inga möten ännu')">{{ __('Planera årsstämman, så fyller NONOX i dagordningen från bokslutet och föreslår när kallelsen ska skickas.') }}</x-ui.empty-state>
        @endunless
    @else
        @foreach([__('Kommande') => $upcoming, __('Hållna') => $held] as $heading => $list)
            @if($list->isNotEmpty())
                <x-ui.card :title="$heading">
                    <ul class="mt-4 divide-y divide-stone-200">
                        @foreach($list as $meeting)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <a href="{{ route('boardroom.meetings.show', $meeting) }}" class="font-semibold text-accent-700 underline">{{ $meeting->title }}</a>
                                    <p class="text-gray-600">{{ $meeting->kind->label() }} · {{ $meeting->meeting_date->toDateString() }}{{ $meeting->place ? ' · '.$meeting->place : '' }}</p>
                                </div>
                                <x-ui.badge :tone="($approved[$meeting->id] ?? false) ? 'success' : 'neutral'">{{ ($approved[$meeting->id] ?? false) ? __('Protokoll godkänt i NONOX') : __('Inget godkänt protokoll') }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        @endforeach
    @endif
</div>
