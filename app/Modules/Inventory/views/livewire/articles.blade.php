@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
@endphp
<div class="space-y-6">
    @if($printing !== null)
        <livewire:inventory.label-print kind="ARTICLE" :items="$printing" :key="'label-print-'.md5((string) json_encode($printing))" />
    @endif

    @if($moving !== null)
        <x-ui.card :title="match ($form['kind'] ?? '') { 'transfer' => __('Flytta varor'), 'scrap' => __('Kassera varor'), 'return_in' => __('Retur från kund'), default => __('Retur till leverantör') }">
            <form wire:submit="saveMovement" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="move-kind" :label="__('Vad händer')" error="form.kind" class="sm:col-span-2">
                    <select id="move-kind" wire:model.live="form.kind" class="{{ $input }}">
                        <option value="transfer">{{ __('Flytt till annan plats') }}</option>
                        <option value="scrap">{{ __('Kassation') }}</option>
                        <option value="return_in">{{ __('Retur från kund (in)') }}</option>
                        <option value="return_out">{{ __('Retur till leverantör (ut)') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="move-from" :label="($form['kind'] ?? '') === 'return_in' ? __('Till plats') : __('Från plats')" error="form.location_id" class="sm:col-span-2">
                    <select id="move-from" wire:model="form.location_id" class="{{ $input }}">
                        <option value="">{{ __('Välj plats') }}</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if(($form['kind'] ?? '') === 'transfer')
                    <x-ui.field for="move-to" :label="__('Till plats')" error="form.to_location_id" class="sm:col-span-2">
                        <select id="move-to" wire:model="form.to_location_id" class="{{ $input }}">
                            <option value="">{{ __('Välj plats') }}</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="move-quantity" :label="__('Antal')" error="form.quantity" class="sm:col-span-1">
                    <input id="move-quantity" wire:model="form.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="move-date" :label="__('Datum')" error="form.date" class="sm:col-span-2">
                    <input id="move-date" type="date" wire:model="form.date" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="move-note" :label="__('Anteckning')" error="form.note" class="sm:col-span-3">
                    <input id="move-note" wire:model="form.note" maxlength="255" class="{{ $input }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Registrera') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canManage)
            <x-slot:cta>
                <a href="{{ route('invoicing.articles', ['ny' => 1]) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-transparent bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 sm:min-h-0">{{ __('Ny artikel') }}</a>
            </x-slot:cta>
        @endif

        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block grow sm:max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök nummer, namn, EAN eller SKU') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}" autofocus>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Lager') }}</span>
                <select wire:model.live="warehouse" class="{{ $input }}">
                    <option value="">{{ __('Alla lager') }}</option>
                    @foreach($warehouses as $warehouseOption)
                        <option value="{{ $warehouseOption->id }}">{{ $warehouseOption->full_code }} {{ $warehouseOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="lagerforda">{{ __('Lagerförda artiklar') }}</option>
                    <option value="lagt">{{ __('Under beställningspunkten') }}</option>
                    <option value="negativt">{{ __('Negativt saldo') }}</option>
                    <option value="ej-utskrivna">{{ __('Ej utskrivna etiketter') }}</option>
                    <option value="alla">{{ __('Alla artiklar') }}</option>
                </select>
            </label>
            @if($canPrint)
                <x-secondary-button type="button" wire:click="printLabels" class="min-h-11">
                    @if(count($selected) > 0)
                        {{ __('Skriv ut etiketter (:count valda)', ['count' => count($selected)]) }}
                    @else
                        {{ __('Skriv ut etiketter') }}
                    @endif
                </x-secondary-button>
            @endif
        </div>

        @if($articles->isEmpty())
            <x-ui.empty-state :title="__('Inga artiklar att visa')">
                {{ __('Markera en artikel som lagerförd under Avancerat i artikelregistret. Sedan kan du ta emot varor på den med en inleverans.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            @if($canPrint)
                                <th scope="col" class="w-10 py-2 pr-2"><span class="sr-only">{{ __('Välj för etiketter') }}</span></th>
                            @endif
                            <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Per lagerställe') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('I lager') }}</th>
                            @if($seesPrices)
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Inköpspris') }}</th>
                            @endif
                            <th scope="col"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($articles as $article)
                            @php($low = $article->reorder_point_milli !== null && (int) $article->stock_milli <= $article->reorder_point_milli)
                            <tr wire:key="stock-article-{{ $article->id }}">
                                @if($canPrint)
                                    <td class="py-2 pr-2 align-top">
                                        <input type="checkbox" wire:model.live="selected" value="{{ $article->id }}" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" aria-label="{{ __('Välj :name för etiketter', ['name' => $article->name]) }}">
                                    </td>
                                @endif
                                <td class="py-2 pr-4">
                                    <span class="font-semibold tabular-nums">{{ $article->number }}</span> {{ $article->name }}
                                    @if($article->gtin)<span class="block text-xs text-gray-500 tabular-nums">EAN {{ $article->gtin }}</span>@endif
                                    @if(! $article->is_stock_item)<x-ui.badge>{{ __('Inte lagerförd') }}</x-ui.badge>@endif
                                    @if($canPrint && $article->labels_printed_at === null)<x-ui.badge>{{ __('Ej utskriven') }}</x-ui.badge>@endif
                                </td>
                                <td class="py-2 pr-4">
                                    @forelse($article->stockLevels->sortBy(fn ($level) => $level->location?->sort_key) as $level)
                                        <span class="mr-3 inline-block whitespace-nowrap tabular-nums"><span class="text-gray-500">{{ $level->location?->full_code }}</span> {{ $q($level->quantity_milli) }}</span>
                                    @empty
                                        <span class="text-gray-500">{{ __('Inget i lager') }}</span>
                                    @endforelse
                                </td>
                                <td @class(['py-2 pr-4 text-right font-semibold tabular-nums whitespace-nowrap', 'text-accent-700' => $low || (int) $article->stock_milli < 0])>
                                    {{ $q((int) $article->stock_milli) }} {{ $article->unit }}
                                    @if($low)<span class="block text-xs font-normal">{{ __('Beställ :quantity', ['quantity' => $q($article->reorder_quantity_milli)]) }}</span>@endif
                                </td>
                                @if($seesPrices)
                                    <td class="py-2 pr-4 text-right">
                                        @if($article->purchase_price_ore !== null)<x-ui.amount :money="App\Support\Money\Money::ofOre($article->purchase_price_ore)" />@endif
                                    </td>
                                @endif
                                <td class="py-2 text-right">
                                    <span class="flex flex-wrap justify-end gap-3 text-sm font-medium">
                                        @if($canCount && $article->is_stock_item)
                                            <button type="button" wire:click="move({{ $article->id }})" class="text-accent-700 underline">{{ __('Flytta') }}</button>
                                            <button type="button" wire:click="move({{ $article->id }}, 'scrap')" class="text-gray-700 underline">{{ __('Kassera') }}</button>
                                        @endif
                                        @if($canPrint)
                                            <button type="button" wire:click="printLabels({{ $article->id }})" class="text-accent-700 underline">{{ __('Etikett') }}</button>
                                        @endif
                                        @if($canManage)
                                            <a href="{{ route('invoicing.articles', ['redigera' => $article->id]) }}" class="text-accent-700 underline">{{ __('Ändra') }}</a>
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $articles->links() }}</div>
        @endif
    </x-ui.card>
</div>
