@php
    $statuses = ['SUBMITTED' => [__('Väntar på svar'), 'bg-gray-100 text-gray-700'], 'APPROVED' => [__('Godkänd, betalas med lönen'), 'bg-green-50 text-green-800'], 'PAID' => [__('Utbetald'), 'bg-green-50 text-green-800'], 'REJECTED' => [__('Inte godkänd'), 'bg-accent-50 text-accent-800']];
@endphp
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <label for="mileage_month" class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Månad') }}</label>
                <input id="mileage_month" type="month" class="rounded-lg border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model.live="month">
            </div>
            @unless($tripOpen)
                <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:border-gray-300" wire:click="startTrip">{{ __('Ny resa') }}</button>
            @endunless
        </div>

        @if($tripOpen)
            @include('mileage.partials.trip-form', ['allowOwnCar' => true])
        @endif

        @include('mileage.partials.trip-list', ['empty' => __('Inga resor i :month. Tryck på Ny resa och skriv datum, sträcka och ärende.', ['month' => $monthName])])
    </x-ui.card>

    <x-ui.card :title="__('Skicka in resorna')">
        @if($toSend->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Det finns inga resor att skicka in för :month.', ['month' => $monthName]) }}</p>
        @else
            <form wire:submit="submitClaim" class="space-y-3">
                <p class="text-sm text-gray-700">{{ trans_choice(':count resa, :distance, skickas in som milersättning för :month.|:count resor, :distance, skickas in som milersättning för :month.', $toSend->count(), ['count' => $toSend->count(), 'distance' => $formatMil($toSendHm), 'month' => $monthName]) }}</p>
                @if($reminder)
                    <p class="rounded-lg bg-accent-50 p-3 text-sm text-accent-900">{{ __('Skicka in resorna senast :date, så kommer de med i lönen.', ['date' => $reminder->toDateString()]) }}</p>
                @endif
                @if($needsFuel)
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="paidAllFuel">
                        <span>{{ __('Jag har betalat allt drivmedel för resorna med företagets bil') }}
                            <span class="block text-xs text-gray-500">{{ __('Bara då är milersättningen för en förmånsbil skattefri. Har företaget betalat något av drivmedlet kan resorna bara ersättas som lön.') }}</span>
                        </span>
                    </label>
                @endif
                <x-button wire:confirm="{{ __('Skicka in resorna? De kan inte ändras förrän de är besvarade.') }}" wire:loading.attr="disabled">{{ __('Skicka in resorna för :month', ['month' => $monthName]) }}</x-button>
            </form>
        @endif

        @if($claims->isNotEmpty())
            <ul class="mt-4 divide-y divide-gray-100 border-t border-gray-100">
                @foreach($claims as $claim)
                    <li class="flex flex-wrap items-center gap-3 py-3 text-sm" wire:key="claim-{{ $claim->id }}">
                        <span class="min-w-0 flex-1 basis-40">
                            <span class="block font-medium text-gray-900">{{ $claim->description }}</span>
                            <span class="block text-gray-600 tabular-nums">{{ ($claim->trip_data['rate_pending'] ?? false) ? __('Beloppet räknas när Skatteverket har publicerat det') : $claim->amount_ore->format() }}</span>
                            @if($claim->status === 'REJECTED' && $claim->rejection_reason)<span class="block text-accent-800">{{ $claim->rejection_reason }}</span>@endif
                        </span>
                        <span class="rounded-full px-3 py-1 text-xs font-medium {{ $statuses[$claim->status][1] ?? '' }}">{{ $statuses[$claim->status][0] ?? $claim->status }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <p class="text-xs text-gray-500">{{ __('Körjournalen används bara för att betala milersättning och som underlag för förmånsbil. Den används aldrig för att följa upp hur du arbetar, och NONOX läser aldrig din position. Du skriver själv in resorna.') }}</p>
</div>
