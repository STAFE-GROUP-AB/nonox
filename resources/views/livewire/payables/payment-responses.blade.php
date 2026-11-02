@php
    use App\Domain\Payables\PaymentResponseStatus;
@endphp

<div class="space-y-6">
    @if($canHandle)
        <x-ui.card :title="__('Läs in bankens svar')" :description="__('Hämta statusrapporten (pain.002) eller debiteringsaviseringen (camt.054) för betalfilerna från internetbanken. Ett kontoutdrag (camt.053) går också. NONOX hittar varje betalning på referensen i betalfilen, bokför det banken har betalat och visar det banken har avvisat.')">
            <div
                x-data="{ over: false }"
                x-on:dragover.prevent="over = true"
                x-on:dragleave.prevent="over = false"
                x-on:drop.prevent="over = false; $wire.upload('file', $event.dataTransfer.files[0])"
                :class="over ? 'border-accent-500 bg-accent-50' : 'border-gray-300'"
                class="rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm"
            >
                <p class="text-gray-700">{{ __('Dra hit filen, eller') }}
                    <label for="payment_response_file" class="cursor-pointer font-semibold text-accent-600 hover:text-accent-700">{{ __('välj fil') }}</label>
                </p>
                <input id="payment_response_file" type="file" class="sr-only" wire:model="file" accept=".xml,application/xml,text/xml">
                <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="file">{{ __('Läser filen…') }}</p>
            </div>
            @error('file') <p class="mt-2 text-sm text-accent-700">{{ $message }}</p> @enderror
        </x-ui.card>
    @endif

    @if($open->isNotEmpty())
        <x-ui.card :title="__('Svar från banken att hantera')" :description="__('Förslag som behöver bekräftas och svar som NONOX inte kunde koppla till en betalning. Avvisade betalningar finns kvar att betala under Att betala.')">
            @include('livewire.payables.partials.payment-response-rows', ['rows' => $open, 'canHandle' => $canHandle, 'prefix' => 'open'])
        </x-ui.card>
    @endif

    @if($import)
        <x-ui.card :title="__('Svar i :name', ['name' => $import->file_name])" :description="__(':format, inläst :date av :name.', ['format' => $import->formatLabel(), 'date' => $import->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'name' => $import->creator?->name])">
            @if($responses->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Filen innehöll inga nya svar om företagets betalfiler.') }}</p>
            @else
                @php($counts = $responses->countBy(fn ($response) => $response->status->value))
                <p class="mb-3 flex flex-wrap gap-2 text-sm">
                    @foreach(PaymentResponseStatus::cases() as $status)
                        @if(($counts[$status->value] ?? 0) > 0)
                            <x-ui.badge :tone="$status->tone()">{{ $status->label() }}: {{ $counts[$status->value] }}</x-ui.badge>
                        @endif
                    @endforeach
                </p>
                @include('livewire.payables.partials.payment-response-rows', ['rows' => $responses, 'canHandle' => $canHandle, 'prefix' => 'file'])
            @endif
        </x-ui.card>
    @endif

    @if($imports->count() > 1)
        <x-ui.card :title="__('Tidigare svar från banken')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($imports as $previous)
                    <li class="flex flex-wrap justify-between gap-4 py-2">
                        <button type="button" class="text-left text-accent-600 hover:text-accent-700" wire:click="$set('importId', '{{ $previous->id }}')">{{ $previous->file_name }}</button>
                        <span class="text-gray-500 tabular-nums">{{ $previous->formatLabel() }} · {{ $previous->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
