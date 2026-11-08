@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = config('app.timezone');
@endphp

<x-ui.card :title="__('Signering')" :description="$isQuote ? ($crm ? __('Skicka offerten för signering med namnteckning. När kunden har signerat blir offerten en order, och affären vinns.') : __('Skicka offerten för signering med namnteckning. När kunden har signerat blir offerten en order.')) : __('Skicka avtalet för signering med namnteckning. Kunden behöver inget konto.')" data-testid="crm-signing-panel">
    @if($canSend && $open)
        <form wire:submit="send" class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-ui.field for="signing-email" :label="__('E-post till den som ska signera')" error="email">
                <input id="signing-email" type="email" class="{{ $input }} mt-1" wire:model="email" autocomplete="off" required>
            </x-ui.field>
            <x-ui.field for="signing-expires" :label="__('Sista dag att signera (frivilligt)')" error="expires_on" :hint="$isQuote ? __('Annars offertens giltighetsdag, eller 30 dagar. Högst 90 dagar.') : __('Annars 30 dagar. Högst 90 dagar.')">
                <input id="signing-expires" type="date" class="{{ $input }} mt-1" wire:model="expiresOn">
            </x-ui.field>
            @if($deals->isNotEmpty())
                <x-ui.field for="signing-deal" :label="__('Affär som vinns vid signering')" error="deal_id">
                    <select id="signing-deal" class="{{ $input }} mt-1" wire:model="dealId">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($deals as $deal)
                            <option value="{{ $deal->id }}">{{ $deal->title }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif
            @if($canInvoice)
                <label class="flex items-start gap-3 self-end text-sm sm:col-span-1">
                    <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="createInvoice">
                    <span>{{ __('Gör också ett fakturautkast när offerten är signerad') }}</span>
                </label>
            @endif
            <x-input-error for="document" class="sm:col-span-2" />
            <div class="sm:col-span-2">
                <x-button wire:loading.attr="disabled">{{ __('Skicka för signering') }}</x-button>
                <p class="mt-2 text-sm text-gray-600">{{ __('Ett mejl med en personlig länk går till adressen. Länken kan användas en gång. En tidigare länk för samma dokument slutar gälla.') }}</p>
            </div>
        </form>
    @elseif(! $open && $requests->isEmpty())
        <p class="mt-4 text-sm text-gray-600">{{ $isQuote ? __('Offerten är inte öppen och kan inte skickas för signering.') : __('Avtalet är signerat.') }}</p>
    @endif

    @if($requests->isNotEmpty())
        <ul class="mt-6 divide-y divide-gray-100 border-t border-gray-100" data-testid="crm-signing-requests">
            @foreach($requests as $signing)
                @php($status = $signing->effectiveStatus())
                <li wire:key="signing-{{ $signing->id }}" class="py-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
                        <span class="text-sm text-gray-700">{{ __('till :email', ['email' => $signing->email]) }} · {{ __('gäller till :date', ['date' => $signing->expires_at->setTimezone($zone)->format('Y-m-d')]) }}</span>
                        @if($status === App\Domain\Signing\SigningStatus::Pending && $canSend)
                            <button type="button" class="ms-auto min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="revoke({{ $signing->id }})" wire:confirm="{{ __('Återkalla länken? Den går inte längre att använda, och kunden ser att den är återkallad.') }}">{{ __('Återkalla') }}</button>
                        @endif
                    </div>
                    @if($signing->signed_at && $isQuote && $signing->order_id === null)
                        <p class="mt-2 text-sm font-semibold text-accent-700">{{ __('Signerad – ordern är inte skapad') }}</p>
                    @endif
                    @if($signing->signed_at)
                        <p class="mt-2 text-sm text-gray-900">{{ __('Signerad :time av :name', ['time' => $signing->signed_at->setTimezone($zone)->format('Y-m-d H:i'), 'name' => $signing->signer_name]) }}@if($signing->signer_title), {{ $signing->signer_title }}@endif</p>
                    @endif
                    @if($signing->declined_at)
                        <p class="mt-2 text-sm text-gray-900">{{ __('Avböjd :time', ['time' => $signing->declined_at->setTimezone($zone)->format('Y-m-d H:i')]) }}@if($signing->decline_reason): ”{{ $signing->decline_reason }}”@endif</p>
                    @endif
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <a href="{{ route('signing.file', ['signing' => $signing->id, 'file' => 'dokument']) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('PDF:en som skickades') }}</a>
                        @if($signing->signed_document_path)
                            <a href="{{ route('signing.file', ['signing' => $signing->id, 'file' => 'signeringsbevis']) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Signeringsbevis') }}</a>
                        @endif
                    </div>
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer text-gray-600">{{ __('Händelser') }}</summary>
                        <ol class="mt-2 space-y-1">
                            @foreach($signing->events as $event)
                                <li class="tabular-nums text-gray-700">{{ $event->created_at->setTimezone($zone)->format('Y-m-d H:i') }} · {{ $event->event->label() }}</li>
                            @endforeach
                        </ol>
                        <p class="mt-2 break-all text-xs text-gray-600">{{ __('SHA-256: :hash', ['hash' => $signing->document_sha256]) }}</p>
                    </details>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
