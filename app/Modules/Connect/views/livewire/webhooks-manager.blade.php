@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($statusLabels = ['pending' => __('Väntar'), 'delivered' => __('Levererad'), 'failed' => __('Misslyckades'), 'cancelled' => __('Inställd')])
<section class="space-y-3" x-data="{
    busy: false,
    error: null,
    async withPasskey(call) {
        this.error = null;
        this.busy = true;
        try {
            if (@js($skipPasskey)) {
                await call(null);
                return;
            }
            if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                return;
            }
            const options = await $wire.passkeyOptions();
            if (! options) { return; }
            await call(await window.NonoxPasskeys.authenticate(options));
        } catch (error) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(error) : String(error);
        } finally {
            this.busy = false;
        }
    },
}">
    <h2 class="text-base font-semibold text-gray-900">{{ __('Webhooks') }}</h2>
    <p class="text-sm text-gray-600">{{ __('En webhook berättar för ett annat program när något händer i företaget, till exempel att en faktura har betalats. NONOX skickar händelsen till en https-adress på internet och signerar den med en hemlighet som bara ni och mottagaren känner till. Händelsen innehåller id, nummer, datum och belopp, inga personuppgifter. Svarar mottagaren inte försöker NONOX igen i ungefär ett dygn och stänger sedan av webhooken och mejlar ägaren.') }}</p>

    @if($newSecret)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 space-y-2" x-data="{ copied: false }">
            <p class="text-sm font-semibold text-amber-900">{{ __('Kopiera hemligheten nu. Den visas inte igen.') }}</p>
            <code class="block break-all rounded-sm bg-white px-3 py-2 font-mono text-sm text-gray-900" data-webhook-secret>{{ $newSecret }}</code>
            <div class="flex gap-3">
                <button type="button" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="navigator.clipboard.writeText(@js($newSecret)); copied = true">
                    <span x-show="! copied">{{ __('Kopiera') }}</span><span x-show="copied" x-cloak>{{ __('Kopierad') }}</span>
                </button>
                <button type="button" class="text-sm font-medium text-gray-700 hover:underline" wire:click="forgetSecret">{{ __('Jag har sparat den') }}</button>
            </div>
        </div>
    @endif

    @if($webhooks->isEmpty())
        <p class="text-sm text-gray-500">{{ __('Inga webhooks.') }}</p>
    @endif

    @foreach($webhooks as $webhook)
        <div class="rounded-lg border border-gray-200 p-4 space-y-3" wire:key="webhook-{{ $webhook->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="break-all font-mono text-sm text-gray-900">{{ $webhook->url }}</p>
                    @if($webhook->description)<p class="text-sm text-gray-700">{{ $webhook->description }}</p>@endif
                    <p class="text-sm text-gray-500">
                        {{ __('Tillagd av :name', ['name' => $webhook->creator->name ?? '–']) }} ·
                        {{ __('Hemligheten börjar med') }} <span class="font-mono">{{ $webhook->secret_prefix }}…</span>
                    </p>
                    @if(! $webhook->isActive())
                        <p class="text-sm font-medium text-red-700">
                            @if($webhook->disabled_reason === 'failing')
                                {{ __('Avstängd :date: mottagaren svarade inte på ungefär ett dygn.', ['date' => $webhook->disabled_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}
                            @elseif($webhook->disabled_reason === 'module_disabled')
                                {{ __('Avstängd när NONOX Connect stängdes av.') }}
                            @else
                                {{ __('Avstängd.') }}
                            @endif
                        </p>
                    @endif
                </div>
                @if($canManage)
                    <div class="flex flex-wrap gap-3 text-sm">
                        @if($webhook->isActive())
                            <button type="button" class="font-medium text-gray-700 hover:underline" wire:click="setActive('{{ $webhook->id }}', false)">{{ __('Stäng av') }}</button>
                        @else
                            <button type="button" class="font-medium text-accent-700 hover:underline" wire:click="setActive('{{ $webhook->id }}', true)">{{ __('Slå på') }}</button>
                        @endif
                        @if($hasPasskey || $skipPasskey)
                            <button type="button" class="font-medium text-gray-700 hover:underline" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.rotateSecret('{{ $webhook->id }}', credential))">{{ __('Ny hemlighet') }}</button>
                        @endif
                        <button type="button" class="font-medium text-red-700 hover:underline" wire:click="deleteWebhook('{{ $webhook->id }}')" wire:confirm="{{ __('Ta bort webhooken? Inga fler händelser skickas och leveransloggen försvinner.') }}">{{ __('Ta bort') }}</button>
                    </div>
                @endif
            </div>
            <ul class="flex flex-wrap gap-2 text-xs">
                @foreach($webhook->events as $event)
                    <li class="rounded-sm bg-gray-100 px-2 py-0.5 text-gray-700" title="{{ $eventLabels[$event] ?? $event }}"><span class="font-mono">{{ $event }}</span></li>
                @endforeach
            </ul>
            @if($webhook->deliveries->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <caption class="sr-only">{{ __('Senaste leveranserna') }}</caption>
                        <thead class="text-left text-gray-600">
                            <tr>
                                <th class="px-2 py-1 font-medium">{{ __('Tid') }}</th>
                                <th class="px-2 py-1 font-medium">{{ __('Händelse') }}</th>
                                <th class="px-2 py-1 font-medium">{{ __('Status') }}</th>
                                <th class="px-2 py-1 font-medium text-right">{{ __('Försök') }}</th>
                                <th class="px-2 py-1 font-medium">{{ __('Senaste svar') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($webhook->deliveries as $delivery)
                                <tr wire:key="delivery-{{ $delivery->id }}">
                                    <td class="px-2 py-1 text-gray-700 tabular-nums">{{ $delivery->created_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                                    <td class="px-2 py-1 font-mono text-gray-700">{{ $delivery->event_type }}</td>
                                    <td class="px-2 py-1 {{ $delivery->status === 'failed' ? 'text-red-700' : 'text-gray-700' }}">{{ $statusLabels[$delivery->status] ?? $delivery->status }}</td>
                                    <td class="px-2 py-1 text-right text-gray-700 tabular-nums">{{ $delivery->attempts }}</td>
                                    <td class="px-2 py-1 text-gray-700">
                                        @if($delivery->last_status_code)
                                            HTTP {{ $delivery->last_status_code }}
                                        @elseif($delivery->last_error)
                                            <span class="font-mono">{{ $delivery->last_error }}</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    @if($canManage)
        <form x-on:submit.prevent="withPasskey((credential) => $wire.createWebhook(credential))" class="space-y-4 rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Lägg till webhook') }}</h3>
            <x-ui.field for="connect_webhook_url" :label="__('Adress (https)')" error="url">
                <input id="connect_webhook_url" type="url" class="{{ $input }} mt-1 font-mono" wire:model="url" maxlength="2000" placeholder="https://exempel.se/nonox/webhook">
            </x-ui.field>
            <x-ui.field for="connect_webhook_description" :label="__('Beskrivning (valfritt)')" error="description">
                <input id="connect_webhook_description" type="text" class="{{ $input }} mt-1" wire:model="description" maxlength="100" placeholder="{{ __('Till exempel Kassasystemet') }}">
            </x-ui.field>
            <fieldset class="space-y-1">
                <legend class="text-sm font-medium text-gray-700">{{ __('Händelser') }}</legend>
                @foreach($eventLabels as $event => $label)
                    <label class="flex items-start gap-2 text-sm" wire:key="event-{{ $event }}"><input type="checkbox" value="{{ $event }}" wire:model="events" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500"><span>{{ $label }} <span class="font-mono text-xs text-gray-500">{{ $event }}</span></span></label>
                @endforeach
                @error('events') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            </fieldset>
            @if(! $hasPasskey && ! $skipPasskey)
                <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att lägga till webhooks. Lägg till en under Profil.') }}</p>
            @else
                <button type="submit" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700" x-bind:disabled="busy">
                    <span x-show="! busy">{{ __('Lägg till med passkey') }}</span>
                    <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
                </button>
            @endif
        </form>
    @endif
    <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
    @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    @error('webhook') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
</section>
