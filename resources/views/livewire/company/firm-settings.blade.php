@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card :title="__('Redovisningsbyrå')" :description="__('En byrå som har flera kunder i NONOX kan visa sin logga och sina färger för dem. Kunderna väljer själva sin byrå.')">
        <form wire:submit="saveFirm" class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" class="mt-1 rounded-sm border-gray-300" wire:model.live="isFirm">
                <span>
                    <span class="font-medium text-gray-900">{{ __('Vi är en redovisningsbyrå') }}</span>
                    <span class="block text-sm text-gray-600">{{ __('Kunder där någon av era redovisningskonsulter är med kan då välja er som sin byrå.') }}</span>
                </span>
            </label>

            @if($isFirm)
                <label class="flex items-start gap-3">
                    <input type="checkbox" class="mt-1 rounded-sm border-gray-300" wire:model="brandForClients">
                    <span>
                        <span class="font-medium text-gray-900">{{ __('Visa byråns varumärke för våra kunder') }}</span>
                        <span class="block text-sm text-gray-600">{{ __('Kundens användare och anställda ser er logga och era färger i NONOX, på Min sida och i mejlen om inloggning och uppgifter. Kundens egna fakturor, offerter, order och lönebesked behåller kundens varumärke.') }}</span>
                    </span>
                </label>
            @endif

            <x-input-error for="firm" />

            <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
        </form>

        @if($company->is_accounting_firm)
            <div class="mt-6 border-t border-stone-200 pt-6">
                <h3 class="font-semibold text-gray-900">{{ __('Kunder som har valt er') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Kopplingen styr bara utseendet. Ni kommer åt en kund bara genom att vara med i kundens företag.') }}</p>
                @if($clients->isEmpty())
                    <p class="mt-3 text-sm text-gray-500">{{ __('Ingen kund har valt er ännu. Ägaren väljer byrån under Inställningar för företaget, Varumärke.') }}</p>
                @else
                    <ul class="mt-3 divide-y divide-stone-200" data-firm-clients>
                        @foreach($clients as $client)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="client-{{ $client->id }}">
                                <span class="text-sm text-gray-900">{{ $client->name }}</span>
                                <button type="button" class="text-sm font-medium text-gray-600 underline hover:text-gray-900" wire:click="removeClient({{ $client->id }})" wire:confirm="{{ __('Ta bort kopplingen? Kunden ser då sitt eget varumärke igen.') }}">{{ __('Ta bort') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </x-ui.card>

    @unless($company->is_accounting_firm)
        <x-ui.card :title="__('Vår redovisningsbyrå')" :description="__('Välj er byrå så ser ni och era anställda byråns logga och färger i NONOX och på Min sida. Byrån får ingen tillgång av kopplingen; det styrs bara av vilka som är med i företaget.')">
            <div class="mt-4">
                @if($linkedFirm)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-stone-50 px-4 py-3" data-linked-firm>
                        <div>
                            <p class="font-medium text-gray-900">{{ $linkedFirm->name }}</p>
                            @if(! $linkedFirm->is_accounting_firm || ! $linkedFirm->firm_brand_for_clients || $linkedFirm->archived_at)
                                <p class="text-sm text-gray-600">{{ __('Byrån visar inte sitt varumärke just nu, så ni ser ert eget.') }}</p>
                            @elseif($company->accounting_firm_linked_at)
                                <p class="text-sm text-gray-600">{{ __('Vald :date', ['date' => $company->accounting_firm_linked_at->toDateString()]) }}</p>
                            @endif
                        </div>
                        <button type="button" class="text-sm font-medium text-gray-600 underline hover:text-gray-900" wire:click="unlink" wire:confirm="{{ __('Ta bort byrån? Ni ser då ert eget varumärke igen.') }}">{{ __('Ta bort byrån') }}</button>
                    </div>
                @elseif($eligibleFirms->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Här visas byråer där någon som har rollen Redovisningskonsult i ert företag också är med. Bjud in er konsult med den rollen under Uppgifter och personer, så kan ni välja byrån här.') }}</p>
                @else
                    <form wire:submit="link" class="flex flex-wrap items-end gap-3">
                        <x-ui.field for="firm_id" :label="__('Byrå')" class="min-w-0 flex-1">
                            <select id="firm_id" class="{{ $input }} mt-1" wire:model="firmId">
                                <option value="">{{ __('Välj byrå') }}</option>
                                @foreach($eligibleFirms as $firm)
                                    <option value="{{ $firm->id }}">{{ $firm->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-button wire:loading.attr="disabled">{{ __('Välj byrån') }}</x-button>
                    </form>
                    <x-input-error for="firmId" class="mt-2" />
                    <x-input-error for="firm" class="mt-2" />
                @endif
            </div>
        </x-ui.card>
    @endunless
</div>
