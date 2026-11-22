@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $date = fn ($value): string => $value === null ? '–' : $value->setTimezone($zone)->format('Y-m-d');
@endphp

<x-ui.card :title="__('Försegling av signerade dokument')" :description="__('När alla har signerat kan dokumentet förseglas med en elektronisk stämpel (PAdES). Stämpeln visar att filen inte har ändrats sedan den förseglades och med vilket certifikat den förseglades. Den är inte undertecknarnas underskrift.')" data-testid="signing-seal-settings">
    <div class="space-y-3 text-sm text-gray-700" data-testid="signing-seal-status">
        @if(! $switchedOn)
            <p role="note" class="rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200" data-testid="signing-seal-off">{{ __('Försegling är inte påslagen i den här installationen. Signerade dokument skickas utan försegling, som tidigare. Du kan ändå ladda upp företagets certifikat i förväg.') }}</p>
        @elseif(! $engine)
            <x-signing.requires-pdf-engine :feature="__('Försegling')" />
        @else
            <p data-testid="signing-seal-on">{{ __('Försegling är påslagen. Dokument som signeras av alla förseglas innan kopiorna skickas.') }}</p>
        @endif

        <p data-testid="signing-seal-timestamp">
            @if($timestampServers > 0)
                {{ __('Förseglingen får en tidsstämpel från en tidsstämpelserver (PAdES B-T). Svarar ingen server förseglas dokumentet utan tidsstämpel (PAdES B-B) när väntetiden är slut, som förvalt är en timme, och du får ett meddelande.') }}
            @else
                {{ __('Ingen tidsstämpelserver är inställd i installationen, så förseglingen får ingen tidsstämpel (PAdES B-B).') }}
            @endif
        </p>
    </div>

    <h3 class="mt-6 text-sm font-semibold text-gray-900">{{ __('Certifikatet som används') }}</h3>
    @if($active)
        <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[12rem_1fr]" data-testid="signing-seal-active">
            <dt class="text-gray-600">{{ __('Företagets eget certifikat') }}</dt><dd class="text-gray-900 break-words">{{ $active->subject }}</dd>
            <dt class="text-gray-600">{{ __('Utfärdare') }}</dt><dd class="text-gray-900 break-words">{{ $active->self_signed ? __('Självsignerat certifikat – namnet är inte kontrollerat') : $active->issuer.' ('.__('inte kontrollerat av NONOX').')' }}</dd>
            <dt class="text-gray-600">{{ __('Giltigt') }}</dt><dd class="tabular-nums text-gray-900">{{ $date($active->not_before) }} – {{ $date($active->not_after) }}</dd>
            <dt class="text-gray-600">{{ __('Fingeravtryck (SHA-256)') }}</dt><dd class="font-mono text-xs break-all text-gray-900">{{ $active->fingerprint_sha256 }}</dd>
        </dl>
        @if($nameMismatch)
            <p class="mt-2 text-sm font-semibold text-gray-900" role="status" data-testid="signing-seal-name-warning">{{ __('Namnet i certifikatet stämmer inte med företagets namn eller organisationsnummer. Den som öppnar dokumentet ser namnet i certifikatet, och NONOX kontrollerar inte vem som står bakom det. Kontrollera att det är företagets eget certifikat.') }}</p>
        @endif
        @if($active->isExpired())
            <p class="mt-2 text-sm font-semibold text-red-700" role="status">{{ __('Certifikatet har gått ut. Dokumenten förseglas med installationens certifikat tills du laddar upp ett nytt.') }}</p>
        @elseif($active->expiresSoon())
            <p class="mt-2 text-sm font-semibold text-gray-900" role="status">{{ __('Certifikatet går snart ut. Ladda upp ett nytt före :date.', ['date' => $date($active->not_after)]) }}</p>
        @endif
        <button type="button" class="mt-3 min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="retire({{ $active->id }})" wire:confirm="{{ __('Sluta använda företagets certifikat? Nyckeln tas bort från NONOX och kan inte användas igen. Dokument som signeras därefter förseglas med installationens certifikat, om det finns.') }}" data-testid="signing-seal-retire">{{ __('Sluta använda certifikatet') }}</button>
    @elseif($instance)
        <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[12rem_1fr]" data-testid="signing-seal-instance">
            <dt class="text-gray-600">{{ __('Installationens certifikat') }}</dt><dd class="text-gray-900 break-words">{{ $instance->subject }}</dd>
            <dt class="text-gray-600">{{ __('Utfärdare') }}</dt><dd class="text-gray-900">{{ $instance->selfSigned ? __('Självsignerat certifikat – namnet är inte kontrollerat') : $instance->issuer.' ('.__('inte kontrollerat av NONOX').')' }}</dd>
            <dt class="text-gray-600">{{ __('Giltigt till') }}</dt><dd class="tabular-nums text-gray-900">{{ $date($instance->notAfter) }}</dd>
        </dl>
        @if($instance->selfSigned)
            <p class="mt-2 text-sm text-gray-700">{{ __('Ett självsignerat certifikat visas i PDF-läsare som en underskrift med okänd giltighet. Det betyder inte att filen har ändrats. Vill ni att företagets namn ska stå i förseglingen laddar ni upp ett eget certifikat nedan.') }}</p>
        @endif
    @else
        <p class="mt-2 text-sm text-gray-700" data-testid="signing-seal-none">{{ __('Det finns inget förseglingscertifikat. Dokumenten förseglas inte förrän företaget laddar upp ett eget eller installationen har fått ett.') }}</p>
    @endif

    <form wire:submit="upload" class="mt-6 space-y-3" data-testid="signing-seal-upload">
        <h3 class="text-sm font-semibold text-gray-900">{{ $active ? __('Byt till ett nytt certifikat') : __('Ladda upp företagets eget certifikat') }}</h3>
        <p class="text-sm text-gray-600">{{ __('Ett förseglingscertifikat för en organisation (en elektronisk stämpel), som PKCS#12-fil (.p12 eller .pfx) med den privata nyckeln. NONOX sparar det krypterat och visar aldrig nyckeln eller lösenordet igen.') }}</p>
        <x-ui.field for="signing-seal-file" :label="__('Certifikatfil (.p12 eller .pfx)')" error="certificate" :hint="__('Högst :max kB.', ['max' => $maxKilobytes])">
            <input id="signing-seal-file" type="file" accept=".p12,.pfx,application/x-pkcs12" wire:model="certificate" class="mt-1 block text-sm">
        </x-ui.field>
        <x-ui.field for="signing-seal-password" :label="__('Certifikatets lösenord')" error="password">
            <input id="signing-seal-password" type="password" autocomplete="off" maxlength="200" class="{{ $input }} mt-1 w-full max-w-sm" wire:model="password">
        </x-ui.field>
        @if($active)
            <x-button type="submit" wire:loading.attr="disabled" wire:target="certificate,upload" wire:confirm="{{ __('Ersätta företagets certifikat? Det gamla slutar användas och dess nyckel tas bort.') }}">{{ __('Ladda upp certifikatet') }}</x-button>
        @else
            <x-button type="submit" wire:loading.attr="disabled" wire:target="certificate,upload">{{ __('Ladda upp certifikatet') }}</x-button>
        @endif
    </form>

    @if($retired->isNotEmpty())
        <div class="mt-6 overflow-x-auto">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Tidigare certifikat') }}</h3>
            <table class="mt-2 w-full min-w-[32rem] text-sm" data-testid="signing-seal-retired">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-600">
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Certifikat') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Giltigt till') }}</th>
                        <th scope="col" class="py-2 font-medium">{{ __('Slutade användas') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($retired as $old)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-3 break-words">{{ $old->subject }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $date($old->not_after) }}</td>
                            <td class="py-2 tabular-nums">{{ $date($old->retired_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
