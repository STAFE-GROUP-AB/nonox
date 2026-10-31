@php
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $fields = [
        'name' => [__('Namn'), 'sm:col-span-2', null],
        'org_number' => [__('Organisationsnummer'), '', null],
        'vat_number' => [__('Momsregistreringsnummer'), '', null],
        'bankgiro' => [__('Bankgiro'), '', __('Kontrolleras mot kontrollsiffran.')],
        'plusgiro' => [__('Plusgiro'), '', null],
        'iban' => [__('IBAN (utländsk leverantör)'), '', null],
        'bic' => [__('BIC'), '', null],
        'email' => [__('E-post'), '', null],
        'phone' => [__('Telefon'), '', null],
        'address_line1' => [__('Adress'), 'sm:col-span-2', null],
        'postal_code' => [__('Postnummer'), '', null],
        'city' => [__('Ort'), '', null],
        'payment_terms_days' => [__('Betalningsvillkor, dagar'), '', null],
        'default_account' => [__('Konto för fakturorna'), '', __('Föreslås på nya fakturor, till exempel 4010 för varuinköp.')],
    ];
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra leverantör') : __('Ny leverantör :number', ['number' => $nextNumber])">
            @if($editingId)
                <p class="mb-4 text-sm"><a href="{{ route('privacy.export.supplier', $editingId) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner registerutdrag (JSON)') }}</a> <span class="text-gray-500">· {{ __('när personen begär ut sina uppgifter enligt GDPR') }}</span></p>
            @endif
            <datalist id="supplier-default-accounts">
                @foreach($accounts as $account)
                    <option value="{{ $account->number }}">{{ $account->name }}</option>
                @endforeach
            </datalist>
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                @foreach($fields as $field => [$label, $span, $hint])
                    <x-ui.field :for="'supplier_'.$field" :label="$label" :error="$field" :hint="$hint" class="{{ $span }}">
                        <input id="supplier_{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" class="{{ $input }} mt-1" wire:model="form.{{ $field }}"
                            @if(in_array($field, ['payment_terms_days', 'default_account', 'bankgiro', 'plusgiro'], true)) inputmode="numeric" @endif
                            @if($field === 'default_account') list="supplier-default-accounts" autocomplete="off" @endif>
                    </x-ui.field>
                @endforeach
                <div class="flex items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                    @if($editingId)
                        @if(App\Models\Supplier::query()->whereKey($editingId)->whereNotNull('processing_restricted_at')->exists())
                            <button type="button" class="ms-auto text-sm text-accent-700 underline" wire:click="restrict(false)" wire:confirm="{{ __('Häva begränsningen? Uppgifterna kan då användas för nya dokument igen.') }}">{{ __('Häv begränsad behandling') }}</button>
                        @else
                            <button type="button" class="ms-auto text-sm text-accent-700 underline" wire:click="restrict" wire:confirm="{{ __('Begränsa behandlingen enligt GDPR? Uppgifterna sparas men kan inte användas för nya dokument förrän begränsningen hävs.') }}">{{ __('Begränsa behandling') }}</button>
                        @endif
                        <button type="button" class="text-sm text-accent-700 underline" wire:click="erase" wire:confirm="{{ __('Radera uppgifterna enligt GDPR? Finns de i bokföringen anonymiseras de i registret i stället; fakturorna behåller sina uppgifter.') }}">{{ __('Radera enligt GDPR') }}</button>
                    @endif

                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <input type="search" class="{{ $input }} max-w-sm" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på namn, nummer eller bankgiro') }}" aria-label="{{ __('Sök leverantör') }}">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny leverantör') }}</x-button>
            @endif
        </div>

        @if($suppliers->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search === '' ? __('Inga leverantörer ännu') : __('Ingen leverantör matchar sökningen')">
                {{ __('Lägg till dem du köper av: grossisten, hyresvärden, elbolaget. Med bankgiro och konto ifyllt går varje faktura snabbt att registrera.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100">
                @foreach($suppliers as $supplier)
                    <li wire:key="supplier-{{ $supplier->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-14 flex-none font-semibold tabular-nums text-gray-900">{{ $supplier->number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ $supplier->name }}@if($supplier->processing_restricted_at) <span class="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ __('Begränsad behandling') }}</span>@endif</span>
                            <span class="block text-sm text-gray-600">{{ collect([$supplier->paymentDetails(), $supplier->defaultAccount ? $supplier->defaultAccount->number.' '.$supplier->defaultAccount->name : null])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="w-36 flex-none text-right text-sm">
                            <span class="block text-gray-500">{{ __('Köpt :year', ['year' => $year]) }}</span>
                            <x-ui.amount :money="Money::ofOre((int) $supplier->bought_this_year)" class="font-medium text-gray-900" />
                        </span>
                        <span class="w-32 flex-none text-right text-sm">
                            <span class="block text-gray-500">{{ __('Obetalt') }}</span>
                            <x-ui.amount :money="Money::ofOre((int) $supplier->unpaid)" class="font-medium text-gray-900" />
                        </span>
                        @if($canManage)
                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $supplier->id }})">{{ __('Ändra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $suppliers->links() }}</div>
        @endif
    </x-ui.card>
</div>
