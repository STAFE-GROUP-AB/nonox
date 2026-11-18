<div data-intercompany-delivery>
    <x-ui.card :title="__('Leverera till koncernföretaget')">
        @if($deliveries->isNotEmpty())
            <ul class="mb-4 divide-y divide-gray-100 text-sm">
                @foreach($deliveries as $delivery)
                    <li class="py-2" wire:key="delivery-{{ $delivery->id }}">
                        <span class="font-medium">{{ $delivery->status->label() }}</span>
                        · {{ __('till :number', ['number' => $delivery->recipient_org_number]) }}
                        · <span class="tabular-nums">{{ $delivery->created_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                        @if($delivery->deliverer)
                            · {{ $delivery->deliverer->name }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if($delivered)
            <p class="text-sm text-gray-600">{{ __('Fakturan ligger i koncernföretagets inkorg. Där tolkar, registrerar och attesterar företagets egna användare den som en leverantörsfaktura. Ni ser inte hur den hanteras.') }}</p>
        @elseif($unsupported !== null)
            <p class="text-sm text-gray-600">{{ $unsupported }}</p>
        @elseif($canDeliver)
            <p class="text-sm text-gray-700">{{ __('Kunden är markerad som koncernföretaget :number.', ['number' => $marking?->counterparty_org_number]) }}</p>
            <x-input-error for="delivery" class="mt-1" />
            <div class="mt-3">
                <x-button type="button" wire:click="deliver" wire:loading.attr="disabled"
                    wire:confirm="{{ __('Fakturan levereras som e-faktura till koncernföretagets inkorg i NONOX. Det går inte att ta tillbaka en levererad faktura; rätta den i så fall med en kreditfaktura. Bokföringen påverkas inte.') }}">
                    {{ __('Leverera till koncernföretaget') }}
                </x-button>
            </div>
            <p class="mt-2 text-sm text-gray-500">{{ __('Fakturan går som e-faktura enligt Peppol BIS Billing 3.0 med PDF:en bifogad, direkt till företagets inkorg i NONOX. Mottagaren måste ha valt att ta emot koncernfakturor från er.') }}</p>
        @endif
    </x-ui.card>
</div>
