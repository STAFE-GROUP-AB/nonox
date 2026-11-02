{{-- The passkey step (ADR-029), in the sign-in form and on the passkey page. Needs resources/js/passkeys.js on the page. --}}
<div x-data="{
    busy: false,
    error: null,
    async run(start, finish) {
        this.error = null;
        if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
            return;
        }
        this.busy = true;
        try {
            const options = await $wire[start]();
            if (! options) { return; }
            const credential = await finish(options);
            await $wire[@js($step === 'passkey' ? 'confirmPasskey' : 'registerFirstPasskey')](credential);
        } catch (error) {
            this.error = window.NonoxPasskeys.errorMessage(error);
        } finally {
            this.busy = false;
        }
    },
}">
    @if($step === 'passkey')
        <h1>{{ __('Bekräfta med passkey') }}</h1>
        <p class="lead">{{ __('Koden stämde. Bekräfta nu att det är du med din passkey, till exempel fingeravtryck, ansiktsigenkänning eller telefonens skärmlås.') }}</p>

        <div class="actions">
            <button class="button wide" type="button" x-on:click="run('passkeyAssertionOptions', (options) => window.NonoxPasskeys.authenticate(options))" x-bind:disabled="busy">
                <span x-show="! busy">{{ __('Använd passkey') }}</span>
                <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
            </button>
        </div>
        <p class="muted small" style="margin-top:14px">{{ __('Har du tappat bort alla enheter med passkeys? Be en ägare eller administratör i ditt företag att återställa dina passkeys under Inställningar för företaget.') }}</p>
    @else
        <h1>{{ __('Lägg till en passkey') }}</h1>
        <p class="lead">{{ __('Du kan se känsliga personuppgifter, till exempel sjukfrånvaro eller fullständiga personnummer, eller så kräver ett av dina företag passkey för alla. Då räcker inte koden i mejlet: den som kommer åt din e-post skulle komma åt uppgifterna. Med en passkey behövs också din telefon eller dator.') }}</p>
        <p class="lead">{{ __('En passkey sparas på din enhet och låses upp med fingeravtryck, ansiktsigenkänning eller skärmlås. Inga lösenord.') }}</p>

        <form x-on:submit.prevent="run('passkeyRegistrationOptions', (options) => window.NonoxPasskeys.register(options))" novalidate>
            <div class="field">
                <label for="passkeyName">{{ __('Namn på enheten') }}</label>
                <input id="passkeyName" type="text" wire:model="passkeyName" maxlength="60" placeholder="{{ __('Till exempel MacBook eller iPhone') }}" autocomplete="off" autofocus>
                @error('passkeyName') <div class="error" role="alert">{{ $message }}</div> @enderror
                @error('name') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>
            <div class="actions">
                <button class="button wide" type="submit" x-bind:disabled="busy">
                    <span x-show="! busy">{{ __('Skapa passkey och fortsätt') }}</span>
                    <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
                </button>
            </div>
        </form>
    @endif

    <div class="error" role="alert" x-show="error" x-text="error" x-cloak></div>
    @error('passkey') <div class="error" role="alert">{{ $message }}</div> @enderror
</div>
