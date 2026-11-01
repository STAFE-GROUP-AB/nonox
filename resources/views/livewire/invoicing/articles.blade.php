@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $rates = [2500 => '25 %', 1200 => '12 %', 600 => '6 %', 0 => __('Momsfri')];
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra artikel') : __('Ny artikel')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="article_number" :label="__('Artikelnummer')" error="number" class="sm:col-span-2">
                    <input id="article_number" type="text" class="{{ $input }} mt-1" wire:model="form.number" maxlength="30">
                </x-ui.field>
                <x-ui.field for="article_name" :label="__('Benämning')" error="name" class="sm:col-span-4">
                    <input id="article_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" placeholder="{{ __('Till exempel: Lunch, Konsulttimme') }}">
                </x-ui.field>
                <x-ui.field for="article_price" :label="__('Pris exkl. moms')" error="price" class="sm:col-span-2">
                    <input id="article_price" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.price" placeholder="149,00">
                </x-ui.field>
                <x-ui.field for="article_unit" :label="__('Enhet')" error="unit">
                    <input id="article_unit" type="text" class="{{ $input }} mt-1" wire:model="form.unit" maxlength="10">
                </x-ui.field>
                <x-ui.field for="article_vat" :label="__('Moms')" error="vat_rate">
                    <select id="article_vat" class="{{ $input }} mt-1" wire:model="form.vat_rate">
                        @foreach($rates as $rate => $label)
                            <option value="{{ $rate }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="article_account" :label="__('Intäktskonto')" error="account" class="sm:col-span-2" :hint="__('Lämna tomt för standardkontot för momssatsen.')">
                    <select id="article_account" class="{{ $input }} mt-1" wire:model="form.account">
                        <option value="">{{ __('Standard') }}</option>
                        @foreach($revenueAccounts as $account)
                            <option value="{{ $account->number }}">{{ $account->number }} {{ $account->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canManage && ! $showForm)
            <x-slot:cta>
                <x-button type="button" wire:click="create">{{ __('Ny artikel') }}</x-button>
            </x-slot:cta>
        @endif

        @if($articles->isEmpty())
            <x-ui.empty-state :title="__('Inga artiklar ännu')">
                {{ __('Artiklar är det du säljer, med pris och moms. De gör det snabbt att fylla i fakturor. Du kan också skriva fria rader direkt på fakturan.') }}
            </x-ui.empty-state>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Benämning') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Pris exkl. moms') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Moms') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Konto') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Sålt :year', ['year' => $year]) }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($articles as $article)
                        <tr wire:key="article-{{ $article->id }}">
                            <td class="py-2 pr-4 font-semibold tabular-nums">{{ $article->number }}</td>
                            <td class="py-2 pr-4">{{ $article->name }} <span class="text-gray-500">/ {{ $article->unit }}</span></td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="$article->price_ore" /></td>
                            <td class="py-2 pr-4">{{ $rates[$article->vat_rate] ?? '' }}</td>
                            <td class="py-2 pr-4 tabular-nums">{{ $article->account?->number }}</td>
                            <td class="py-2 pr-4 text-right">
                                <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $article->sold_this_year)" />
                                <span class="block text-xs text-gray-500 tabular-nums">{{ rtrim(rtrim(number_format((float) $article->quantity_this_year, 3, ',', ' '), '0'), ',') }} {{ $article->unit }}</span>
                            </td>
                            <td class="py-2 text-right">
                                @if($canManage)
                                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $article->id }})">{{ __('Ändra') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
