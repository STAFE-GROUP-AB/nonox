{{--
    My messages about the schedule, on Min sida (ADR-037 gap 5). A new
    message opens with "Läs", which counts as read; one that asks for it is
    confirmed with "Jag har läst". The employee never sees who else got it.
--}}
@if($messages->isNotEmpty())
    <x-ui.card>
        <div id="meddelanden" class="scroll-mt-24" data-my-messages>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Meddelanden om schemat') }}</p>
            <p class="mt-1 text-xs text-gray-500" data-read-notice>{{ __('När du öppnar ett meddelande ser din chef att du har läst det, och när. Det tas bort efter tolv månader.') }}</p>
            <ul class="mt-3 divide-y divide-gray-100">
                @foreach($messages as $receipt)
                    @php($message = $receipt->message)
                    <li class="space-y-2 py-3" wire:key="my-message-{{ $receipt->id }}" data-my-message="{{ $receipt->id }}">
                        <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            @if($receipt->read_at === null)<span class="rounded-full bg-accent-600 px-2 py-0.5 font-semibold text-white">{{ __('Nytt') }}</span>@endif
                            <span>{{ $message->date_from->equalTo($message->date_to) ? $day($message->date_from) : $day($message->date_from).' – '.$day($message->date_to) }}</span>
                            @if($message->author)<span>· {{ __('från :name', ['name' => $message->author->name]) }}</span>@endif
                        </p>
                        @if($receipt->read_at === null)
                            <button type="button" wire:click="readMessage({{ $receipt->id }})" class="rounded-lg bg-accent-50 px-3 py-2 text-sm font-semibold text-accent-800 hover:bg-accent-100">{{ __('Läs meddelandet') }}</button>
                        @else
                            <p class="whitespace-pre-line text-sm text-gray-900">{{ $message->body }}</p>
                            @if($message->requires_ack)
                                @if($receipt->acknowledged_at)
                                    <p class="text-xs text-green-800">{{ __('Du bekräftade :time', ['time' => $receipt->acknowledged_at->format('Y-m-d H:i')]) }}</p>
                                @else
                                    <x-button type="button" wire:click="acknowledgeMessage({{ $receipt->id }})">{{ __('Jag har läst') }}</x-button>
                                @endif
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-gray-500">{{ __('Meddelandena går inte att svara på här. Har du frågor tar du dem med din chef.') }}</p>
        </div>
    </x-ui.card>
@endif
