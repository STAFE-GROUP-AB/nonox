@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
@endphp
<div class="space-y-6">
    @if($trace)
        @php($lot = $trace['lot'])
        <x-ui.card :title="($lot->kind === 'SERIAL' ? __('Serienummer :code', ['code' => $lot->code]) : __('Batch :code', ['code' => $lot->code]))" :description="__('Hela vägen: varifrån varan kom och vart den har tagit vägen.')">
            <x-slot:action>
                <button type="button" wire:click="close" class="text-sm font-semibold text-gray-700 underline">{{ __('Stäng') }}</button>
            </x-slot:action>

            <dl class="grid gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-gray-500">{{ __('Artikel') }}</dt>
                    <dd class="font-semibold"><span class="tabular-nums">{{ $lot->article?->number }}</span> {{ $lot->article?->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Bäst före') }}</dt>
                    <dd class="font-semibold tabular-nums">{{ $lot->expires_on?->toDateString() ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Mottagen') }}</dt>
                    <dd class="font-semibold tabular-nums">{{ $lot->received_on->toDateString() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Leverantör') }}</dt>
                    <dd class="font-semibold">{{ $lot->supplier?->name ?? '–' }}</dd>
                </div>
            </dl>

            <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Finns kvar') }}</h3>
            @if($trace['on_hand'] === [])
                <p class="text-sm text-gray-600">{{ __('Inget finns kvar i lager.') }}</p>
            @else
                <p class="text-sm tabular-nums">
                    @foreach($trace['on_hand'] as $row)
                        <span class="mr-4 inline-block"><span class="text-gray-500">{{ $row['location'] }}</span> {{ $q($row['quantity_milli']) }}</span>
                    @endforeach
                </p>
            @endif

            <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Kom in') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Plats') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Antal') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($trace['in'] as $row)
                            <tr>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['date']->toDateString() }}</td>
                                <td class="py-2 pr-4">{{ $row['type'] }} {{ $row['label'] }}</td>
                                <td class="py-2 pr-4">{{ $row['supplier'] ?? '–' }}</td>
                                <td class="py-2 pr-4">{{ $row['location'] }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $q($row['quantity_milli']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Gick ut') }}</h3>
            @if($trace['out'] === [])
                <p class="text-sm text-gray-600">{{ __('Inget har gått ut ännu.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Order eller faktura') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Antal') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($trace['out'] as $row)
                                <tr>
                                    <td class="py-2 pr-4 tabular-nums">{{ $row['date']->toDateString() }}</td>
                                    <td class="py-2 pr-4">{{ $row['type'] }} {{ $row['label'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['source'] ?? '–' }}</td>
                                    <td class="py-2 pr-4">{{ $row['customer'] ?? '–' }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $q($row['quantity_milli']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Återkallningslista: kunder som har fått den') }}</h3>
            @if($trace['customers'] === [])
                <p class="text-sm text-gray-600">{{ __('Ingen kund har fått den här batchen eller det här serienumret.') }}</p>
            @else
                <ul class="divide-y divide-stone-200 text-sm">
                    @foreach($trace['customers'] as $customer)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="font-semibold">{{ $customer['name'] }}</span>
                            <span class="tabular-nums text-gray-600">{{ $q($customer['quantity_milli']) }} · {{ __('senast :date', ['date' => $customer['last_on']->toDateString()]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-4 text-xs text-gray-500">{{ __('NONOX visar vad lagret vet. Det intygar inte att företaget uppfyller de krav på spårbarhet som gäller för till exempel livsmedel.') }}</p>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block grow sm:max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök batch, serienummer eller artikel') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}" autofocus>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="i-lager">{{ __('Finns i lager') }}</option>
                    <option value="utgar">{{ __('Går ut inom 30 dagar') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
        </div>

        @if($lots->isEmpty())
            <x-ui.empty-state :title="__('Inga batcher eller serienummer')">
                {{ __('Välj Batch eller Serienummer under Spårning på en lagerförd artikel. Sedan anger du batchnumret eller serienumren när du tar emot varorna.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Batch eller serienummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Bäst före') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('I lager') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($lots as $row)
                            <tr wire:key="lot-{{ $row->id }}">
                                <td class="py-2 pr-4 font-semibold tabular-nums">{{ $row->code }} <span class="font-normal text-gray-500">{{ $row->kind === 'SERIAL' ? __('serienummer') : __('batch') }}</span></td>
                                <td class="py-2 pr-4"><span class="tabular-nums">{{ $row->article?->number }}</span> {{ $row->article?->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">
                                    @if($row->expires_on)
                                        {{ $row->expires_on->toDateString() }}
                                        @if($row->expires_on->toDateString() < $today)<x-ui.badge>{{ __('Utgången') }}</x-ui.badge>
                                        @elseif($row->expires_on->toDateString() <= $soon)<x-ui.badge>{{ __('Går snart ut') }}</x-ui.badge>@endif
                                    @else
                                        –
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $row->supplier?->name ?? '–' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $q((int) $row->on_hand_milli) }} {{ $row->article?->stockUnitCode() }}</td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="show({{ $row->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Spåra') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $lots->links() }}</div>
        @endif
    </x-ui.card>
</div>
