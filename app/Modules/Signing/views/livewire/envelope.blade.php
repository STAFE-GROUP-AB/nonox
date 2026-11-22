@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6" data-testid="signing-envelope-page">
    <div>
        <a href="{{ route('signing.envelopes') }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Alla signeringar') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-ink">{{ $envelope->title }}</h1>
            <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
            @if($envelope->confidential)
                <x-ui.badge tone="warning" data-testid="signing-confidential">{{ __('Konfidentiell') }}</x-ui.badge>
            @endif
        </div>
        <p class="mt-1 text-sm text-gray-600">
            {{ $typeLabel }} · {{ __('Skickad :time av :name', ['time' => $envelope->sent_at?->setTimezone($zone)->format('Y-m-d H:i') ?? '–', 'name' => $envelope->creator?->name ?? '–']) }}
            @if($envelope->deadline_at) · {{ __('Sista dag att signera :date', ['date' => $envelope->deadline_at->setTimezone($zone)->format('Y-m-d')]) }}@endif
        </p>
        @if($envelope->confidential)
            <p class="mt-1 text-sm text-gray-600">{{ __('Den här signeringen syns bara för den som skickade den och för den som hanterar signering.') }}</p>
        @endif
        @if($link || $register['customer'] || $register['supplier'])
            <p class="mt-1 text-sm text-gray-700">
                @if($link)<a href="{{ $link['url'] }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $link['label'] }}</a>@endif
                @if($register['customer'])@if($link) · @endif{{ __('Kund: :name', ['name' => $register['customer']]) }}@endif
                @if($register['supplier'])@if($link || $register['customer']) · @endif{{ __('Leverantör: :name', ['name' => $register['supplier']]) }}@endif
            </p>
        @endif
    </div>

    <x-ui.card :title="__('Undertecknare')" :description="__('Undertecknare med samma steg signerar samtidigt. Nästa steg får sin länk när alla i steget före har signerat.')">
        @if($canAct && $status === App\Domain\Signing\EnvelopeStatus::Out)
            <div class="mt-4 flex flex-wrap items-center gap-4" data-testid="signing-envelope-actions">
                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="editDeadline">{{ __('Ändra sista dag') }}</button>
                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="voidEnvelope" wire:confirm="{{ __('Dra tillbaka dokumentet? Alla länkar slutar gälla, och de som inte har signerat ser att dokumentet är tillbakadraget.') }}">{{ __('Dra tillbaka') }}</button>
            </div>
            @if($newDeadline !== null)
                <form wire:submit="changeDeadline" class="mt-3 flex flex-wrap items-end gap-3" data-testid="signing-deadline-form">
                    <x-ui.field for="envelope-new-deadline" :label="__('Ny sista dag att signera')" error="expires_on" :hint="__('Mellan :min och :max dagar fram.', ['min' => $minDays, 'max' => $maxDays])">
                        <input id="envelope-new-deadline" type="date" class="{{ $input }} mt-1" wire:model="newDeadline" required>
                    </x-ui.field>
                    <x-button wire:loading.attr="disabled">{{ __('Spara ny sista dag') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="cancelDeadline">{{ __('Avbryt') }}</button>
                </form>
            @endif
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm" data-testid="signing-signers">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-600">
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Steg') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Undertecknare') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Kontroll') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('Status') }}</th>
                        <th scope="col" class="py-2 font-medium">{{ __('Filer') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($signers as $signer)
                        @php($signerStatus = $signer->effectiveStatus())
                        <tr wire:key="signer-{{ $signer->id }}" class="border-b border-gray-100 align-top">
                            <td class="py-3 pr-3 tabular-nums">{{ $signer->step }}</td>
                            <td class="py-3 pr-3">
                                {{ $signer->email }}
                                @if($signer->signed_at)
                                    <span class="block text-gray-600">{{ __('Signerade :time som :name', ['time' => $signer->signed_at->setTimezone($zone)->format('Y-m-d H:i'), 'name' => $signer->signer_name]) }}@if($signer->signer_title), {{ $signer->signer_title }}@endif</span>
                                @endif
                                @if($signer->declined_at)
                                    <span class="block text-gray-600">{{ __('Avböjde :time', ['time' => $signer->declined_at->setTimezone($zone)->format('Y-m-d H:i')]) }}@if($signer->decline_reason): ”{{ $signer->decline_reason }}”@endif</span>
                                @endif
                                @if($signerStatus === App\Domain\Signing\SigningStatus::Pending && $signer->reminder_count > 0)
                                    <span class="block text-gray-600" data-testid="signing-reminded">{{ trans_choice('{1} :count påminnelse skickad, senast :time|[2,*] :count påminnelser skickade, senast :time', $signer->reminder_count, ['count' => $signer->reminder_count, 'time' => $signer->last_reminded_at?->setTimezone($zone)->format('Y-m-d H:i')]) }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                {{ $signer->verification->label() }}
                                @if($signer->locked_at !== null && $signerStatus === App\Domain\Signing\SigningStatus::Pending)
                                    <span class="block font-semibold text-red-700">{{ __('låst efter för många felaktiga försök') }}</span>
                                    @if($canAct)
                                        <button type="button" class="min-h-11 text-sm font-normal text-gray-700 underline sm:min-h-0" wire:click="unlock({{ $signer->id }})" wire:confirm="{{ __('Lås upp länken? Undertecknaren får försöka igen. Gör det bara om du vet att det var undertecknaren som skrev fel.') }}" data-testid="signing-unlock">{{ __('Lås upp') }}</button>
                                    @endif
                                @elseif($signer->verified_at)
                                    <span class="block text-gray-600">{{ __('godkänd :time', ['time' => $signer->verified_at->setTimezone($zone)->format('Y-m-d H:i')]) }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                <x-ui.badge :tone="$signerStatus->tone()">{{ $signerStatus->label() }}</x-ui.badge>
                                @if($canAct && $signerStatus === App\Domain\Signing\SigningStatus::Pending && $signer->channel === 'LINK' && $signer->step <= $envelope->current_step)
                                    <button type="button" class="ms-2 min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="remind({{ $signer->id }})" wire:confirm="{{ __('Skicka en påminnelse med en ny länk till :email?', ['email' => $signer->email]) }}" data-testid="signing-remind-now">{{ __('Påminn nu') }}</button>
                                @endif
                            </td>
                            <td class="py-3">
                                <a href="{{ route('signing.file', ['signing' => $signer->id, 'file' => 'dokument']) }}" class="block font-medium text-accent-600 hover:text-accent-700">{{ __('PDF:en som skickades') }}</a>
                                @if($signer->signed_document_path)
                                    <a href="{{ route('signing.file', ['signing' => $signer->id, 'file' => 'signeringsbevis']) }}" class="block font-medium text-accent-600 hover:text-accent-700">{{ __('Signeringsbevis') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-input-error for="signing" class="mt-2" />
        <x-input-error for="expires_on" class="mt-2" />

        @if($copies->isNotEmpty())
            <div class="mt-4 text-sm text-gray-700" data-testid="signing-copies">
                <h3 class="font-semibold text-gray-900">{{ __('Kopiemottagare') }}</h3>
                <p>{{ $copies->pluck('email')->implode(', ') }}</p>
            </div>
        @endif
        <p class="mt-4 break-all text-xs text-gray-600">{{ __('SHA-256: :hash', ['hash' => $envelope->document_sha256]) }}</p>
    </x-ui.card>

    @if($envelope->seal_status)
        <x-ui.card :title="__('Försegling')" data-testid="signing-seal">
            <p class="text-sm text-gray-700">
                @switch($envelope->seal_status)
                    @case(App\Domain\Signing\Seal\SealStatus::Pending)
                        {{ __('Förseglas. Kopiorna skickas när dokumentet är förseglat.') }}
                        @break
                    @case(App\Domain\Signing\Seal\SealStatus::Sealed)
                        {{ __('Förseglad :date med :level. Filen som skickades och som du laddar ner är den förseglade.', ['date' => $envelope->sealed_at?->setTimezone($zone)->format('Y-m-d H:i'), 'level' => $envelope->seal_level?->label()]) }}
                        @if($envelope->seal_level === App\Domain\Signing\Pdf\SealLevel::B_B)
                            {{ __('Förseglingen har ingen tidsstämpel.') }}
                        @endif
                        @break
                    @default
                        {{ __('Dokumentet kunde inte förseglas, så kopiorna skickades utan försegling (:reason).', ['reason' => App\Domain\Signing\Seal\EnvelopeSealer::failureLabel((string) $envelope->seal_failure)]) }}
                @endswitch
            </p>
            @if($envelope->ltv_level === App\Domain\Signing\Pdf\SealLevel::B_LTA)
                <p class="mt-2 text-sm text-gray-700" data-testid="signing-ltv">
                    {{ __('Långtidsvalidering (:level): filen har valideringsdata och en dokumenttidsstämpel från :date.', ['level' => $envelope->ltv_level->label(), 'date' => $envelope->last_timestamp_at?->setTimezone($zone)->format('Y-m-d')]) }}
                    @if($envelope->timestamp_valid_until)
                        {{ __('En ny tidsstämpel läggs till innan den slutar gälla :date, så länge dokumentet bevaras.', ['date' => $envelope->timestamp_valid_until->setTimezone($zone)->format('Y-m-d')]) }}
                    @endif
                </p>
            @elseif($envelope->ltv_level === App\Domain\Signing\Pdf\SealLevel::B_LT)
                <p class="mt-2 text-sm text-gray-700" data-testid="signing-ltv">
                    {{ __('Valideringsdata har lagts till i filen (:level), så förseglingen kan kontrolleras även när certifikaten har gått ut.', ['level' => $envelope->ltv_level->label()]) }}
                    @if($envelope->timestamp_valid_until)
                        {{ __('En dokumenttidsstämpel läggs till innan tidsstämpeln slutar gälla :date, så länge dokumentet bevaras.', ['date' => $envelope->timestamp_valid_until->setTimezone($zone)->format('Y-m-d')]) }}
                    @endif
                </p>
            @endif
            @if($envelope->ltv_failure)
                <p class="mt-2 text-sm text-gray-700" data-testid="signing-ltv-failure">
                    {{ __('Senaste försöket att lägga till valideringsdata eller en ny tidsstämpel lyckades inte (:reason). NONOX försöker igen.', ['reason' => App\Domain\Signing\Seal\EnvelopeTimestamper::failureLabel($envelope->ltv_failure)]) }}
                </p>
            @endif
            @if($envelope->sealed_sha256)
                <p class="mt-2 break-all text-xs text-gray-600">{{ __('SHA-256 för den förseglade filen: :hash', ['hash' => $envelope->sealed_sha256]) }}</p>
            @endif
            @if($newestSha256)
                <p class="mt-1 break-all text-xs text-gray-600" data-testid="signing-ltv-sha256">{{ __('SHA-256 för den senaste versionen, som du laddar ner: :hash', ['hash' => $newestSha256]) }}</p>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Bevarande')" data-testid="signing-retention">
        <p class="text-sm text-gray-700">
            @if($envelope->retention_until)
                {{ __(':class. Sparas till och med :date och gallras därefter.', ['class' => App\Domain\Signing\Retention\SigningRetentionClass::tryFrom((string) $envelope->retention_class)?->label() ?? '', 'date' => $envelope->retention_until->toDateString()]) }}
            @else
                {{ __('Bevarandetiden bestäms när signeringen är klar, avböjd, tillbakadragen eller har gått ut.') }}
            @endif
        </p>
        @if($running)
            <p class="mt-2 text-sm text-gray-700" data-testid="signing-agreement-running">{{ __('Avtalet gäller fortfarande. Signeringen gallras inte medan det gäller, och sparas minst :years år efter att det har upphört.', ['years' => $years]) }}</p>
        @endif
        @if($envelope->retention_hold)
            <p class="mt-2 text-sm font-semibold text-gray-900" data-testid="signing-hold-set">{{ __('Bevarandespärr: :reason, sedan :date. Signeringen gallras inte och kan inte raderas.', ['reason' => App\Domain\Documents\LegalHoldReason::tryFrom((string) $envelope->retention_hold_reason)?->label() ?? '', 'date' => $envelope->retention_hold_at?->setTimezone($zone)->format('Y-m-d')]) }}</p>
        @endif

        @if($canRetain || $canHold)
            <div class="mt-4 flex flex-wrap items-end gap-4">
                @if($canHold && $envelope->retention_hold)
                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="liftHold" wire:confirm="{{ __('Häva bevarandespärren? Signeringen gallras igen när bevarandetiden har gått ut.') }}">{{ __('Häv bevarandespärr') }}</button>
                @elseif($canHold)
                    <form wire:submit="setHold" class="flex flex-wrap items-end gap-3" data-testid="signing-hold-form">
                        <x-ui.field for="envelope-hold-reason" :label="__('Skäl för bevarandespärr')" error="holdReason">
                            <select id="envelope-hold-reason" class="{{ $input }} mt-1" wire:model="holdReason" required>
                                <option value="">{{ __('Välj skäl') }}</option>
                                @foreach(App\Domain\Documents\LegalHoldReason::cases() as $reason)
                                    <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-secondary-button type="submit">{{ __('Sätt bevarandespärr') }}</x-secondary-button>
                    </form>
                @endif
                @if($canRetain && $envelope->retention_class === App\Domain\Signing\Retention\SigningRetentionClass::Other->value)
                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="markAccounting" wire:confirm="{{ __('Markera dokumentet som räkenskapsinformation? Det sparas då minst till och med 31 december sju år efter räkenskapsårets slut. Det går inte att ångra.') }}" data-testid="signing-mark-accounting">{{ __('Markera som räkenskapsinformation') }}</button>
                @endif
            </div>
        @endif
    </x-ui.card>

    @if($downloadLinks->isNotEmpty())
        <x-ui.card :title="__('Länkar till filerna')">
            <p class="mt-1 text-sm text-gray-700">{{ __('Filerna var för stora för mejlet, så mottagarna fick en personlig länk. Länken fungerar i 14 dagar, i 7 dagar för företagets länk och för en konfidentiell signering.') }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm" data-testid="signing-download-links">
                    <thead class="text-left text-gray-600">
                        <tr>
                            <th class="py-2 pr-4 font-medium">{{ __('Mottagare') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Nedladdningar') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($downloadLinks as $downloadLink)
                            <tr wire:key="download-link-{{ $downloadLink->id }}">
                                <td class="py-2 pr-4">{{ $downloadLink->email }}</td>
                                <td class="py-2 pr-4">
                                    @if($downloadLink->isRevoked())
                                        {{ __('Stängd') }}
                                    @elseif($downloadLink->isExpired())
                                        {{ __('Utgången :date', ['date' => $downloadLink->expires_at->setTimezone($zone)->format('Y-m-d')]) }}
                                    @else
                                        {{ __('Öppen till :date', ['date' => $downloadLink->expires_at->setTimezone($zone)->format('Y-m-d')]) }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4 tabular-nums">{{ $downloadLink->downloads }}@if($downloadLink->events_max_downloaded_at) · {{ __('senast :time', ['time' => \Carbon\CarbonImmutable::parse($downloadLink->events_max_downloaded_at)->setTimezone($zone)->format('Y-m-d H:i')]) }}@endif</td>
                                <td class="py-2 text-right">
                                    @if($mayManageLinks)
                                        <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="resendLink({{ $downloadLink->id }})">{{ __('Skicka ny länk') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($mayManageLinks)
                <button type="button" class="mt-3 min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="closeLinks" wire:confirm="{{ __('Stänga alla länkar? Ingen kan då ladda ner filerna med sin gamla länk. Du kan skicka en ny länk till var och en.') }}">{{ __('Stäng länkarna') }}</button>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Händelser')">
        <ol class="mt-3 space-y-1 text-sm" data-testid="signing-timeline">
            @forelse($events as $event)
                <li class="tabular-nums text-gray-700">{{ $event->created_at->setTimezone($zone)->format('Y-m-d H:i') }} · {{ $event->event->label() }}@if($emails->has($event->signing_request_id)) · {{ $emails[$event->signing_request_id] }}@endif</li>
            @empty
                <li class="text-gray-600">{{ __('Inga händelser än.') }}</li>
            @endforelse
        </ol>
    </x-ui.card>
</div>
