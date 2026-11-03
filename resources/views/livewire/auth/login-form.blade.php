<div class="card" @if($firmBrand) style="border-top: 4px solid {{ $firmBrand->brand()->color }}" @endif>
    @if($firmBrand)
        {{-- Reached from an invitation to a client of an accounting firm (ADR-030). --}}
        <div class="firm-brand" style="display:flex;align-items:center;gap:12px;margin-bottom:18px" data-firm-brand>
            @if($firmBrand->signedLogoUrl())
                <img src="{{ $firmBrand->signedLogoUrl() }}" alt="{{ $firmBrand->firmName }}" style="max-height:44px;max-width:160px;object-fit:contain">
            @endif
            <p style="margin:0;font-size:13px;font-weight:600;color:{{ $firmBrand->brand()->accentText() }}">{{ $firmBrand->title() }}</p>
        </div>
    @endif
    @if($step === 'email')
        <h1>Logga in</h1>
        <p class="lead">Skriv din e-postadress så skickar vi en kod. Inga lösenord att komma ihåg.</p>

        <form wire:submit="sendCode" novalidate>
            <div class="field">
                <label for="email">E-postadress</label>
                <input id="email" type="email" wire:model="email" autocomplete="email" autofocus @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <div class="error" id="email-error" role="alert">{{ $message }}</div> @enderror
            </div>

            @if($autofill)
                <div class="notice">Systemet körs på din egen dator, så koden fylls i åt dig.</div>
            @endif

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled" wire:target="sendCode">
                    <span wire:loading.remove wire:target="sendCode">Skicka kod</span>
                    <span wire:loading wire:target="sendCode">Skickar…</span>
                </button>
            </div>
        </form>
        @include('livewire.auth.partials.join-waitlist')
        @if(config('app.landing.hosted'))
            <p class="muted small" style="margin-top:14px"><a href="{{ route('legal.privacy') }}">{{ __('Så behandlar vi dina personuppgifter') }}</a></p>
        @endif
    @elseif($step === 'code')
        <h1>Kolla din e-post</h1>
        @if($registrationOpen)
            <p class="lead" id="code-help">Vi har skickat en kod med åtta siffror till <strong>{{ $email }}</strong>. Skriv in den här. Har du inget konto skapar vi ett när koden stämmer.</p>
        @else
            <p class="lead" id="code-help">Om <strong>{{ $email }}</strong> har ett konto eller en inbjudan har vi skickat en kod med åtta siffror dit. Skriv in den här.</p>
        @endif

        <form wire:submit="verify" novalidate>
            <div class="field">
                <label for="code">Kod</label>
                <input id="code" class="code-input" type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" autofocus aria-describedby="code-help @error('code') code-error @enderror" @error('code') aria-invalid="true" @enderror>
                @error('code') <div class="error" id="code-error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled" wire:target="verify">Logga in</button>
                <button class="link" type="button" wire:click="startOver">Fel adress eller ingen kod? Börja om</button>
            </div>
        </form>
        @include('livewire.auth.partials.join-waitlist')
    @elseif($step === 'passkey' || $step === 'passkey-register')
        @include('livewire.auth.partials.passkey-step', ['step' => $step])
        <div class="actions">
            <button class="link" type="button" wire:click="startOver">{{ __('Avbryt och börja om') }}</button>
        </div>
    @else
        <h1>Välkommen!</h1>
        <p class="lead">{{ $registrationOpen ? 'Du har inget konto än. Vad heter du? Sedan lägger du upp ditt företag.' : 'Du är inbjuden men har inget konto än. Vad heter du?' }}</p>

        <form wire:submit="createAccount" novalidate>
            <div class="field">
                <label for="name">Ditt namn</label>
                <input id="name" type="text" wire:model="name" autocomplete="name" autofocus @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <div class="error" id="name-error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled" wire:target="createAccount">Skapa konto</button>
            </div>
        </form>
    @endif
</div>
