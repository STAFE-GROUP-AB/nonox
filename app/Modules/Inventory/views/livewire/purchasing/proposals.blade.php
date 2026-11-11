@php
    use App\Modules\Inventory\Domain\Quantity;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div class="space-y-6">
    <x-ui.card :description="__('Förslaget räknar för varje lagerartikel: fritt nu = i lager minus reserverat (:sources), på väg = öppna inköpsorder, saknas = reserverat minus i lager minus på väg. En artikel vid eller under sin beställningspunkt fylls på med beställningskvantiteten.', ['sources' => mb_strtolower(implode(', ', $sources))])">
        @if($lines === [])
            <x-ui.empty-state :title="__('Inget behöver beställas')">{{ __('Ingen lagerartikel ligger vid sin beställningspunkt eller saknas för en öppen kundorder. Sätt en beställningspunkt på artiklarna under Lager → Artiklar för att få förslag.') }}</x-ui.empty-state>
        @else
            <form wire:submit="create" class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-600">
                                @if($canManage)<th class="py-2 pr-3 font-medium"><span class="sr-only">{{ __('Beställ') }}</span></th>@endif
                                <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('I lager') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Reserverat') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('På väg') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Saknas') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Beställningspunkt') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Beställ') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($lines as $line)
                                @php($article = $line->article)
                                @php($stock = $article->stockUnitCode())
                                <tr wire:key="proposal-{{ $article->id }}">
                                    @if($canManage)
                                        <td class="py-2 pr-3"><input type="checkbox" wire:model="choices.{{ $article->id }}.selected" aria-label="{{ __('Beställ :name', ['name' => $article->name]) }}" class="rounded border-gray-300 text-accent-600"></td>
                                    @endif
                                    <td class="py-2 pr-4"><span class="tabular-nums">{{ $article->number }}</span> {{ $article->name }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format($line->onHandMilli) }} {{ $stock }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format($line->reservedMilli) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format($line->comingMilli) }}@if($line->comingFrom)<span class="block text-gray-600">{{ __('från :date', ['date' => $line->comingFrom]) }}</span>@endif</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">@if($line->shortMilli > 0)<strong class="text-red-700">{{ Quantity::format($line->shortMilli) }}</strong>@else 0 @endif</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $article->reorder_point_milli === null ? '–' : Quantity::format($article->reorder_point_milli) }}@if($line->belowReorderPoint)<span class="block text-gray-600">{{ __('nådd') }}</span>@endif</td>
                                    <td class="py-2 pr-4">
                                        @if($canManage)
                                            <div class="flex items-center gap-2">
                                                <input wire:model="choices.{{ $article->id }}.quantity" inputmode="decimal" aria-label="{{ __('Antal att beställa av :name', ['name' => $article->name]) }}" class="{{ $input }} w-24 text-right tabular-nums">
                                                <span>{{ $line->unit->code }}</span>
                                            </div>
                                        @else
                                            <span class="tabular-nums">{{ str_replace('.', ',', $line->proposedQuantity) }} {{ $line->unit->code }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($canManage)
                                            <select wire:model="choices.{{ $article->id }}.supplier_id" aria-label="{{ __('Leverantör för :name', ['name' => $article->name]) }}" class="{{ $input }} min-w-40">
                                                <option value="">{{ __('Välj leverantör') }}</option>
                                                @foreach($suppliers as $supplier)
                                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            {{ $article->defaultSupplier?->name ?? __('Ingen leverantör') }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($canManage)
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa inköpsordrar för det som saknas') }}</x-button>
                        <span class="text-sm text-gray-600">{{ __('Det blir ett utkast per leverantör. Inget skickas förrän du har granskat och skickat varje order.') }}</span>
                    </div>
                @endif
            </form>
        @endif
    </x-ui.card>
</div>
