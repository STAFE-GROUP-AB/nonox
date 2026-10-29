@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $fields = [
        'name' => [__('Namn'), 'sm:col-span-2'],
        'org_number' => [__('Organisationsnummer'), ''],
        'personal_number' => [__('Personnummer (privatperson)'), ''],
        'vat_number' => [__('Momsregistreringsnummer'), ''],
        'email' => [__('E-post för fakturor'), ''],
        'phone' => [__('Telefon'), ''],
        'payment_terms_days' => [__('Betalningsvillkor, dagar'), ''],
        'address_line1' => [__('Adress'), 'sm:col-span-2'],
        'address_line2' => [__('Adress, rad 2'), 'sm:col-span-2'],
        'postal_code' => [__('Postnummer'), ''],
        'city' => [__('Ort'), ''],
    ];
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra kund') : __('Ny kund :number', ['number' => $nextNumber])">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                @foreach($fields as $field => [$label, $span])
                    <x-ui.field :for="'customer_'.$field" :label="$label" :error="$field" class="{{ $span }}">
                        <input id="customer_{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" class="{{ $input }} mt-1" wire:model="form.{{ $field }}" @if($field === 'payment_terms_days') inputmode="numeric" @endif>
                    </x-ui.field>
                @endforeach
                <div class="flex items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <input type="search" class="{{ $input }} max-w-sm" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på namn, nummer eller e-post') }}" aria-label="{{ __('Sök kund') }}">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny kund') }}</x-button>
            @endif
        </div>

        @if($customers->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search === '' ? __('Inga kunder ännu') : __('Ingen kund matchar sökningen')">
                {{ __('Lägg till de kunder du fakturerar. Uppgifterna hamnar på fakturan, och betalningsvillkoret styr förfallodagen.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100">
                @foreach($customers as $customer)
                    <li wire:key="customer-{{ $customer->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-14 flex-none font-semibold tabular-nums text-gray-900">{{ $customer->number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ $customer->name }}</span>
                            <span class="block text-sm text-gray-600">{{ collect([$customer->org_number, $customer->email, $customer->city])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="w-36 flex-none text-right text-sm">
                            <span class="block text-gray-500">{{ __('Fakturerat :year', ['year' => $year]) }}</span>
                            <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $customer->invoiced_this_year)" class="font-medium text-gray-900" />
                        </span>
                        <span class="w-32 flex-none text-right text-sm">
                            <span class="block text-gray-500">{{ __('Obetalt') }}</span>
                            <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $customer->unpaid)" class="font-medium text-gray-900" />
                        </span>
                        @if($canManage)
                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $customer->id }})">{{ __('Ändra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $customers->links() }}</div>
        @endif
    </x-ui.card>
</div>
