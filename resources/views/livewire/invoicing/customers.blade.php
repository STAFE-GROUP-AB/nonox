@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    @if($showForm)
        @include('livewire.invoicing.partials.customer-form')
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-4">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny kund') }}</x-button>
            @endif
            <input type="search" class="{{ $input }} max-w-sm" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på namn, nummer eller e-post') }}" aria-label="{{ __('Sök kund') }}">
        </div>

        @if($customers->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search === '' ? __('Inga kunder ännu') : __('Ingen kund matchar sökningen')">
                {{ __('Lägg till de kunder du fakturerar. Uppgifterna hamnar på fakturan, och betalningsvillkoret styr förfallodagen.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100">
                @foreach($customers as $customer)
                    <li wire:key="customer-{{ $customer->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-8 flex-none font-semibold tabular-nums text-gray-900 sm:w-14">{{ $customer->number }}</span>
                        <span class="min-w-0 flex-1 basis-52">
                            <span class="block font-medium text-gray-900">{{ $customer->name }}@if($customer->processing_restricted_at) <span class="ms-2 rounded-sm bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ __('Begränsad behandling') }}</span>@endif</span>
                            <span class="block text-sm text-gray-600">{{ collect([$customer->org_number, $customer->email, $customer->city, $customer->external_ref ? __('Nummer i tidigare program').': '.$customer->external_ref : null])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="flex-1 text-right text-sm sm:w-36 sm:flex-none">
                            <span class="block text-gray-500">{{ __('Fakturerat :year', ['year' => $year]) }}</span>
                            <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $customer->invoiced_this_year)" class="font-medium text-gray-900" />
                        </span>
                        <span class="flex-1 text-right text-sm sm:w-32 sm:flex-none">
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
