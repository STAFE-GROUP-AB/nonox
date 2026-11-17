@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $weekdays = [1 => __('Måndag'), 2 => __('Tisdag'), 3 => __('Onsdag'), 4 => __('Torsdag'), 5 => __('Fredag'), 6 => __('Lördag'), 7 => __('Söndag')];
    $short = [1 => __('mån'), 2 => __('tis'), 3 => __('ons'), 4 => __('tors'), 5 => __('fre'), 6 => __('lör'), 7 => __('sön')];
    $areaName = $areas->pluck('name', 'id');
    $skillName = $skills->pluck('name', 'id');
@endphp
<div class="grid gap-8 lg:grid-cols-3">
    <div class="space-y-4">
        @if($canManage && $editing === null)
            <x-button type="button" wire:click="create">{{ __('Ny uppgift') }}</x-button>
        @endif

        @if($editing !== null)
            <x-ui.card :title="$editing === 0 ? __('Ny uppgift') : __('Ändra uppgiften')">
                <form wire:submit="save" class="space-y-4">
                    <x-ui.field for="task-title" :label="__('Uppgift')" :hint="__('Skriv inga personuppgifter här, bara vad som ska göras.')" error="form.title">
                        <input id="task-title" wire:model="form.title" maxlength="120" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-ui.field for="task-instructions" :label="__('Instruktion')" error="form.instructions">
                        <textarea id="task-instructions" wire:model="form.instructions" rows="3" maxlength="500" class="{{ $input }}"></textarea>
                    </x-ui.field>
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">{{ __('Veckodagar') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                            @foreach($weekdays as $number => $label)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" value="{{ $number }}" wire:model="form.weekdays" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('form.weekdays')<p class="mt-1 text-sm text-red-800">{{ $message }}</p>@enderror
                    </fieldset>
                    <x-ui.field for="task-area" :label="__('Avdelning')" :hint="__('Tom betyder alla avdelningar.')" error="form.area_id">
                        <select id="task-area" wire:model="form.area_id" class="{{ $input }}">
                            <option value="">{{ __('Alla') }}</option>
                            @foreach($areas as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}{{ $option->archived_at ? ' ('.__('arkiverad').')' : '' }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if($skills->isNotEmpty())
                        <x-ui.field for="task-skill" :label="__('Kompetens')" :hint="__('Tom betyder alla pass.')" error="form.skill_id">
                            <select id="task-skill" wire:model="form.skill_id" class="{{ $input }}">
                                <option value="">{{ __('Alla') }}</option>
                                @foreach($skills as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <x-ui.field for="task-time" :label="__('Klockslag')" :hint="__('Valfritt. Uppgiften läggs bara på pass som pågår då.')" error="form.due_time">
                        <input id="task-time" type="time" wire:model="form.due_time" class="{{ $input }} w-32 tabular-nums">
                    </x-ui.field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field for="task-from" :label="__('Gäller från')" error="form.valid_from">
                            <input id="task-from" type="date" wire:model="form.valid_from" class="{{ $input }}">
                        </x-ui.field>
                        <x-ui.field for="task-to" :label="__('Gäller till')" error="form.valid_to">
                            <input id="task-to" type="date" wire:model="form.valid_to" class="{{ $input }}">
                        </x-ui.field>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="form.is_active" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                        {{ __('Aktiv') }}
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <x-button type="submit">{{ __('Spara') }}</x-button>
                        <x-secondary-button type="button" wire:click="$set('editing', null)">{{ __('Avbryt') }}</x-secondary-button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('Uppgifter som följer med passen')" :description="__('Uppgifterna läggs på passen när ett pass sparas och när schemat publiceras. Personalen bockar av dem på Min sida.')">
            <ul class="divide-y divide-stone-100" data-task-templates>
                @forelse($templates as $template)
                    <li wire:key="task-template-{{ $template->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3">
                        <div class="min-w-0 flex-1 basis-60">
                            <p @class(['font-semibold text-ink', 'text-gray-400 line-through' => ! $template->is_active])>{{ $template->title }}</p>
                            @if($template->instructions)<p class="text-sm text-gray-600">{{ $template->instructions }}</p>@endif
                            <p class="mt-1 text-xs text-gray-500">
                                {{ collect($template->weekdays)->map(fn ($day) => $short[(int) $day])->join(', ') }}
                                @if($template->due_time) · {{ __('kl. :time', ['time' => $template->due_time]) }}@endif
                                · {{ $template->area_id ? ($areaName[$template->area_id] ?? __('Avdelning')) : __('Alla avdelningar') }}
                                @if($template->skill_id) · {{ $skillName[$template->skill_id] ?? __('Kompetens') }}@endif
                                @if($template->valid_from || $template->valid_to) · {{ trim(($template->valid_from?->toDateString() ?? '').' – '.($template->valid_to?->toDateString() ?? '')) }}@endif
                                @unless($template->is_active) · {{ __('Inaktiv') }}@endunless
                            </p>
                        </div>
                        @if($canManage)
                            <span class="flex gap-3 text-sm font-semibold">
                                <button type="button" wire:click="edit({{ $template->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                                <button type="button" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('Ta bort uppgiften? Den tas också bort från pass som inte har börjat, utom de där något redan är avbockat.') }}" class="text-red-800 underline">{{ __('Ta bort') }}</button>
                            </span>
                        @endif
                    </li>
                @empty
                    <li class="py-2"><x-ui.empty-state :title="__('Inga uppgifter ännu')">{{ __('Skapa en uppgift här, till exempel "Kontrollera kylen" för alla pass i köket på morgonen.') }}</x-ui.empty-state></li>
                @endforelse
            </ul>
        </x-ui.card>
        <p class="text-xs text-gray-500">{{ __('Uppgifterna är ett stöd i arbetet. De är inte egenkontroll enligt livsmedelslagstiftningen och inte en del av personalliggaren.') }}</p>
    </div>
</div>
