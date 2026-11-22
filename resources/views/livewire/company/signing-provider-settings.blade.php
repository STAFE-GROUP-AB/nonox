@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $date = fn ($value): string => $value === null ? '–' : $value->setTimezone($zone)->format('Y-m-d');
@endphp

<x-ui.card :title="__('Underskrift hos extern tjänst')" :description="__('En undertecknare kan skriva under hos en tjänst för underskrift som företaget har avtal med, med sitt eget certifikat. Underskriften läggs in i den signerade PDF:en (PAdES). Tjänsten ska följa Cloud Signature Consortiums API, version 2.')" data-testid="signing-provider-settings">
    <div class="space-y-3 text-sm text-gray-700" data-testid="signing-provider-status">
        @if(! $switchedOn)
            <p role="note" class="rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200" data-testid="signing-provider-off">{{ __('Underskrift hos extern tjänst är inte påslagen i den här installationen. Ingen undertecknare kan skickas till en tjänst. Du kan ändå lägga in företagets tjänst i förväg.') }}</p>
        @elseif(! $engine)
            <x-signing.requires-pdf-engine :feature="__('Underskrift hos extern tjänst')" />
        @else
            <p data-testid="signing-provider-on">{{ __('Underskrift hos extern tjänst är påslagen. Välj tjänsten som kontroll för en undertecknare när du skickar ett dokument.') }}</p>
        @endif
        <p>{{ __('NONOX anger inte själv någon nivå för underskriften. Den nivå du väljer nedan visas som tjänstens uppgift, så välj den som står i ert avtal med tjänsten.') }}</p>
        <p>{{ __('Registrera den här returadressen hos tjänsten:') }} <span class="font-mono text-xs break-all text-gray-900" data-testid="signing-provider-return-url">{{ $returnUrl }}</span></p>
    </div>

    <h3 class="mt-6 text-sm font-semibold text-gray-900">{{ __('Företagets tjänster') }}</h3>
    @if($active->isEmpty())
        <p class="mt-2 text-sm text-gray-700" data-testid="signing-provider-none">{{ __('Ingen tjänst är inlagd. Lägg till en nedan när företaget har avtal med en tjänst för underskrift.') }}</p>
    @else
        <ul class="mt-2 space-y-3" data-testid="signing-provider-active">
            @foreach($active as $provider)
                <li wire:key="signing-provider-{{ $provider->id }}" class="rounded-lg border border-gray-200 p-3 text-sm">
                    <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_1fr]">
                        <dt class="text-gray-600">{{ __('Namn för undertecknarna') }}</dt><dd class="text-gray-900 break-words">{{ $provider->name }}</dd>
                        <dt class="text-gray-600">{{ __('Tjänstens eget namn') }}</dt><dd class="text-gray-900 break-words">{{ $provider->service_name ?? '–' }}@if($provider->service_region) ({{ $provider->service_region }})@endif</dd>
                        <dt class="text-gray-600">{{ __('Adress') }}</dt><dd class="font-mono text-xs break-all text-gray-900">{{ $provider->base_url }}</dd>
                        <dt class="text-gray-600">{{ __('Nivå enligt avtalet') }}</dt><dd class="text-gray-900">{{ $provider->claimed_level->label() }}</dd>
                        <dt class="text-gray-600">{{ __('Inlagd') }}</dt><dd class="tabular-nums text-gray-900">{{ $date($provider->created_at) }}</dd>
                    </dl>
                    <button type="button" class="mt-2 min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="retire({{ $provider->id }})" wire:confirm="{{ __('Sluta använda :name? Klienthemligheten tas bort från NONOX. Undertecknare som redan har fått en länk med den här tjänsten kan inte skriva under, utan ombeds kontakta dig.', ['name' => $provider->name]) }}" data-testid="signing-provider-retire-{{ $provider->id }}">{{ __('Sluta använda tjänsten') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="add" class="mt-6 space-y-3" data-testid="signing-provider-add">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('Lägg till en tjänst') }}</h3>
        <p class="text-sm text-gray-600">{{ __('Uppgifterna får företaget från tjänsten. NONOX frågar tjänsten innan den sparas, sparar klienthemligheten krypterad och visar den aldrig igen. Adressen måste gå till en server på internet med https.') }}</p>
        @if(count($drivers) > 1)
            <x-ui.field for="signing-provider-driver" :label="__('Typ av tjänst')" error="driver">
                <select id="signing-provider-driver" class="{{ $input }} mt-1" wire:model="driver">
                    @foreach($drivers as $key)
                        <option value="{{ $key }}">{{ $key === 'csc' ? __('Cloud Signature Consortium API v2') : __('Testtjänst') }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
        <x-ui.field for="signing-provider-name" :label="__('Namn för undertecknarna')" error="name" :hint="__('Visas för undertecknaren, till exempel tjänstens eget namn.')">
            <input id="signing-provider-name" type="text" maxlength="120" class="{{ $input }} mt-1 w-full max-w-md" wire:model="name">
        </x-ui.field>
        <x-ui.field for="signing-provider-url" :label="__('Tjänstens adress (slutar med /csc/v2)')" error="baseUrl">
            <input id="signing-provider-url" type="url" inputmode="url" maxlength="500" placeholder="https://tjanst.example/csc/v2" class="{{ $input }} mt-1 w-full max-w-lg" wire:model="baseUrl">
        </x-ui.field>
        <x-ui.field for="signing-provider-client" :label="__('Klient-id')" error="clientId">
            <input id="signing-provider-client" type="text" autocomplete="off" maxlength="500" class="{{ $input }} mt-1 w-full max-w-md" wire:model="clientId">
        </x-ui.field>
        <x-ui.field for="signing-provider-secret" :label="__('Klienthemlighet')" error="clientSecret">
            <input id="signing-provider-secret" type="password" autocomplete="off" maxlength="2000" class="{{ $input }} mt-1 w-full max-w-md" wire:model="clientSecret">
        </x-ui.field>
        <x-ui.field for="signing-provider-level" :label="__('Nivå som tjänsten anger i ert avtal')" error="claimedLevel">
            <select id="signing-provider-level" class="{{ $input }} mt-1" wire:model="claimedLevel">
                @foreach($levels as $level)
                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-button type="submit" wire:loading.attr="disabled" wire:target="add">{{ __('Lägg till tjänsten') }}</x-button>
    </form>

    @if($retired->isNotEmpty())
        <div class="mt-6 overflow-x-auto">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Tidigare tjänster') }}</h3>
            <table class="mt-2 w-full min-w-[28rem] text-sm" data-testid="signing-provider-retired">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-600">
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Namn för undertecknarna') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Nivå enligt avtalet') }}</th>
                        <th scope="col" class="py-2 font-medium">{{ __('Slutade användas') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($retired as $old)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-3 break-words">{{ $old->name }}</td>
                            <td class="py-2 pr-3">{{ $old->claimed_level->label() }}</td>
                            <td class="py-2 tabular-nums">{{ $date($old->retired_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
