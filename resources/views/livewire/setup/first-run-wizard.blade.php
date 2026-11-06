<div class="card">
    <div class="progress" aria-hidden="true">
        <i class="on"></i>
        <i class="{{ $step !== 'company' ? 'on' : '' }}"></i>
        <i class="{{ $step === 'code' ? 'on' : '' }}"></i>
    </div>

    @if($step === 'company')
        <div class="step">Steg 1 av 3</div>
        <h1>Välkommen! Vi börjar med ditt företag.</h1>
        <p class="lead">Det tar en minut. Du kan ändra allt senare.</p>

        <form wire:submit="toPerson" novalidate>
            <div class="field">
                <label for="company_name">Vad heter företaget?</label>
                <input id="company_name" type="text" wire:model="company_name" autocomplete="organization" autofocus>
                @error('company_name') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="org_number">Organisationsnummer</label>
                <input id="org_number" type="text" wire:model="org_number" inputmode="numeric" placeholder="556123-4567">
                <span class="hint">Tio siffror. Det står på registreringsbeviset från Bolagsverket. Har du enskild firma är det ditt personnummer.</span>
                @error('org_number') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="legal_form">Vilken sorts företag är det?</label>
                <select id="legal_form" wire:model.live="legal_form">
                    @foreach($legalForms as $form)
                        <option value="{{ $form->value }}">{{ $form->label() }}</option>
                    @endforeach
                </select>
            </div>

            <fieldset class="field" style="border: 0; padding: 0; margin: 0 0 1.25rem;">
                <legend>Redovisar företaget moms?</legend>
                <div class="choices">
                    <label class="choice"><input type="radio" wire:model="vat" value="QUARTERLY"> Ja, varje kvartal</label>
                    <label class="choice"><input type="radio" wire:model="vat" value="MONTHLY"> Ja, varje månad</label>
                    <label class="choice"><input type="radio" wire:model="vat" value="YEARLY"> Ja, en gång per år</label>
                    <label class="choice"><input type="radio" wire:model="vat" value="NONE"> Nej</label>
                </div>
                <span class="hint">Osäker? Det står i beslutet från Skatteverket. Holdingbolag är ofta inte momsregistrerade.</span>
            </fieldset>

            <div class="field">
                <label for="fiscal_year_start_month">När börjar räkenskapsåret?</label>
                <select id="fiscal_year_start_month" wire:model.live="fiscal_year_start_month">
                    @foreach($months as $number => $month)
                        <option value="{{ $number }}">1 {{ $month }}</option>
                    @endforeach
                </select>
                @if($calendarYearOnly)
                    <span class="hint">En enskild firma har alltid kalenderår, alltså 1 januari.</span>
                @else
                    <span class="hint">För de flesta företag är det 1 januari.</span>
                @endif
                @error('fiscal_year_start_month') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            @if($askPartners)
                <div class="field">
                    <span class="hint">Ett handels- eller kommanditbolag där en fysisk person beskattas för hela eller en del av bolagets inkomst ska ha kalenderår som räkenskapsår.</span>
                    <label class="choice"><input type="checkbox" wire:model="calendar_year_exempt"> Ingen fysisk person beskattas för någon del av bolagets inkomst.</label>
                    @error('calendar_year_exempt') <div class="error" role="alert">{{ $message }}</div> @enderror
                </div>
            @endif

            <div class="field">
                <label for="reporting_framework">Vilket regelverk följer bokslutet?</label>
                <select id="reporting_framework" wire:model.live="reporting_framework">
                    @foreach($frameworks as $framework)
                        <option value="{{ $framework->value }}">{{ $framework->label() }}</option>
                    @endforeach
                </select>
                @if($frameworkRefusal)
                    <div class="error" role="alert">{{ $frameworkRefusal }}</div>
                @else
                    @if($frameworkConditions)
                        <span class="hint" style="white-space: pre-line;">{{ $frameworkConditions }}</span>
                        <label class="choice"><input type="checkbox" wire:model="framework_confirmed"> Företaget uppfyller villkoren ovan. Är du osäker, fråga din redovisningskonsult innan du väljer. Väljer du K3 tar NONOX ännu inte fram årsredovisningen; bokföring, bokslut och inkomstdeklaration fungerar som vanligt.</label>
                    @endif
                    @error('reporting_framework') <div class="error" role="alert">{{ $message }}</div> @enderror
                @endif
                @error('framework_confirmed') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled">Fortsätt</button>
            </div>
        </form>
    @elseif($step === 'person')
        <div class="step">Steg 2 av 3</div>
        <h1>Och du?</h1>
        <p class="lead">Du blir ägare till <strong>{{ $company_name }}</strong> i systemet.</p>

        <form wire:submit="sendCode" novalidate>
            <div class="field">
                <label for="name">Ditt namn</label>
                <input id="name" type="text" wire:model="name" autocomplete="name" autofocus>
                @error('name') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="email">Din e-postadress</label>
                <input id="email" type="email" wire:model="email" autocomplete="email">
                <span class="hint">Det är med den här adressen du loggar in. Det finns inga lösenord: varje gång får du en kod till mejlen.</span>
                @error('email') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled" wire:target="sendCode">
                    <span wire:loading.remove wire:target="sendCode">Skicka kod till min e-post</span>
                    <span wire:loading wire:target="sendCode">Skickar…</span>
                </button>
                <button class="link" type="button" wire:click="backToCompany">Tillbaka</button>
            </div>
        </form>
    @else
        <div class="step">Steg 3 av 3</div>
        <h1>Kolla din e-post</h1>
        <p class="lead">Vi har skickat en kod med åtta siffror till <strong>{{ $email }}</strong>. Skriv in den här.</p>

        @if($autofill)
            <div class="notice">Systemet körs på din egen dator, så koden fylls i åt dig.</div>
        @endif

        <form wire:submit="finish" novalidate>
            <div class="field">
                <label for="code">Kod</label>
                <input id="code" class="code-input" type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" autofocus>
                @error('code') <div class="error" role="alert">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button class="button wide" type="submit" wire:loading.attr="disabled">Klart, öppna mitt företag</button>
                <button class="link" type="button" wire:click="backToCompany">Börja om</button>
            </div>
        </form>
    @endif
</div>
