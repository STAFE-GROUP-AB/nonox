@if($entries->isEmpty())
    <x-ui.empty-state :title="$kind === 'time' ? __('Ingen tid registrerad') : __('Inget material registrerat')">
        {{ $kind === 'time' ? __('Utförarna registrerar tid på plats i mobilen, eller så registrerar du den här.') : __('Material från lagret, från en leverantörsfaktura eller skrivet för hand.') }}
    </x-ui.empty-state>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-2 pr-3 font-medium">{{ __('Datum') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ $kind === 'time' ? __('Typ av arbete') : __('Material') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('Del') }}</th>
                    <th class="py-2 pr-3 text-right font-medium">{{ __('Antal') }}</th>
                    @if($canManage)<th class="py-2 pr-3 text-right font-medium">{{ __('Belopp') }}</th>@endif
                    <th class="py-2 pr-3 font-medium">{{ __('Vem') }}</th>
                    <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach($entries as $entry)
                    <tr @class(['text-gray-400 line-through' => $entry->isReversed(), 'text-gray-500' => $entry->isReversal()]) wire:key="{{ $kind }}-{{ $entry->id }}">
                        <td class="py-2 pr-3 whitespace-nowrap">{{ $entry->work_date->toDateString() }}</td>
                        <td class="py-2 pr-3">{{ $kind === 'time' ? $entry->labourKind?->name : $entry->description }}@if($entry->isReversal()) <span class="no-underline">({{ __('backad: :reason', ['reason' => $entry->reversal_reason]) }})</span>@endif @unless($entry->billable) · {{ __('ej debiterbar') }}@endunless</td>
                        <td class="py-2 pr-3">{{ $entry->change_order_id ? __('ÄTA :number', ['number' => $changeOrders->firstWhere('id', $entry->change_order_id)?->number]) : __('Huvudarbete') }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">
                            @if($kind === 'time')
                                {{ $entry->minutes !== null ? $hours($entry->minutes).' '.__('tim') : rtrim(rtrim(number_format(((int) $entry->quantity_milli) / 1000, 3, ',', ' '), '0'), ',').' '.$entry->labourKind?->unit->label() }}
                            @else
                                {{ rtrim(rtrim(number_format($entry->quantity_milli / 1000, 3, ',', ' '), '0'), ',') }} {{ $entry->unit }}
                            @endif
                        </td>
                        @if($canManage)<td class="py-2 pr-3 text-right tabular-nums">{{ $kr($entry->netOre()) }}</td>@endif
                        <td class="py-2 pr-3">{{ $entry->user?->name }}</td>
                        <td class="py-2 text-right whitespace-nowrap">
                            @if($entry->invoice_id)
                                <span class="text-gray-500">{{ __('På faktura') }}</span>
                            @elseif($entry->isLive() && ($canManage || $entry->user_id === auth()->id()))
                                <button type="button" wire:click="startReverse('{{ $kind }}:{{ $entry->id }}')" class="font-medium text-accent-700 hover:underline">{{ __('Backa') }}</button>
                            @endif
                        </td>
                    </tr>
                    @if($reversing === $kind.':'.$entry->id)
                        <tr>
                            <td colspan="7" class="py-2">
                                <form wire:submit="reverse" class="flex flex-wrap items-end gap-3">
                                    <x-ui.field for="reverse-reason" :label="__('Varför backas posten?')" error="reason"><input id="reverse-reason" wire:model="reason" maxlength="255" class="mt-1 block w-full rounded-lg border-gray-300" required></x-ui.field>
                                    <x-button type="submit" wire:confirm="{{ __('Backa posten? Den finns kvar och en motpost med samma belopp läggs till.') }}">{{ __('Backa posten') }}</x-button>
                                    <x-secondary-button type="button" wire:click="$set('reversing', null)">{{ __('Avbryt') }}</x-secondary-button>
                                </form>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
@endif
