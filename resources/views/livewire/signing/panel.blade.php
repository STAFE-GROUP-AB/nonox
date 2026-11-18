@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = config('app.timezone');
@endphp

<x-ui.card :title="__('Signering')" :description="$isQuote ? ($crm ? __('Skicka offerten för signering med namnteckning. När kunden har signerat blir offerten en order, och affären vinns.') : __('Skicka offerten för signering med namnteckning. När kunden har signerat blir offerten en order.')) : __('Skicka avtalet för signering med namnteckning. Kunden behöver inget konto.')" data-testid="crm-signing-panel">
    @if($canSend && $open && $several)
        <form wire:submit="sendSeveral" class="mt-4 space-y-6" data-testid="signing-several-form">
            <fieldset>
                <legend class="text-sm font-semibold text-gray-900">{{ __('Undertecknare') }}</legend>
                <p class="mt-1 text-sm text-gray-600">{{ __('Undertecknare med samma steg signerar samtidigt. Nästa steg får sin länk först när alla i steget före har signerat. Högst :count undertecknare.', ['count' => $maxSigners]) }}</p>
                <x-input-error for="signers" class="mt-2" />
                <ol class="mt-3 space-y-3">
                    @foreach($signers as $index => $signer)
                        <li wire:key="signer-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_7rem_auto] sm:items-end">
                            <x-ui.field for="signer-email-{{ $index }}" :label="__('E-post')" error="signers.{{ $index }}.email">
                                <input id="signer-email-{{ $index }}" type="email" class="{{ $input }} mt-1" wire:model="signers.{{ $index }}.email" autocomplete="off" required>
                            </x-ui.field>
                            <x-ui.field for="signer-step-{{ $index }}" :label="__('Steg')" error="signers.{{ $index }}.step">
                                <select id="signer-step-{{ $index }}" class="{{ $input }} mt-1" wire:model="signers.{{ $index }}.step">
                                    @for($step = 1; $step <= $maxStep; $step++)
                                        <option value="{{ $step }}">{{ $step }}</option>
                                    @endfor
                                </select>
                            </x-ui.field>
                            <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeSigner({{ $index }})">{{ __('Ta bort') }}</button>
                            <div class="grid gap-3 sm:col-span-3 sm:grid-cols-2">
                                <x-ui.field for="signer-check-{{ $index }}" :label="__('Kontroll av undertecknaren')" error="signers.{{ $index }}.verification">
                                    <select id="signer-check-{{ $index }}" class="{{ $input }} mt-1" wire:model.live="signers.{{ $index }}.verification">
                                        @foreach($checks as $check)
                                            <option value="{{ $check->value }}">{{ $check->label() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                @if(($signer['verification'] ?? 'NONE') === 'ACCESS_CODE')
                                    <x-ui.field for="signer-access-{{ $index }}" :label="__('Åtkomstkod')" error="signers.{{ $index }}.access_code" :hint="__('Minst 6 tecken, eller 8 om koden bara har siffror. Använd inte personnummer, telefonnummer eller annat som går att ta reda på. Lämna koden till undertecknaren på annat sätt, till exempel per telefon; NONOX skickar den aldrig.')">
                                        <div class="mt-1 flex gap-2">
                                            <input id="signer-access-{{ $index }}" type="text" class="{{ $input }}" wire:model="signers.{{ $index }}.access_code" autocomplete="off" maxlength="64" required>
                                            <button type="button" class="min-h-11 shrink-0 rounded-lg px-3 text-sm font-medium ring-1 ring-gray-300 hover:bg-gray-50 sm:min-h-0" wire:click="suggestSignerCode({{ $index }})">{{ __('Föreslå kod') }}</button>
                                        </div>
                                    </x-ui.field>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
                @if(count($signers) < $maxSigners)
                    <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="addSigner">{{ __('Lägg till undertecknare') }}</button>
                @endif
            </fieldset>

            <fieldset>
                <legend class="text-sm font-semibold text-gray-900">{{ __('Kopiemottagare') }}</legend>
                <p class="mt-1 text-sm text-gray-600">{{ __('Kopiemottagare signerar inte. De får dokumentet och signeringsbevisen när alla har signerat. Högst :count.', ['count' => $maxCopies]) }}</p>
                <x-input-error for="copies" class="mt-2" />
                @if($copies !== [])
                    <ul class="mt-3 space-y-3">
                        @foreach($copies as $index => $copy)
                            <li wire:key="copy-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <x-ui.field for="copy-email-{{ $index }}" :label="__('E-post')" error="copies.{{ $index }}.email">
                                    <input id="copy-email-{{ $index }}" type="email" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.email" autocomplete="off" required>
                                </x-ui.field>
                                <x-ui.field for="copy-name-{{ $index }}" :label="__('Namn (frivilligt)')" error="copies.{{ $index }}.name">
                                    <input id="copy-name-{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.name" maxlength="120" autocomplete="off">
                                </x-ui.field>
                                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeCopy({{ $index }})">{{ __('Ta bort') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if(count($copies) < $maxCopies)
                    <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="addCopy">{{ __('Lägg till kopiemottagare') }}</button>
                @endif
                @if($copies !== [])
                    <label class="mt-3 flex items-start gap-3 text-sm">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="notifyCopies">
                        <span>{{ __('Meddela kopiemottagarna redan nu att dokumentet är skickat') }}</span>
                    </label>
                @endif
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="signing-expires-several" :label="__('Sista dag att signera (frivilligt)')" error="expires_on" :hint="$isQuote ? __('Annars offertens giltighetsdag, eller 30 dagar. Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays]) : __('Annars 30 dagar. Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays])">
                    <input id="signing-expires-several" type="date" class="{{ $input }} mt-1" wire:model="expiresOn">
                </x-ui.field>
                @if($deals->isNotEmpty())
                    <x-ui.field for="signing-deal-several" :label="__('Affär som vinns när alla har signerat')" error="deal_id">
                        <select id="signing-deal-several" class="{{ $input }} mt-1" wire:model="dealId">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($deals as $deal)
                                <option value="{{ $deal->id }}">{{ $deal->title }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if($canInvoice)
                    <label class="flex items-start gap-3 self-end text-sm">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="createInvoice">
                        <span>{{ __('Gör också ett fakturautkast när alla har signerat') }}</span>
                    </label>
                @endif
            </div>
            <fieldset data-testid="signing-reminder-settings">
                <legend class="text-sm font-semibold text-gray-900">{{ __('Påminnelser') }}</legend>
                <label class="mt-2 flex items-start gap-3 text-sm">
                    <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model.live="autoReminders">
                    <span>{{ __('Påminn de som ska signera automatiskt, mellan 08 och 18 på dagtid. Högst :count påminnelser per person.', ['count' => $maxReminders]) }}</span>
                </label>
                @if($autoReminders)
                    <x-ui.field for="signing-reminder-days-several" :label="__('Hur ofta')" error="reminder_days" class="mt-3 max-w-xs">
                        <select id="signing-reminder-days-several" class="{{ $input }} mt-1" wire:model="reminderDays">
                            @foreach($intervals as $days)
                                <option value="{{ $days }}">{{ $days === 1 ? __('Varje dag') : __('Var :days:e dag', ['days' => $days]) }}</option>
                            @endforeach
                            <option value="single">{{ __('En gång, två dagar före sista dag') }}</option>
                        </select>
                    </x-ui.field>
                @endif
            </fieldset>
            <x-input-error for="document" />
            <div>
                <div class="flex flex-wrap items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Skicka till undertecknarna') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="oneSigner">{{ __('En undertecknare') }}</button>
                </div>
                <p class="mt-2 text-sm text-gray-600">{{ $isQuote ? __('Offerten blir en order först när alla har signerat. Om någon avböjer avslutas signeringen för alla. En tidigare länk för samma dokument slutar gälla.') : __('Avtalet är signerat först när alla har signerat. Om någon avböjer avslutas signeringen för alla. En tidigare länk för samma dokument slutar gälla.') }}</p>
            </div>
        </form>
    @elseif($canSend && $open)
        <form wire:submit="send" class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-ui.field for="signing-email" :label="__('E-post till den som ska signera')" error="email">
                <input id="signing-email" type="email" class="{{ $input }} mt-1" wire:model="email" autocomplete="off" required>
            </x-ui.field>
            <x-ui.field for="signing-expires" :label="__('Sista dag att signera (frivilligt)')" error="expires_on" :hint="$isQuote ? __('Annars offertens giltighetsdag, eller 30 dagar. Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays]) : __('Annars 30 dagar. Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays])">
                <input id="signing-expires" type="date" class="{{ $input }} mt-1" wire:model="expiresOn">
            </x-ui.field>
            <x-ui.field for="signing-check" :label="__('Kontroll av undertecknaren')" error="verification" :hint="__('En kod minskar risken att någon annan använder en vidarebefordrad länk. Underskriften blir fortfarande en enkel elektronisk underskrift.')">
                <select id="signing-check" class="{{ $input }} mt-1" wire:model.live="verification">
                    @foreach($checks as $check)
                        <option value="{{ $check->value }}">{{ $check->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            @if($verification === 'ACCESS_CODE')
                <x-ui.field for="signing-access-code" :label="__('Åtkomstkod')" error="access_code" :hint="__('Minst 6 tecken, eller 8 om koden bara har siffror. Använd inte personnummer, telefonnummer eller annat som går att ta reda på. Lämna koden till undertecknaren på annat sätt, till exempel per telefon; NONOX skickar den aldrig.')">
                    <div class="mt-1 flex gap-2">
                        <input id="signing-access-code" type="text" class="{{ $input }}" wire:model="accessCode" autocomplete="off" maxlength="64" required>
                        <button type="button" class="min-h-11 shrink-0 rounded-lg px-3 text-sm font-medium ring-1 ring-gray-300 hover:bg-gray-50 sm:min-h-0" wire:click="suggestCode">{{ __('Föreslå kod') }}</button>
                    </div>
                </x-ui.field>
            @endif
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
            <fieldset class="sm:col-span-2" data-testid="signing-reminder-settings">
                <legend class="text-sm font-semibold text-gray-900">{{ __('Påminnelser') }}</legend>
                <label class="mt-2 flex items-start gap-3 text-sm">
                    <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model.live="autoReminders">
                    <span>{{ __('Påminn de som ska signera automatiskt, mellan 08 och 18 på dagtid. Högst :count påminnelser per person.', ['count' => $maxReminders]) }}</span>
                </label>
                @if($autoReminders)
                    <x-ui.field for="signing-reminder-days" :label="__('Hur ofta')" error="reminder_days" class="mt-3 max-w-xs">
                        <select id="signing-reminder-days" class="{{ $input }} mt-1" wire:model="reminderDays">
                            @foreach($intervals as $days)
                                <option value="{{ $days }}">{{ $days === 1 ? __('Varje dag') : __('Var :days:e dag', ['days' => $days]) }}</option>
                            @endforeach
                            <option value="single">{{ __('En gång, två dagar före sista dag') }}</option>
                        </select>
                    </x-ui.field>
                @endif
            </fieldset>
            <x-input-error for="document" class="sm:col-span-2" />
            <div class="sm:col-span-2">
                <div class="flex flex-wrap items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Skicka för signering') }}</x-button>
                    @if($wizardUrl)
                        <a href="{{ $wizardUrl }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" data-testid="signing-several-wizard">{{ __('Fler undertecknare') }}</a>
                    @else
                        <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="severalSigners">{{ __('Fler undertecknare') }}</button>
                    @endif
                </div>
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
                @php($envelope = $signing->envelope)
                @php($inEnvelope = $envelope !== null && $envelope->signers_count > 1)
                @if($inEnvelope && ($loop->first || $requests[$loop->index - 1]->envelope_id !== $signing->envelope_id))
                    @php($envelopeStatus = $envelope->effectiveStatus())
                    <li wire:key="envelope-{{ $envelope->id }}" class="flex flex-wrap items-center gap-3 pt-4" data-testid="signing-envelope">
                        <x-ui.badge :tone="$envelopeStatus->tone()">{{ $envelopeStatus->label() }}</x-ui.badge>
                        <span class="text-sm font-medium text-gray-900">{{ __(':count undertecknare', ['count' => $envelope->signers_count]) }}@if($envelopeStatus === App\Domain\Signing\EnvelopeStatus::Out) · {{ __('steg :step är öppet', ['step' => $envelope->current_step]) }}@endif</span>
                        @if($envelope->copies->isNotEmpty())
                            <span class="text-sm text-gray-700">{{ __('Kopia till :emails', ['emails' => $envelope->copies->pluck('email')->implode(', ')]) }}</span>
                        @endif
@if($envelopeStatus === App\Domain\Signing\EnvelopeStatus::Out)
                            <span class="text-sm text-gray-700" data-testid="signing-reminder-note">{{ $envelope->auto_reminders ? ($envelope->reminder_interval_days === null ? __('Påminner en gång före sista dag') : ($envelope->reminder_interval_days === 1 ? __('Påminner varje dag') : __('Påminner var :days:e dag', ['days' => $envelope->reminder_interval_days]))) : __('Inga automatiska påminnelser') }}</span>
                        @endif
                        @if($envelopeStatus === App\Domain\Signing\EnvelopeStatus::Out && $canSend)
                            <button type="button" class="ms-auto min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="editDeadline('{{ $envelope->id }}')">{{ __('Ändra sista dag') }}</button>
                            <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="voidEnvelope('{{ $envelope->id }}')" wire:confirm="{{ __('Dra tillbaka dokumentet? Alla länkar slutar gälla, och de som inte har signerat ser att dokumentet är tillbakadraget.') }}">{{ __('Dra tillbaka') }}</button>
                        @endif
                        <div class="w-full">
                            @if($deadlineFor === $envelope->id)
                                <form wire:submit="changeDeadline" class="mt-3 flex flex-wrap items-end gap-3" data-testid="signing-deadline-form">
                                    <x-ui.field for="signing-new-deadline-{{ $envelope->id }}" :label="__('Ny sista dag att signera')" error="expires_on" :hint="__('Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays])">
                                        <input id="signing-new-deadline-{{ $envelope->id }}" type="date" class="{{ $input }} mt-1" wire:model="newDeadline" required>
                                    </x-ui.field>
                                    <x-button wire:loading.attr="disabled">{{ __('Spara ny sista dag') }}</x-button>
                                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="cancelDeadline">{{ __('Avbryt') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endif
                <li wire:key="signing-{{ $signing->id }}" class="py-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
                        <span class="text-sm text-gray-700">@if($inEnvelope){{ __('steg :step', ['step' => $signing->step]) }} · @endif{{ __('till :email', ['email' => $signing->email]) }} · {{ __('gäller till :date', ['date' => $signing->expires_at->setTimezone($zone)->format('Y-m-d')]) }}</span>
                        @if($status === App\Domain\Signing\SigningStatus::Pending && $canSend && $envelope !== null && $envelope->status === App\Domain\Signing\EnvelopeStatus::Out && $signing->channel === 'LINK' && $signing->step <= $envelope->current_step)
                            <button type="button" class="ms-auto min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="remindNow({{ $signing->id }})" wire:confirm="{{ __('Skicka en påminnelse med en ny länk till :email?', ['email' => $signing->email]) }}" data-testid="signing-remind-now">{{ __('Påminn nu') }}</button>
                            @if(! $inEnvelope)
                                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="editDeadline('{{ $envelope->id }}')">{{ __('Ändra sista dag') }}</button>
                            @endif
                        @endif
                        @if($status === App\Domain\Signing\SigningStatus::Pending && $canSend && ! $inEnvelope)
                            <button type="button" class="@if($envelope === null || $envelope->status !== App\Domain\Signing\EnvelopeStatus::Out) ms-auto @endif min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="revoke({{ $signing->id }})" wire:confirm="{{ __('Återkalla länken? Den går inte längre att använda, och kunden ser att den är återkallad.') }}">{{ __('Återkalla') }}</button>
                        @endif
                    </div>
                    @if($status === App\Domain\Signing\SigningStatus::Pending && $signing->reminder_count > 0)
                        <p class="mt-2 text-sm text-gray-700" data-testid="signing-reminded">{{ trans_choice('{1} :count påminnelse skickad, senast :time|[2,*] :count påminnelser skickade, senast :time', $signing->reminder_count, ['count' => $signing->reminder_count, 'time' => $signing->last_reminded_at?->setTimezone($zone)->format('Y-m-d H:i')]) }}</p>
                    @endif
                    @if($envelope !== null && ! $inEnvelope && $status === App\Domain\Signing\SigningStatus::Pending)
                        <p class="mt-2 text-sm text-gray-700" data-testid="signing-reminder-note">{{ $envelope->auto_reminders ? ($envelope->reminder_interval_days === null ? __('Påminner en gång före sista dag') : ($envelope->reminder_interval_days === 1 ? __('Påminner varje dag') : __('Påminner var :days:e dag', ['days' => $envelope->reminder_interval_days]))) : __('Inga automatiska påminnelser') }}</p>
                    @endif
                    @if($envelope !== null && ! $inEnvelope)
                        @if($deadlineFor === $envelope->id)
                            <form wire:submit="changeDeadline" class="mt-3 flex flex-wrap items-end gap-3" data-testid="signing-deadline-form">
                                <x-ui.field for="signing-new-deadline-{{ $envelope->id }}" :label="__('Ny sista dag att signera')" error="expires_on" :hint="__('Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays])">
                                    <input id="signing-new-deadline-{{ $envelope->id }}" type="date" class="{{ $input }} mt-1" wire:model="newDeadline" required>
                                </x-ui.field>
                                <x-button wire:loading.attr="disabled">{{ __('Spara ny sista dag') }}</x-button>
                                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="cancelDeadline">{{ __('Avbryt') }}</button>
                            </form>
                        @endif
                    @endif
                    @if($signing->signed_at && $isQuote && $signing->order_id === null && ! $inEnvelope)
                        <p class="mt-2 text-sm font-semibold text-accent-700">{{ __('Signerad – ordern är inte skapad') }}</p>
                    @endif
                    @if($signing->signed_at)
                        <p class="mt-2 text-sm text-gray-900">{{ __('Signerad :time av :name', ['time' => $signing->signed_at->setTimezone($zone)->format('Y-m-d H:i'), 'name' => $signing->signer_name]) }}@if($signing->signer_title), {{ $signing->signer_title }}@endif</p>
                    @endif
                    @if($signing->declined_at)
                        <p class="mt-2 text-sm text-gray-900">{{ __('Avböjd :time', ['time' => $signing->declined_at->setTimezone($zone)->format('Y-m-d H:i')]) }}@if($signing->decline_reason): ”{{ $signing->decline_reason }}”@endif</p>
                    @endif
                    @if($signing->verification->required())
                        @php($lockedOut = $signing->locked_at !== null && $status === App\Domain\Signing\SigningStatus::Pending)
                        <p class="mt-2 text-sm {{ $lockedOut ? 'font-semibold text-red-700' : 'text-gray-700' }}" data-testid="signing-check-status">
                            {{ __('Kontroll: :method', ['method' => $signing->verification->label()]) }}
                            @if($lockedOut)
                                · {{ __('låst :time efter för många felaktiga försök', ['time' => $signing->locked_at->setTimezone($zone)->format('Y-m-d H:i')]) }}
                            @elseif($signing->verified_at)
                                · {{ __('godkänd :time', ['time' => $signing->verified_at->setTimezone($zone)->format('Y-m-d H:i')]) }}
                            @endif
                            @if($lockedOut && $canSend)
                                <button type="button" class="ms-2 min-h-11 font-normal text-gray-700 underline sm:min-h-0" wire:click="unlock({{ $signing->id }})" wire:confirm="{{ __('Lås upp länken? Undertecknaren får försöka igen. Gör det bara om du vet att det var undertecknaren som skrev fel.') }}">{{ __('Lås upp') }}</button>
                            @endif
                        </p>
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
                        @if($envelope?->chain_head)
                            <p class="mt-1 break-all text-xs text-gray-600">{{ __('Händelsekedjans sista länk: :hash', ['hash' => $envelope->chain_head]) }}</p>
                        @endif
                        @if($envelope !== null)
                            @php($chainChecked = $chainCheck !== null && ($envelope->sent_at === null || $chainCheck['at']->greaterThanOrEqualTo($envelope->sent_at)))
                            @php($chainBroken = $chainCheck !== null && isset($chainCheck['broken'][$envelope->id]))
                            <p class="mt-1 text-xs {{ $chainBroken ? 'font-semibold text-red-700' : 'text-gray-600' }}" data-testid="signing-chain-check">
                                @if($chainBroken)
                                    {{ __('Kontrollen :time hittade en avvikelse i händelsekedjan eller dokumenten. Kontakta den som driftar NONOX.', ['time' => $chainCheck['at']->setTimezone($zone)->format('Y-m-d H:i')]) }}
                                @elseif(! $chainChecked)
                                    {{ __('Händelsekedjan har inte kontrollerats än. Kontrollen körs varje natt.') }}
                                @else
                                    {{ __('Händelsekedjan och dokumenten var oförändrade vid kontrollen :time.', ['time' => $chainCheck['at']->setTimezone($zone)->format('Y-m-d H:i')]) }}
                                @endif
                            </p>
                        @endif
                    </details>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
