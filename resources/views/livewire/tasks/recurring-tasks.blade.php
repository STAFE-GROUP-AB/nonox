@php
    $input = 'mt-1 block w-full rounded-xl border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
@endphp
<div class="grid gap-6 lg:grid-cols-5">
    <form wire:submit="save" class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6 lg:col-span-2">
        <h2 class="text-lg font-bold tracking-tight text-ink">{{ __('Ny återkommande uppgift') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('Uppgiften skapas en gång per period, med sista dag i månaden efter perioden. Du väljer dagen; kontrollera Skatteverkets datum för just ditt företag.') }}</p>
        <div class="mt-4 grid gap-4">
            <x-ui.field for="rec_title" :label="__('Rubrik')" error="title">
                <input id="rec_title" type="text" maxlength="200" class="{{ $input }}" wire:model="title" required placeholder="{{ __('Till exempel: Moms') }}">
            </x-ui.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="rec_frequency" :label="__('Hur ofta')" error="frequency">
                    <select id="rec_frequency" class="{{ $input }}" wire:model="frequency">
                        @foreach($frequencies as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="rec_day" :label="__('Sista dag (dag i månaden)')" error="due_day">
                    <input id="rec_day" type="number" min="1" max="31" class="{{ $input }}" wire:model="dueDay" required>
                </x-ui.field>
                <x-ui.field for="rec_type" :label="__('Typ')" error="type">
                    <select id="rec_type" class="{{ $input }}" wire:model="type">
                        @foreach($types as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="rec_assignee" :label="__('Ansvarig')" error="assignee_id">
                    <select id="rec_assignee" class="{{ $input }}" wire:model="assigneeId">
                        <option value="">{{ __('Ingen ännu') }}</option>
                        @foreach($members as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            </div>
            <x-ui.field for="rec_description" :label="__('Beskrivning (valfri)')" error="description">
                <textarea id="rec_description" rows="3" maxlength="5000" class="{{ $input }}" wire:model="description"></textarea>
            </x-ui.field>
        </div>
        <x-button class="mt-5" wire:loading.attr="disabled" wire:target="save">{{ __('Spara') }}</x-button>
    </form>

    <div class="lg:col-span-3">
        @if($recurrences->isEmpty())
            <x-ui.card>
                <x-ui.empty-state :title="__('Inga återkommande uppgifter')">{{ __('Lägg upp det som kommer tillbaka, som moms varje månad eller arbetsgivardeklarationen.') }}</x-ui.empty-state>
            </x-ui.card>
        @else
            <ul class="space-y-3">
                @foreach($recurrences as $recurrence)
                    <li wire:key="recurrence-{{ $recurrence->id }}" class="flex flex-col gap-3 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $recurrence->title }}
                                @unless($recurrence->active)<x-ui.badge class="ms-2">{{ __('Stoppad') }}</x-ui.badge>@endunless
                            </p>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ $recurrence->frequency->label() }} · {{ __('sista dag den :day', ['day' => $recurrence->due_day]) }} · {{ $recurrence->type->label() }}
                                · {{ $recurrence->assignee ? $recurrence->assignee->name : __('Ingen ansvarig') }}
                            </p>
                        </div>
                        @if($recurrence->active)
                            <button type="button" wire:click="stop({{ $recurrence->id }})" wire:confirm="{{ __('Stoppa den återkommande uppgiften? Redan skapade uppgifter finns kvar.') }}" class="shrink-0 text-sm font-semibold text-gray-600 hover:text-accent-700">{{ __('Stoppa') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
