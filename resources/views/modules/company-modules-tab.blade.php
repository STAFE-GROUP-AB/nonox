@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    <p class="text-sm text-gray-600">{{ __('Moduler är tillägg som varje företag slår på när det behöver dem. Att stänga av en modul tar aldrig bort några uppgifter; de finns kvar så länge bokföringslagen kräver.') }}</p>

    @if($cards === [])
        <x-ui.empty-state :title="__('Inga moduler är installerade')">{{ __('När en modul installeras visas den här.') }}</x-ui.empty-state>
    @endif

    @foreach($cards as $card)
        @php($module = $card['module'])
        <x-ui.card wire:key="module-{{ $module->key() }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1 basis-40">
                    <h3 class="flex items-center gap-3 text-base font-semibold text-gray-900">
                        {{ $module->name() }}
                        <x-ui.badge :tone="$card['enabled'] ? 'success' : 'neutral'">{{ $card['enabled'] ? __('Aktiv') : __('Inaktiv') }}</x-ui.badge>
                        @if($module instanceof \App\Modules\Contracts\IsNew && now()->lessThan($module->newUntil()))
                            <x-ui.badge tone="accent">{{ __('Ny') }}</x-ui.badge>
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $module->description() }}</p>
                    @if($card['dependencies'] !== [])
                        <p class="mt-1 text-xs text-gray-500">{{ __('Kräver: :modules', ['modules' => implode(', ', $card['dependencies'])]) }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-4">
                    @if($card['enabled'] && $module->settingsComponent())
                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="toggleSettings('{{ $module->key() }}')">{{ __('Inställningar') }}</button>
                    @endif
                    <button type="button" role="switch" aria-checked="{{ $card['enabled'] ? 'true' : 'false' }}" aria-label="{{ __('Slå på eller av :module', ['module' => $module->name()]) }}"
                        wire:click="ask('{{ $module->key() }}', '{{ $card['enabled'] ? 'disable' : 'enable' }}')"
                        @class(['relative inline-flex h-6 w-11 flex-none rounded-full transition', 'bg-accent-600' => $card['enabled'], 'bg-gray-300' => ! $card['enabled']])>
                        <span @class(['inline-block size-5 translate-y-0.5 rounded-full bg-white shadow-sm transition', 'translate-x-5' => $card['enabled'], 'translate-x-0.5' => ! $card['enabled']])></span>
                    </button>
                </div>
            </div>

            @if($card['check'])
                <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm" role="dialog" aria-label="{{ __('Bekräfta') }}">
                    @if(! $card['check']->allowed())
                        <p class="font-medium text-gray-900">{{ $action === 'enable' ? __('Modulen kan inte slås på än:') : __('Modulen kan inte stängas av än:') }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-accent-700">
                            @foreach($card['check']->blockers as $blocker)
                                <li>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="mt-3 text-gray-600 underline" wire:click="cancel">{{ __('Stäng') }}</button>
                    @else
                        <p class="text-gray-800">
                            {{ $action === 'enable'
                                ? __(':module slås på för :company. Menyn och funktionerna visas för dem som har behörighet.', ['module' => $module->name(), 'company' => $company->name])
                                : __(':module stängs av för :company. Uppgifterna finns kvar och visas skrivskyddat för dem som har behörighet till arkivet.', ['module' => $module->name(), 'company' => $company->name]) }}
                        </p>
                        @if($card['check']->warnings !== [])
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-700">
                                @foreach($card['check']->warnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                            <x-ui.field for="module_typed_name" :label="__('Skriv :module för att bekräfta', ['module' => $module->name()])" error="typedName" class="mt-3 max-w-xs">
                                <input id="module_typed_name" type="text" class="{{ $input }} mt-1" wire:model="typedName" autocomplete="off">
                            </x-ui.field>
                        @endif
                        <div class="mt-4 flex items-center gap-3">
                            <x-button type="button" wire:click="confirm" wire:loading.attr="disabled">{{ $action === 'enable' ? __('Slå på') : __('Stäng av') }}</x-button>
                            <button type="button" class="text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                        </div>
                    @endif
                    @error('module') <p class="mt-2 text-accent-700" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif

            @if($settingsFor === $module->key() && $card['enabled'] && $module->settingsComponent())
                <div class="mt-5 border-t border-gray-100 pt-5">
                    @livewire($module->settingsComponent(), ['company' => $company], key('settings-'.$module->key()))
                </div>
            @endif
        </x-ui.card>
    @endforeach
</div>
