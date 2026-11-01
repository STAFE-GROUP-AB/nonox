@php
    $input = 'mt-1 block w-full rounded-xl border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
@endphp
<div>
    @if($canCreate)
        @if(! $open)
            {{-- The page's main call to action, first on the left (docs/design-system.md). --}}
            <x-button type="button" wire:click="$set('open', true)">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ __('Ny uppgift') }}
            </x-button>
        @else
            <form wire:submit="save" class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6">
                <h2 class="text-lg font-bold tracking-tight text-ink">{{ __('Ny uppgift') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @if($fixedCompanyId === null)
                        <x-ui.field for="task_company" :label="__('Företag')" error="companyId" class="sm:col-span-2">
                            <select id="task_company" class="{{ $input }}" wire:model.live="companyId" required>
                                <option value="">{{ __('Välj företag') }}</option>
                                @foreach($companies as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <x-ui.field for="task_title" :label="__('Vad ska göras?')" error="title" class="sm:col-span-2">
                        <input id="task_title" type="text" maxlength="200" class="{{ $input }}" wire:model="title" required placeholder="{{ __('Till exempel: Skicka kvitto för köpet den 12 oktober') }}">
                    </x-ui.field>
                    <x-ui.field for="task_assignee" :label="__('Ansvarig')" error="assignee_id">
                        <select id="task_assignee" class="{{ $input }}" wire:model="assigneeId" @disabled($company === null)>
                            <option value="">{{ __('Ingen ännu') }}</option>
                            @foreach($members as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="task_due" :label="__('Sista dag')" error="due_date">
                        <input id="task_due" type="date" class="{{ $input }}" wire:model="dueDate">
                    </x-ui.field>
                    <x-ui.field for="task_type" :label="__('Typ')" error="type">
                        <select id="task_type" class="{{ $input }}" wire:model="type">
                            @foreach($types as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="task_priority" :label="__('Prioritet')" error="priority">
                        <select id="task_priority" class="{{ $input }}" wire:model="priority">
                            @foreach($priorities as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <fieldset class="sm:col-span-2">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Vem väntar vi på?') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-700">
                            <label class="flex items-center gap-2"><input type="radio" value="TODO" wire:model="status" class="border-gray-300 text-accent-600 focus:ring-accent-500"> {{ __('Oss – att göra') }}</label>
                            <label class="flex items-center gap-2"><input type="radio" value="WAITING_FOR_CLIENT" wire:model="status" class="border-gray-300 text-accent-600 focus:ring-accent-500"> {{ __('Kunden – väntar på kund') }}</label>
                        </div>
                    </fieldset>
                    <x-ui.field for="task_description" :label="__('Beskrivning (valfri)')" error="description" class="sm:col-span-2">
                        <textarea id="task_description" rows="3" maxlength="5000" class="{{ $input }}" wire:model="description"></textarea>
                    </x-ui.field>
                    <x-ui.field for="task_checklist" :label="__('Checklista (valfri, en punkt per rad)')" error="checklist" class="sm:col-span-2">
                        <textarea id="task_checklist" rows="3" class="{{ $input }}" wire:model="checklist" placeholder="{{ __('Stäm av bankkontot') . "\n" . __('Bokför leverantörsfakturorna') }}"></textarea>
                    </x-ui.field>
                </div>
                <div class="mt-5 flex flex-wrap items-center gap-4">
                    <x-button wire:loading.attr="disabled" wire:target="save">{{ __('Skapa uppgiften') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('open', false)">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @endif
    @endif
</div>
