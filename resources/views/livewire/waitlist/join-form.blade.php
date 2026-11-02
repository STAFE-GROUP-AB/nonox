<div class="wl-card">
    @if($done)
        <div role="status" aria-live="polite" data-waitlist-done>
            <p class="wl-eyebrow">Tack!</p>
            <h2 class="wl-h2" id="wl-tack" tabindex="-1" x-data x-init="$el.focus()">Du står i kö</h2>
            <p class="wl-lead">Vi har tagit emot din anmälan. Om adressen stämmer får du strax ett mejl som bekräftar att du står i kö.</p>
            <h3 class="wl-h3">Det här händer nu</h3>
            <ol class="wl-steps">
                <li>Vi läser dina svar och hör av oss om vi undrar något.</li>
                <li>När du är godkänd får du ett mejl med en länk till inloggningen.</li>
                <li>Du loggar in med din e-postadress och en kod som vi mejlar. Inga lösenord.</li>
                <li>Första gången lägger du upp ditt företag. Det tar några minuter.</li>
            </ol>
            <p class="wl-note">NONOX är i öppen beta. Skarp lansering av version 1.0 sker den 1 januari 2027, och det du lägger in under betan ligger kvar.</p>
            <p class="wl-small">Ångrar du dig, eller vill du att vi tar bort dina uppgifter? Svara på bekräftelsemejlet så tar vi bort dem. <a href="{{ route('legal.privacy') }}">Så behandlar vi dina personuppgifter</a></p>
        </div>
    @else
        <form wire:submit="submit" novalidate aria-describedby="wl-required">
            <p class="wl-small" id="wl-required">Fält markerade med <span aria-hidden="true">*</span><span class="wl-sr">stjärna</span> måste fyllas i.</p>

            @error('form') <div class="wl-alert" role="alert">{{ $message }}</div> @enderror

            <fieldset class="wl-fieldset">
                <legend class="wl-legend">Om dig</legend>

                <div class="wl-field">
                    <label for="wl-name">Ditt namn <span aria-hidden="true">*</span></label>
                    <input id="wl-name" type="text" wire:model="name" autocomplete="name" required aria-required="true" @error('name') aria-invalid="true" aria-describedby="wl-name-error" @enderror>
                    @error('name') <p class="wl-error" id="wl-name-error">{{ $message }}</p> @enderror
                </div>

                <div class="wl-field">
                    <label for="wl-email">E-postadress <span aria-hidden="true">*</span></label>
                    <input id="wl-email" type="email" wire:model="email" autocomplete="email" inputmode="email" required aria-required="true" aria-describedby="wl-email-hint @error('email') wl-email-error @enderror" @error('email') aria-invalid="true" @enderror>
                    <p class="wl-hint" id="wl-email-hint">Hit skickar vi besked, och med den loggar du in när du är godkänd.</p>
                    @error('email') <p class="wl-error" id="wl-email-error">{{ $message }}</p> @enderror
                </div>

                <div class="wl-field">
                    <label for="wl-phone">Telefon <span class="wl-optional">(valfritt)</span></label>
                    <input id="wl-phone" type="tel" wire:model="phone" autocomplete="tel" inputmode="tel" @error('phone') aria-invalid="true" aria-describedby="wl-phone-error" @enderror>
                    @error('phone') <p class="wl-error" id="wl-phone-error">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            <fieldset class="wl-fieldset">
                <legend class="wl-legend">Om företaget</legend>

                <div class="wl-field">
                    <label for="wl-company">Företagets namn <span aria-hidden="true">*</span></label>
                    <input id="wl-company" type="text" wire:model="company_name" autocomplete="organization" required aria-required="true" @error('company_name') aria-invalid="true" aria-describedby="wl-company-error" @enderror>
                    @error('company_name') <p class="wl-error" id="wl-company-error">{{ $message }}</p> @enderror
                </div>

                <div class="wl-field">
                    <label for="wl-orgnr">Organisationsnummer <span class="wl-optional">(valfritt)</span></label>
                    <input id="wl-orgnr" type="text" wire:model="org_number" inputmode="numeric" autocomplete="off" aria-describedby="wl-orgnr-hint @error('org_number') wl-orgnr-error @enderror" @error('org_number') aria-invalid="true" @enderror>
                    <p class="wl-hint" id="wl-orgnr-hint">Tio siffror, till exempel 556123-4567. Lämna tomt om företaget inte är registrerat än.</p>
                    @error('org_number') <p class="wl-error" id="wl-orgnr-error">{{ $message }}</p> @enderror
                </div>

                <div class="wl-row">
                    <div class="wl-field">
                        <label for="wl-industry">Bransch <span aria-hidden="true">*</span></label>
                        <select id="wl-industry" wire:model="industry" required aria-required="true" @error('industry') aria-invalid="true" aria-describedby="wl-industry-error" @enderror>
                            <option value="">Välj bransch</option>
                            @foreach($industries as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('industry') <p class="wl-error" id="wl-industry-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="wl-field">
                        <label for="wl-employees">Antal anställda <span aria-hidden="true">*</span></label>
                        <select id="wl-employees" wire:model="employees" required aria-required="true" @error('employees') aria-invalid="true" aria-describedby="wl-employees-error" @enderror>
                            <option value="">Välj antal</option>
                            @foreach($employeeRanges as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('employees') <p class="wl-error" id="wl-employees-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="wl-field">
                    <label for="wl-system">Vilket system använder ni i dag? <span aria-hidden="true">*</span></label>
                    <select id="wl-system" wire:model="current_system" required aria-required="true" @error('current_system') aria-invalid="true" aria-describedby="wl-system-error" @enderror>
                        <option value="">Välj system</option>
                        @foreach($systems as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('current_system') <p class="wl-error" id="wl-system-error">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            <fieldset class="wl-fieldset">
                <legend class="wl-legend">Berätta gärna</legend>

                <div class="wl-field">
                    <label for="wl-test">Vad vill du testa i NONOX? <span class="wl-optional">(valfritt)</span></label>
                    <textarea id="wl-test" wire:model="wants_to_test" rows="3" maxlength="2000" @error('wants_to_test') aria-invalid="true" aria-describedby="wl-test-error" @enderror></textarea>
                    @error('wants_to_test') <p class="wl-error" id="wl-test-error">{{ $message }}</p> @enderror
                </div>

                <div class="wl-field">
                    <label for="wl-why">Varför vill du byta system? <span class="wl-optional">(valfritt)</span></label>
                    <textarea id="wl-why" wire:model="why_switch" rows="3" maxlength="2000" @error('why_switch') aria-invalid="true" aria-describedby="wl-why-error" @enderror></textarea>
                    @error('why_switch') <p class="wl-error" id="wl-why-error">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            {{-- Honeypot: off screen and out of the tab order; people never see it. --}}
            <div class="wl-hp" aria-hidden="true">
                <label for="wl-website">Lämna det här fältet tomt</label>
                <input id="wl-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="wl-field">
                <div class="wl-check">
                    <input id="wl-consent" type="checkbox" wire:model="consent" required aria-required="true" @error('consent') aria-invalid="true" aria-describedby="wl-consent-error" @enderror>
                    <label for="wl-consent">Jag har läst <a href="{{ route('legal.privacy') }}">hur NONOX behandlar mina personuppgifter</a> och vill stå i kö. <span aria-hidden="true">*</span></label>
                </div>
                @error('consent') <p class="wl-error" id="wl-consent-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="wl-button" wire:loading.attr="disabled" wire:target="submit">
                <span wire:loading.remove wire:target="submit">Ställ mig i kö</span>
                <span wire:loading wire:target="submit">Skickar…</span>
            </button>
        </form>
    @endif
</div>
