@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
        <label class="flex items-start gap-3 sm:col-span-2">
            <input type="checkbox" wire:model="autoProject" class="mt-1 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <span><span class="font-medium text-gray-900">{{ __('Skapa ett projekt för varje arbetsorder') }}</span><br><span class="text-sm text-gray-500">{{ __('Projektkoden blir AO och arbetsorderns nummer, så leverantörsfakturor, tidrapporter och verifikationer kan kopplas till jobbet.') }}</span></span>
        </label>
        <x-ui.field for="wo-markup" :label="__('Påslag på material, procent')" error="markup" :hint="__('Används när materialet inte har något försäljningspris.')">
            <input id="wo-markup" wire:model="markup" inputmode="decimal" class="{{ $input }}">
        </x-ui.field>
        <x-ui.field for="wo-method" :label="__('Metod för fastprisuppdrag vid bokslut')" error="method" :hint="__('Samma metod för alla fastprisuppdrag. Bokslutssteget Pågående arbeten föreslår verifikationer efter den, men bokför aldrig själv.')">
            <select id="wo-method" wire:model="fixedPriceMethod" class="{{ $input }}">
                @foreach($methods as $method)
                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <div class="sm:col-span-2"><x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button></div>
    </form>

    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Typer av arbete') }}</h3>
            @if($editing === null)
                <x-secondary-button type="button" wire:click="createKind">{{ __('Ny typ av arbete') }}</x-secondary-button>
            @endif
        </div>
        <p class="mt-1 text-sm text-gray-500">{{ __('Bara typer som är Arbete kan ge ROT- eller RUT-avdrag. Resor räknas per resa eller kilometer och ger aldrig avdrag.') }}</p>

        @if($editing !== null)
            <form wire:submit="saveKind" class="mt-4 grid gap-4 sm:grid-cols-6">
                @if($editing === 0)
                    <x-ui.field for="kind-code" :label="__('Kod')" error="form.code" class="sm:col-span-2"><input id="kind-code" wire:model="form.code" maxlength="20" class="{{ $input }} uppercase"></x-ui.field>
                    <x-ui.field for="kind-type" :label="__('Typ')" error="form.type" class="sm:col-span-2">
                        <select id="kind-type" wire:model="form.type" class="{{ $input }}">
                            @foreach($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="kind-name" :label="__('Namn')" error="form.name" class="sm:col-span-2"><input id="kind-name" wire:model="form.name" maxlength="100" class="{{ $input }}"></x-ui.field>
                <x-ui.field for="kind-unit" :label="__('Räknas i')" error="form.unit" class="sm:col-span-2">
                    <select id="kind-unit" wire:model="form.unit" class="{{ $input }}">
                        @foreach($units as $unit)<option value="{{ $unit->value }}">{{ $unit->label() }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="kind-rate" :label="__('Pris per enhet exklusive moms')" error="form.default_rate" class="sm:col-span-2"><input id="kind-rate" wire:model="form.default_rate" inputmode="decimal" class="{{ $input }} text-right tabular-nums"></x-ui.field>
                <label class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" wire:model="form.is_active" class="rounded border-gray-300 text-accent-600"> {{ __('Aktiv') }}</label>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancelKind">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif

        <ul class="mt-4 divide-y divide-stone-200" aria-label="{{ __('Typer av arbete') }}">
            @foreach($kinds as $kind)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <span>{{ $kind->name }} <span class="text-sm text-gray-500">· {{ $kind->type->label() }} · {{ $rate($kind->default_rate_ore) }}/{{ $kind->unit->label() }}@unless($kind->is_active) · {{ __('Inaktiv') }}@endunless</span></span>
                    <button type="button" wire:click="editKind({{ $kind->id }})" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ändra') }}</button>
                </li>
            @endforeach
        </ul>
    </div>
</div>
