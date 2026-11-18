<div class="space-y-6">
    @if($items === [])
        <x-ui.empty-state :title="__('Inget väntar på din attest')" data-approval-queue-empty>
            {{ __('Här visas leverantörsfakturor, betalfiler, inköpsorder, verifikationer och kreditfakturor som ett attestflöde väntar på att du godkänner. Flödena ställer du in under Attestflöden.') }}
        </x-ui.empty-state>
    @else
        <x-ui.card :title="__('Väntar på dig')" :description="__('Öppna underlaget för att granska och attestera det. Listan visar bara det du får godkänna i steget det står i.')">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm" data-approval-queue>
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Underlag') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Avser') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Attest') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Förberett av') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Skickat') }}</th>
                            <th scope="col" class="py-2 text-right font-medium">{{ __('Belopp') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($items as $item)
                            <tr wire:key="item-{{ $item->source }}-{{ $item->id }}">
                                <td class="py-2 pr-4">
                                    @if($item->url)
                                        <a href="{{ $item->url }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $item->title }}</a>
                                    @else
                                        <span class="font-medium text-gray-900">{{ $item->title }}</span>
                                    @endif
                                    @if($item->stale)
                                        <span class="block text-gray-600">{{ __('Ändrat efter att det skickades. Det kan attesteras när det har skickats till attest igen.') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-700">{{ $item->kind }}</td>
                                <td class="py-2 pr-4 text-gray-700">
                                    {{ $item->reason }}
                                    @if($item->onBehalfOf)
                                        <span class="block text-gray-600">{{ __('på uppdrag av :name', ['name' => $names[$item->onBehalfOf] ?? __('tidigare medlem')]) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-700">{{ $item->preparerId ? ($names[$item->preparerId] ?? __('tidigare medlem')) : '' }}</td>
                                <td class="py-2 pr-4 tabular-nums text-gray-700">{{ $item->date->setTimezone(config('app.timezone'))->toDateString() }}</td>
                                <td class="py-2 text-right">
                                    @if($item->amountOre !== null)
                                        <x-ui.amount :money="App\Support\Money\Money::ofOre($item->amountOre)" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
