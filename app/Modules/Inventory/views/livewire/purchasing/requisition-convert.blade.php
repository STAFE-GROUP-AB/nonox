@php
    use App\Modules\Inventory\Domain\Quantity;
    use App\Support\Money\Money;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div class="space-y-6">
    <x-ui.card>
        <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
            <x-ui.field for="rc-supplier" :label="__('Leverantör')" error="supplier" :hint="__('Raderna läggs på en inköpsorder till den här leverantören. Rader som är avsedda för en annan leverantör visas inte.')">
                <select id="rc-supplier" wire:model.live="supplier" class="{{ $input }}">
                    <option value="">{{ __('Välj leverantör') }}</option>
                    @foreach($suppliers as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>

        @if($rows->isEmpty())
            <div class="mt-4">
                <x-ui.empty-state :title="__('Inget att beställa')">{{ __('Det finns inga attesterade rader som väntar på att bli en inköpsorder. När en inköpsanmodan är attesterad dyker raderna upp här.') }}</x-ui.empty-state>
            </div>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-600">
                            <th class="py-2 pr-3 font-medium"><span class="sr-only">{{ __('Välj') }}</span></th>
                            <th class="py-2 pr-4 font-medium">{{ __('Anmodan') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Estimerat à-pris') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Önskad leverantör') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Konto') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rows as $row)
                            @php($loose = $row->article === null || ! $row->article->is_stock_item)
                            <tr wire:key="rc-row-{{ $row->id }}">
                                <td class="py-2 pr-3">
                                    <input type="checkbox" value="{{ $row->id }}" wire:model="selected" id="rc-select-{{ $row->id }}" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" @disabled($supplier === '')>
                                    <label for="rc-select-{{ $row->id }}" class="sr-only">{{ __('Beställ raden :name', ['name' => $row->description]) }}</label>
                                </td>
                                <td class="py-2 pr-4 tabular-nums"><a href="{{ route('inventory.requisitions.show', $row->purchase_requisition_id) }}" class="font-semibold text-accent-700 underline">{{ $row->requisition?->number }}</a><span class="block text-gray-600">{{ $row->requisition?->requester?->name }}</span></td>
                                <td class="py-2 pr-4">@if($row->article)<span class="tabular-nums">{{ $row->article->number }}</span> @endif{{ $row->description }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format($row->quantity_milli) }} {{ $row->unit?->code }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    <input wire:model.blur="details.{{ $row->id }}.price" inputmode="decimal" aria-label="{{ __('À-pris för :name', ['name' => $row->description]) }}" placeholder="{{ $row->estimated_price_ore === null ? ($loose ? __('Ange pris') : __('Artikelns pris')) : Money::ofOre($row->estimated_price_ore)->format(withCurrency: false) }}" class="{{ $input }} text-right tabular-nums">
                                </td>
                                <td class="py-2 pr-4">{{ $row->supplier?->name ?? '–' }}</td>
                                <td class="py-2 pr-4">
                                    @if($loose)
                                        <input wire:model.blur="details.{{ $row->id }}.account" inputmode="numeric" maxlength="10" aria-label="{{ __('Konto för :name', ['name' => $row->description]) }}" placeholder="{{ $row->account?->number ?? __('Ange konto') }}" class="{{ $input }} tabular-nums">
                                    @else
                                        –
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-input-error for="convert" class="mt-3" />
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-button type="button" wire:click="convert" wire:loading.attr="disabled" :disabled="$supplier === ''">{{ __('Skapa inköpsorder av valda rader') }}</x-button>
                <button type="button" wire:click="selectAll" class="text-sm font-medium text-accent-700 underline" @disabled($supplier === '')>{{ __('Välj alla') }}</button>
                <button type="button" wire:click="clearSelection" class="text-sm text-gray-600 underline">{{ __('Välj ingen') }}</button>
            </div>
        @endif
    </x-ui.card>
</div>
