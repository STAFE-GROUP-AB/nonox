<x-action-section>
    <x-slot name="title">{{ __('Passkeys') }}</x-slot>
    <x-slot name="description">{{ __('Bekräfta inloggningen med fingeravtryck, ansiktsigenkänning eller skärmlås, utöver koden i mejlet.') }}</x-slot>
    <x-slot name="content">
        <div x-data="{
            busy: false,
            error: null,
            async add() {
                this.error = null;
                if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                    this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                    return;
                }
                this.busy = true;
                try {
                    const options = await $wire.registrationOptions();
                    if (! options) { return; }
                    const credential = await window.NonoxPasskeys.register(options);
                    await $wire.register(credential);
                } catch (error) {
                    this.error = window.NonoxPasskeys.errorMessage(error);
                } finally {
                    this.busy = false;
                }
            },
        }">
            @if($required)
                <p class="text-sm text-gray-600">{{ __('Du måste logga in med passkey, eftersom du kan se känsliga personuppgifter som sjukfrånvaro eller fullständiga personnummer, eller för att ett av dina företag kräver det. Lägg gärna till en passkey på mer än en enhet, så att du kommer in även om du tappar bort en.') }}</p>
            @else
                <p class="text-sm text-gray-600">{{ __('När du har lagt till en passkey frågar NONOX efter den varje gång du loggar in, efter koden i mejlet.') }}</p>
            @endif

            @if($passkeys->isEmpty())
                <p class="mt-4 text-sm text-gray-600">{{ __('Du har inga passkeys ännu.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100 rounded-md border border-gray-200">
                    @foreach($passkeys as $passkey)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="passkey-{{ $passkey->id }}">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 break-words">{{ $passkey->name }}@if($passkey->authenticator) <span class="text-sm font-normal text-gray-500">· {{ $passkey->authenticator }}</span>@endif</div>
                                <div class="text-sm text-gray-500 tabular-nums">
                                    {{ __('Tillagd :date', ['date' => $passkey->created_at?->timezone(config('app.timezone'))->format('Y-m-d')]) }}
                                    ·
                                    {{ $passkey->last_used_at ? __('Senast använd :date', ['date' => $passkey->last_used_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]) : __('Inte använd ännu') }}
                                </div>
                            </div>
                            <button type="button" class="text-sm text-red-600 underline" wire:click="remove({{ $passkey->id }})" wire:confirm="{{ __('Ta bort passkeyn :name? Enheten kan inte längre användas för att logga in.', ['name' => $passkey->name]) }}" wire:loading.attr="disabled">{{ __('Ta bort') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form class="mt-5 flex flex-wrap items-end gap-3" x-on:submit.prevent="add()" novalidate>
                <div class="w-full sm:w-64">
                    <x-label for="passkey_name" value="{{ __('Namn på enheten') }}" />
                    <x-input id="passkey_name" type="text" class="mt-1 block w-full" wire:model="name" maxlength="60" placeholder="{{ __('Till exempel MacBook eller iPhone') }}" autocomplete="off" />
                </div>
                <x-button type="submit" x-bind:disabled="busy">
                    <span x-show="! busy">{{ __('Lägg till passkey') }}</span>
                    <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
                </x-button>
            </form>
            <x-input-error for="name" class="mt-2" />
            <x-input-error for="passkey" class="mt-2" />
            <p class="mt-2 text-sm text-red-600" role="alert" x-show="error" x-text="error" x-cloak></p>
        </div>
    </x-slot>
</x-action-section>
