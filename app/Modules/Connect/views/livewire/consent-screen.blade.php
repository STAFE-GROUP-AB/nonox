<div class="card" x-data="{
    busy: false,
    error: null,
    async approve() {
        this.error = null;
        this.busy = true;
        try {
            if (@js($skipPasskey)) {
                await $wire.approve(null);
                return;
            }
            if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                return;
            }
            const options = await $wire.passkeyOptions();
            if (! options) { return; }
            const credential = await window.NonoxPasskeys.authenticate(options);
            await $wire.approve(credential);
        } catch (error) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(error) : String(error);
        } finally {
            this.busy = false;
        }
    },
}">
    <p class="step">{{ __('Anslut app') }}</p>
    <h1>{{ __(':app vill ansluta till NONOX', ['app' => $client?->name ?? __('En app')]) }}</h1>
    <p class="lead">
        {{ __('Appen får bara det som står nedan, bara i företaget du väljer, och aldrig mer än du själv får göra. Du kan koppla bort den när du vill under Anslutningar.') }}
        @if($redirectHost)
            {{ __('Efteråt skickas du tillbaka till :host.', ['host' => $redirectHost]) }}
        @endif
    </p>

    <h2 class="small" style="font-weight:600">{{ __('Appen ber om att få') }}</h2>
    <ul class="facts">
        @foreach($scopes as $scope)
            <li wire:key="scope-{{ $scope['key'] }}">
                <span>
                    {{ $scope['label'] }}
                    @unless($scope['held'])
                        <span class="muted small">{{ __('(du har inte själv den här behörigheten i företaget, så appen får den inte)') }}</span>
                    @endunless
                </span>
            </li>
        @endforeach
    </ul>

    @if($companies === [])
        <div class="problem">
            <p>{{ __('Du kan inte ansluta appen till något företag. Det krävs att du är ägare eller administratör, att modulen NONOX API är påslagen under Moduler och att företaget tillåter appen.') }}</p>
        </div>
    @else
        <div class="field" style="margin-top:1.75rem">
            <label for="companyId">{{ __('Företag') }}</label>
            <select id="companyId" wire:model.live="companyId">
                <option value="">{{ __('Välj företag') }}</option>
                @foreach($companies as $company)
                    <option value="{{ $company->id }}" wire:key="company-{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </select>
            @error('companyId') <div class="error" role="alert">{{ $message }}</div> @enderror
            @error('company') <div class="error" role="alert">{{ $message }}</div> @enderror
            @error('scopes') <div class="error" role="alert">{{ $message }}</div> @enderror
        </div>

        @if(! $hasPasskey && ! $skipPasskey)
            <div class="problem">
                <p>{{ __('För att ge en app åtkomst behöver du en passkey. Lägg till en under Profil och kom sedan tillbaka hit.') }}</p>
                <a class="link" href="{{ route('profile.show') }}">{{ __('Till Profil') }}</a>
            </div>
        @endif
    @endif

    <div class="actions">
        @if($companies !== [] && ($hasPasskey || $skipPasskey))
            <button class="button" type="button" x-on:click="approve()" x-bind:disabled="busy">
                <span x-show="! busy">{{ __('Godkänn med passkey') }}</span>
                <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
            </button>
        @endif
        <button class="link" type="button" wire:click="deny">{{ __('Avböj') }}</button>
    </div>

    <div class="error" role="alert" x-show="error" x-text="error" x-cloak></div>
    @error('passkey') <div class="error" role="alert">{{ $message }}</div> @enderror
</div>
