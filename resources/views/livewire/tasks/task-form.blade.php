@php
    $input = 'mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500';
@endphp
<div>
    @if($canCreate)
        {{-- The page's main call to action, first on the left (docs/design-system.md). --}}
        <x-button type="button" wire:click="start" wire:loading.attr="disabled" wire:target="start">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            {{ __('Ny uppgift') }}
        </x-button>

        @if($open)
            {{-- A panel from the right on wide screens, a sheet from the bottom on phones. --}}
            <div class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby="task_form_title" x-data x-on:keydown.escape.window="$wire.set('open', false)">
                <div class="absolute inset-0 bg-ink/40" wire:click="$set('open', false)"></div>
                <form wire:submit="save" class="absolute inset-x-0 bottom-0 flex max-h-[92vh] flex-col rounded-t-3xl bg-white shadow-2xl sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-[34rem] sm:rounded-none sm:rounded-l-3xl" style="padding-bottom: env(safe-area-inset-bottom)">
                    <header class="flex items-center justify-between gap-3 border-b border-stone-200 px-5 py-4 sm:px-6">
                        <h2 id="task_form_title" class="text-lg font-bold tracking-tight text-ink">{{ __('Ny uppgift') }}</h2>
                        <button type="button" class="rounded-lg p-2 text-gray-500 hover:bg-stone-100 hover:text-ink" wire:click="$set('open', false)" aria-label="{{ __('Stäng') }}">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </header>
                    <div class="grid flex-1 gap-4 overflow-y-auto px-5 py-5 sm:grid-cols-2 sm:px-6">
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
                            <input id="task_title" type="text" maxlength="200" class="{{ $input }}" wire:model="title" required autofocus placeholder="{{ __('Till exempel: Skicka kvitto för köpet den 12 oktober') }}">
                        </x-ui.field>
                        <x-ui.field for="task_assignee" :label="__('Vem gör det?')" error="assignee_id" class="sm:col-span-2" :hint="$people['firm'] !== [] ? __('Byrån är företagets redovisningskonsulter. Lämnar byrån en uppgift till kunden blir den Väntar på kund.') : null">
                            <select id="task_assignee" class="{{ $input }}" wire:model.live="assigneeId" @disabled($company === null)>
                                @include('tasks.assignee-options', ['people' => $people])
                            </select>
                        </x-ui.field>
                        <x-ui.field for="task_due" :label="__('Sista dag')" error="due_date">
                            <input id="task_due" type="date" class="{{ $input }}" wire:model="dueDate">
                        </x-ui.field>
                        <x-ui.field for="task_priority" :label="__('Prioritet')" error="priority">
                            <select id="task_priority" class="{{ $input }}" wire:model="priority">
                                @foreach($priorities as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="task_type" :label="__('Typ')" error="type">
                            <select id="task_type" class="{{ $input }}" wire:model="type">
                                @foreach($types as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-700">{{ __('Vem väntar vi på?') }}</legend>
                            <div class="mt-2 flex flex-col gap-2 text-sm text-gray-700">
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
                    <footer class="flex items-center gap-4 border-t border-stone-200 px-5 py-4 sm:px-6">
                        <x-button wire:loading.attr="disabled" wire:target="save">{{ __('Skapa uppgiften') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('open', false)">{{ __('Avbryt') }}</button>
                    </footer>
                </form>
            </div>
        @endif
    @endif
</div>
