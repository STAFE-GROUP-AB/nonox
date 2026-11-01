<div class="card">
    @if($step === 'email')
        <h1>Logga in</h1>
        <p class="lead">Skriv din e-postadress så skickar vi en kod. Inga lösenord att komma ihåg. Är du ny skapas ett konto åt dig.</p>

        <form wire:submit="sendCode" novalidate>
            <div class="field">
                <label for="email">E-postadress</label>
                <input id="email" type="email" wire:model="email" autocomplete="email" autofocus>
                @error('email') <div class="error">{{ $message }}</div> @enderror
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
        @if(config('app.landing.hosted'))
            <p class="muted small" style="margin-top:14px">{{ __('Har du inget konto skapas ett när du loggar in.') }} <a href="{{ route('legal.privacy') }}">{{ __('Så behandlar vi dina personuppgifter') }}</a></p>
        @endif
    @elseif($step === 'code')
        <h1>Kolla din e-post</h1>
        <p class="lead">Vi har skickat en kod med åtta siffror till <strong>{{ $email }}</strong>. Skriv in den här.</p>

        <form wire:submit="verify" novalidate>
            <div class="field">
                <label for="code">Kod</label>
                <input id="code" class="code-input" type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" autofocus>
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled" wire:target="verify">Logga in</button>
                <button class="link" type="button" wire:click="startOver">Fel adress eller ingen kod? Börja om</button>
            </div>
        </form>
    @else
        <h1>Välkommen!</h1>
        <p class="lead">Du är ny här. Vad heter du?</p>

        <form wire:submit="createAccount" novalidate>
            <div class="field">
                <label for="name">Ditt namn</label>
                <input id="name" type="text" wire:model="name" autocomplete="name" autofocus>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled" wire:target="createAccount">Skapa konto</button>
            </div>
        </form>
    @endif
</div>
