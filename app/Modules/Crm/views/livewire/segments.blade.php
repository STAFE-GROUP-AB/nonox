@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    @if($open)
        <x-ui.card :title="$editingId ? __('Ändra segment') : __('Nytt segment')" :description="__('Ett segment är personerna på de kunder och prospekt som matchar filtren. Ett tomt filter tar med alla.')">
            <form wire:submit="save" class="mt-4 grid gap-5 lg:grid-cols-3">
                <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
                    <x-ui.field for="segment_name" :label="__('Namn')" error="name" class="sm:col-span-2">
                        <input id="segment_name" type="text" class="{{ $input }} mt-1" wire:model="name" maxlength="100">
                    </x-ui.field>

                    <fieldset class="text-sm">
                        <legend class="font-medium text-gray-700">{{ __('Status') }}</legend>
                        @foreach($statuses as $status)
                            <label class="mt-1 flex min-h-11 items-center gap-2 sm:min-h-0"><input type="checkbox" class="rounded border-gray-300" value="{{ $status->value }}" wire:model.live="filters.statuses"> {{ $status->label() }}</label>
                        @endforeach
                    </fieldset>
                    <fieldset class="text-sm">
                        <legend class="font-medium text-gray-700">{{ __('Typ') }}</legend>
                        @foreach($kinds as $kind)
                            <label class="mt-1 flex min-h-11 items-center gap-2 sm:min-h-0"><input type="checkbox" class="rounded border-gray-300" value="{{ $kind->value }}" wire:model.live="filters.kinds"> {{ $kind->label() }}</label>
                        @endforeach
                    </fieldset>
                    <fieldset class="text-sm">
                        <legend class="font-medium text-gray-700">{{ __('Roll') }}</legend>
                        @foreach($roles as $role)
                            <label class="mt-1 flex min-h-11 items-center gap-2 sm:min-h-0"><input type="checkbox" class="rounded border-gray-300" value="{{ $role->value }}" wire:model.live="filters.roles"> {{ $role->label() }}</label>
                        @endforeach
                    </fieldset>
                    <fieldset class="text-sm">
                        <legend class="font-medium text-gray-700">{{ __('Rättslig grund') }}</legend>
                        @foreach($bases as $basis)
                            <label class="mt-1 flex min-h-11 items-center gap-2 sm:min-h-0"><input type="checkbox" class="rounded border-gray-300" value="{{ $basis->value }}" wire:model.live="filters.legal_bases"> {{ $basis->label() }}</label>
                        @endforeach
                    </fieldset>

                    <x-ui.field for="segment_tags" :label="__('Taggar')" error="filters.tags" :hint="$tags->isEmpty() ? __('Kommaseparerade. Någon av dem räcker.') : __('Kommaseparerade. Någon av dem räcker. Finns: :tags', ['tags' => $tags->implode(', ')])">
                        <input id="segment_tags" type="text" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="filters.tags">
                    </x-ui.field>
                    <x-ui.field for="segment_owner" :label="__('Ansvarig säljare')" error="filters.owner_id">
                        <select id="segment_owner" class="{{ $input }} mt-1" wire:model.live="filters.owner_id">
                            <option value="">{{ __('Alla') }}</option>
                            @foreach($members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="segment_sni" :label="__('SNI-kod börjar med')" error="filters.sni">
                        <input id="segment_sni" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="filters.sni" maxlength="5">
                    </x-ui.field>
                    <x-ui.field for="segment_city" :label="__('Ort')" error="filters.city">
                        <input id="segment_city" type="text" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="filters.city">
                    </x-ui.field>
                </div>

                <aside class="rounded-2xl bg-stone-50 p-4 text-sm ring-1 ring-stone-200" aria-live="polite" data-testid="crm-segment-preview">
                    <h3 class="font-semibold text-ink">{{ __('Personer i segmentet') }}</h3>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-ink">{{ $preview['total'] }}</p>
                    <p class="mt-2 text-gray-700">{{ __(':count kan få ett utskick.', ['count' => count($preview['recipients'])]) }}</p>
                    @if($preview['excluded'] !== [])
                        <ul class="mt-2 space-y-1 text-gray-600">
                            @foreach($exclusions as $exclusion)
                                @if(isset($preview['excluded'][$exclusion->value]))
                                    <li>{{ $exclusion->label() }}: <span class="tabular-nums font-medium">{{ $preview['excluded'][$exclusion->value] }}</span></li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </aside>

                <div class="flex flex-wrap items-center gap-4 lg:col-span-3">
                    <x-button wire:loading.attr="disabled">{{ __('Spara segmentet') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canSettings && ! $open)
            <x-button type="button" wire:click="start">{{ __('Nytt segment') }}</x-button>
        @endif
        @if($segments->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga segment ännu')">{{ __('Ett segment väljer vilka ett utskick går till, till exempel beslutsfattare hos aktiva kunder i Göteborg.') }}</x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-segments">
                @foreach($segments as $segment)
                    <li wire:key="segment-{{ $segment->id }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="min-w-0 flex-1 basis-60 font-medium text-gray-900">{{ $segment->name }}</span>
                        @if($canSettings)
                            <span class="flex gap-4 text-sm">
                                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="start({{ $segment->id }})">{{ __('Ändra') }}</button>
                                <button type="button" class="min-h-11 text-gray-700 underline" wire:click="delete({{ $segment->id }})" wire:confirm="{{ __('Ta bort segmentet? Skickade utskick behåller sin statistik.') }}">{{ __('Ta bort') }}</button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
