<div>
    <x-ui.card :title="__('Skicka som e-faktura (Peppol)')">
        @if($transmissions->isNotEmpty())
            <ul class="mb-4 divide-y divide-gray-100 text-sm">
                @foreach($transmissions as $transmission)
                    <li class="py-2">
                        <span class="font-medium">{{ $transmission->status->label() }}</span>
                        · {{ __('till :id', ['id' => $transmission->receiver]) }}
                        · <span class="tabular-nums">{{ ($transmission->delivered_at ?? $transmission->failed_at ?? $transmission->sent_at ?? $transmission->queued_at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                        @if($transmission->supportText())
                            <span @class(['block', 'text-red-700' => $transmission->status === App\Modules\Peppol\Domain\TransmissionStatus::Failed, 'text-gray-600' => $transmission->status !== App\Modules\Peppol\Domain\TransmissionStatus::Failed])>{{ $transmission->supportText() }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if($paused)
            <p role="status" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ App\Modules\Peppol\Support\AccessPoints::pausedMessage() }}</p>
        @elseif($unsupported !== null)
            <p class="text-sm text-gray-600">{{ $unsupported }}</p>
        @elseif(blank($customer->peppol_id))
            <p class="text-sm text-gray-600">{{ __('Kunden har inget Peppol-ID. Lägg till det i kundregistret för att kunna skicka e-faktura.') }}</p>
        @elseif(! $participant?->canSend())
            <p class="text-sm text-gray-600">{{ __('Företaget är inte kopplat till Peppol ännu. Se Företagsinställningar → Moduler → Peppol e-faktura.') }}</p>
        @elseif($canSend)
            @if($invoice->sent_at !== null)
                <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ __('Fakturan är redan skickad med e-post. Skicka den via Peppol bara om kunden ber om det; det är samma faktura, inte en ny.') }}</p>
            @endif
            <p class="text-sm text-gray-700">{{ __('Kundens Peppol-ID: :id', ['id' => $customer->peppol_id]) }}</p>
            @if($lookupResult !== '')
                <p role="status" @class(['mt-2 text-sm', 'text-green-800' => $lookupOk, 'text-red-700' => ! $lookupOk])>{{ $lookupResult }}</p>
            @endif
            <x-input-error for="peppol_id" class="mt-1" />
            <x-input-error for="peppol" class="mt-1" />
            <div class="mt-3 flex flex-wrap gap-3">
                <x-secondary-button type="button" wire:click="check" wire:loading.attr="disabled">{{ __('Kontrollera mottagaren') }}</x-secondary-button>
                @unless($transmissions->contains(fn ($transmission) => $transmission->status->isActive()))
                    <x-button type="button" wire:click="send" wire:loading.attr="disabled">{{ $transmissions->isEmpty() ? __('Skicka via Peppol') : __('Skicka igen via Peppol') }}</x-button>
                @endunless
            </div>
            <p class="mt-2 text-sm text-gray-500">{{ __('Fakturan går som e-faktura enligt Peppol BIS Billing 3.0 med PDF:en bifogad. Bokföringen påverkas inte.') }}</p>
        @endif
    </x-ui.card>
</div>
