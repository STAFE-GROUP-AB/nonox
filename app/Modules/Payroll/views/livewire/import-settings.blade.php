@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<section class="space-y-4" aria-labelledby="payroll-import-settings">
    <div>
        <h3 id="payroll-import-settings" class="text-base font-semibold text-gray-900">{{ __('Löneunderlag från schemasystem') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('System som skickar lönefiler (PAXml eller CSV) och vilken löneart varje kod i filerna blir. Koder som inte är kopplade läggs till här första gången en fil använder dem.') }}</p>
    </div>

    <form wire:submit="addSource" class="flex flex-wrap items-end gap-3">
        <x-ui.field for="import_settings_source" :label="__('Nytt källsystem')" error="source_name">
            <input id="import_settings_source" type="text" maxlength="80" class="{{ $input }} mt-1" wire:model="newSourceName" placeholder="{{ __('Till exempel Personalkollen') }}">
        </x-ui.field>
        <x-button wire:loading.attr="disabled">{{ __('Lägg till') }}</x-button>
    </form>
    <x-input-error for="source" class="mt-1" />

    @forelse($sources as $source)
        <div class="rounded-lg border border-gray-200 p-4" wire:key="import-source-{{ $source->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-medium text-gray-900">{{ $source->name }}</p>
                <button type="button" class="text-sm text-gray-600 underline" wire:click="deleteSource({{ $source->id }})">{{ __('Ta bort') }}</button>
            </div>
            @if($source->mappings->isNotEmpty())
                <ul class="mt-3 divide-y divide-gray-100 text-sm">
                    @foreach($source->mappings as $mapping)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="import-mapping-{{ $mapping->id }}">
                            <span><span class="font-mono font-medium">{{ $mapping->external_code }}</span>@if($mapping->description) <span class="text-gray-500">{{ $mapping->description }}</span>@endif</span>
                            <span class="flex items-center gap-2">
                                <select class="{{ $input }} max-w-xs" aria-label="{{ __('Löneart för :code', ['code' => $mapping->external_code]) }}" wire:change="map({{ $source->id }}, @js($mapping->external_code), $event.target.value)">
                                    <option value="" @selected(! $mapping->isMapped())>{{ __('Inte kopplad') }}</option>
                                    @foreach($payCodes as $payCode)<option value="{{ $payCode->id }}" @selected($mapping->pay_code_id === $payCode->id)>{{ $payCode->name_sv }} ({{ $payCode->code }})</option>@endforeach
                                    <option value="ignore" @selected($mapping->ignored)>{{ __('Läs inte in') }}</option>
                                </select>
                                <button type="button" class="text-gray-400 hover:text-accent-600" wire:click="forget({{ $mapping->id }})" aria-label="{{ __('Ta bort kopplingen') }}">×</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addMapping({{ $source->id }})" class="mt-3 flex flex-wrap items-end gap-3">
                <x-ui.field :for="'import_code_'.$source->id" :label="__('Kod i filen')" error="external_code">
                    <input id="import_code_{{ $source->id }}" type="text" maxlength="40" class="{{ $input }} mt-1" wire:model="newMapping.{{ $source->id }}.code" placeholder="OB1">
                </x-ui.field>
                <x-ui.field :for="'import_choice_'.$source->id" :label="__('Blir löneart')" error="pay_code_id">
                    <select id="import_choice_{{ $source->id }}" class="{{ $input }} mt-1" wire:model="newMapping.{{ $source->id }}.choice">
                        <option value="">{{ __('Välj…') }}</option>
                        @foreach($payCodes as $payCode)<option value="{{ $payCode->id }}">{{ $payCode->name_sv }} ({{ $payCode->code }})</option>@endforeach
                        <option value="ignore">{{ __('Läs inte in') }}</option>
                    </select>
                </x-ui.field>
                <x-button wire:loading.attr="disabled">{{ __('Spara kopplingen') }}</x-button>
            </form>
        </div>
    @empty
        <p class="text-sm text-gray-600">{{ __('Inga källsystem än. Lägg till ett här, eller när du läser in den första filen i en lönekörning.') }}</p>
    @endforelse
</section>
