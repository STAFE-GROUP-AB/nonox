@php
    use App\Modules\Payroll\Models\AgiSubmission;
    use App\Modules\Payroll\Models\AgiUpload;

    $time = fn (?string $value): string => $value === null || $value === '' ? '–' : rescue(fn () => \Carbon\CarbonImmutable::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i'), $value, false);
    $periodStates = [
        'LOCKED' => __('Perioden är låst, inläsning är inte tillåten'),
        'LOCKED_FOR_SIGNING' => __('Perioden är låst och en komplett deklaration finns'),
        'RECEIVING' => __('Skatteverket läser in underlag'),
        'RECEIVED' => __('Underlaget är inläst och redo att beräknas'),
        'CALCULATING' => __('Skatteverket beräknar perioden'),
        'CALCULATED' => __('Perioden är beräknad och redo att skrivas under'),
        'SIGNING' => __('Deklarationen håller på att skrivas under'),
        'SIGNED' => __('Deklarationen är underskriven och inskickad till Skatteverket'),
        'EMPTY' => __('Perioden har inget utkast och är inte underskriven'),
    ];
@endphp

<x-ui.card :title="__('Lämna arbetsgivardeklarationen via Skatteverkets API')" class="print:hidden" data-agi-skatteverket>
    <x-slot:action><x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge></x-slot:action>

    @if($test)
        <p class="mb-4 rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-900">{{ __('Testmiljö: NONOX är kopplat till Skatteverkets testtjänst. Ingenting lämnas på riktigt.') }}</p>
    @endif

    <div class="space-y-2 text-sm text-gray-700">
        <p>{{ __('NONOX läser in filen ovan som underlag till arbetsgivardeklarationen i företagets eget utrymme hos Skatteverket. Att läsa in underlaget är inte att lämna deklarationen: uppgiftsskyldigheten är inte fullgjord förrän en behörig person har granskat deklarationen och skickat in den.') }}</p>
        <p>{{ __('Granskningen och inlämningen sker i Skatteverkets e-tjänst Arbetsgivardeklaration på Mina sidor, där du loggar in med e-legitimation. Där går underlaget också att ändra.') }}</p>
        <p>{{ __('NONOX visar efter varje steg om underlaget är sparat i eget utrymme eller inte, och varför. Felmeddelandena är Skatteverkets egna.') }}</p>
        <p>{{ __('Skatteverket loggar alla anrop från NONOX (auditloggning) och sparar loggarna i upp till 5 år. Skatteverket använder dem för att förebygga och utreda säkerhetsincidenter.') }}</p>
    </div>

    @if($periods->isEmpty())
        <p class="mt-5 text-sm text-gray-600">{{ __('Skapa arbetsgivardeklarationen för en månad ovan först. Sedan kan den skickas till Skatteverket här.') }}</p>
    @else
        <div class="mt-5">
            <label for="agi-skatteverket-period" class="block text-sm font-medium text-gray-700">{{ __('Utbetalningsmånad') }}</label>
            <select id="agi-skatteverket-period" wire:model.live="period" class="mt-1 block w-48 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm">
                @foreach($periods as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>

        @if(! $signedIn)
            <div class="mt-5">
                @if($oldSignIn)
                    <p class="mb-2 text-sm text-amber-900">{{ __('Din inloggning hos Skatteverket gäller bara momsdeklarationen. Logga in igen för att också använda arbetsgivardeklarationen.') }}</p>
                @endif
                <a href="{{ route('skatteverket.signIn', ['tillbaka' => $back, 'tjanst' => 'arbetsgivardeklaration']) }}" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-accent-600 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Logga in hos Skatteverket med e-legitimation') }}</a>
                <p class="mt-2 text-xs text-gray-500">{{ __('Du loggar in hos Skatteverket, inte i NONOX, med e-legitimation, till exempel BankID. Skatteverket kontrollerar vid varje steg att du är behörig att företräda arbetsgivaren, till exempel som firmatecknare, deklarationsombud eller registreringsombud för arbetsgivardeklaration. Inloggningen gäller i ungefär en timme.') }}</p>
            </div>
        @else
            <div class="mt-5 flex flex-wrap items-center gap-3">
                @if($canSubmit && $submission?->status === AgiSubmission::GENERATED)
                    <div x-data="{ confirming: false }" class="flex flex-wrap items-center gap-3">
                        <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Skicka filen till Skatteverket') }}</x-button>
                        <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-gray-700">{{ __('Filen läses in i företagets eget utrymme hos Skatteverket och kontrolleras där. Har du redan läst in en fil för månaden tas den förra bort först när den nya är kontrollerad och sparad, så att bara den nya finns kvar. Blir den nya inte sparad finns den förra kvar, och är deklarationen underskriven eller låst hos Skatteverket tas den förra aldrig bort.') }}</span>
                            <x-ui.review-confirmation model="figuresReviewed" />
                            <x-button type="button" wire:click="upload({{ $submission->id }})" wire:loading.attr="disabled" :disabled="! $figuresReviewed">{{ __('Ja, skicka filen') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                        </div>
                    </div>
                @endif
                <button type="button" wire:click="refreshStatus" wire:loading.attr="disabled" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Hämta status från Skatteverket') }}</button>
                <button type="button" wire:click="signOut" class="text-sm text-gray-500 underline">{{ __('Logga ut från Skatteverket') }}</button>
            </div>
        @endif

        @if($latest)
            <div class="mt-5 rounded-lg border border-gray-200 p-4 text-sm" wire:key="agi-upload-{{ $latest->id }}">
                <p class="font-semibold">{{ $latest->statusLabel() }}</p>
                @php($own = $latest->uploaded_by === $userId)
                <p class="mt-1 text-gray-600">{{ __('Skickad :date av :name, inlämnings-id :id.', ['date' => $latest->uploaded_at->timezone(config('app.timezone'))->format('Y-m-d H:i'), 'name' => $latest->uploader?->name ?? __('en tidigare användare'), 'id' => $latest->upload_id]) }}</p>
                @if(! $own && $latest->status !== AgiUpload::REMOVED)
                    <p class="mt-1 text-amber-900">{{ __('Bara den som skickade filen kan hämta kontrollresultatet, spara, ta bort eller skapa granskningslänken för den hos Skatteverket. Den går också att ändra eller ta bort på Mina sidor. Du kan skicka en ny fil för månaden; den här finns då kvar i eget utrymme.') }}</p>
                @endif

                @switch($latest->status)
                    @case(AgiUpload::PROCESSING)
                        <p class="mt-2">{{ __('Underlaget är inte sparat i eget utrymme än. Skatteverket kontrollerar filen, vilket tar från några sekunder till några minuter beroende på hur många individuppgifter den har. Bara den som skickade filen kan hämta kontrollresultatet.') }}</p>
                        @break
                    @case(AgiUpload::SAVED)
                        <p class="mt-2">{{ __('Underlaget är sparat i företagets eget utrymme hos Skatteverket. Deklarationen är inte inlämnad förrän en behörig person har granskat och skickat in den på Mina sidor.') }}</p>
                        @break
                    @case(AgiUpload::SAVED_WITH_ERRORS)
                        <p class="mt-2">{{ __('Underlaget är sparat i eget utrymme, men med fel. Felen måste rättas på Mina sidor innan deklarationen kan skickas in.') }}</p>
                        @break
                    @case(AgiUpload::FAILED)
                        <p class="mt-2">{{ __('Underlaget är inte sparat: Skatteverkets kontroll hittade fel. Välj om det ska sparas ändå, så rättar du felen på Mina sidor, eller tas bort, så rättar du i NONOX och skickar en ny fil. Väljer du inget tar Skatteverket bort det efter 24 timmar.') }}</p>
                        @break
                    @case(AgiUpload::REJECTED)
                        <p class="mt-2">{{ __('Underlaget är inte sparat: Skatteverket avvisade filen, eftersom den inte gick att läsa eller inte följde Skatteverkets format. Rätta och skicka en ny fil.') }}</p>
                        @break
                    @default
                        <p class="mt-2">{{ __('Underlaget är borttaget ur eget utrymme hos Skatteverket.') }}</p>
                @endswitch

                @if($latest->checked_at && $latest->status !== AgiUpload::PROCESSING)
                    <p class="mt-1 text-gray-600">
                        {{ __(':errors fel och :warnings varningar i Skatteverkets kontroll, :count individuppgifter.', ['errors' => $latest->error_count ?? 0, 'warnings' => $latest->warning_count ?? 0, 'count' => $latest->individual_count ?? 0]) }}
                    </p>
                @endif

                @if($signedIn && $canSubmit && $own)
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        @if($latest->status !== AgiUpload::REMOVED)
                            <button type="button" wire:click="fetchResult({{ $latest->id }})" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Hämta kontrollresultat') }}</button>
                        @endif
                        @if($latest->status === AgiUpload::FAILED)
                            <x-button type="button" wire:click="saveAnyway({{ $latest->id }})" wire:loading.attr="disabled">{{ __('Spara ändå i eget utrymme') }}</x-button>
                        @endif
                        @if($latest->isInOwnSpace())
                            <x-button type="button" wire:click="review({{ $latest->id }})" wire:loading.attr="disabled">{{ __('Skapa granskningslänk') }}</x-button>
                        @endif
                        @if($latest->status === AgiUpload::FAILED || $latest->isInOwnSpace())
                            <div x-data="{ confirming: false }" class="flex flex-wrap items-center gap-3">
                                <button type="button" x-show="! confirming" x-on:click="confirming = true" class="text-sm text-gray-600 underline">{{ __('Ta bort hos Skatteverket') }}</button>
                                <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3">
                                    <span class="text-sm text-gray-700">{{ __('Det NONOX läste in för månaden tas bort ur eget utrymme hos Skatteverket. Filen i NONOX finns kvar.') }}</span>
                                    <button type="button" wire:click="remove({{ $latest->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Ja, ta bort') }}</button>
                                    <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if($latest->review_link && $latest->review_state === 'INCORRECT_DATA')
                    <a href="{{ $latest->review_link }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center text-sm font-semibold text-accent-700 underline">{{ __('Öppna Skatteverkets felrapport') }}</a>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Länken öppnar Skatteverkets felrapport för perioden på Mina sidor i en ny flik. Där ser du vilka underlag som är felaktiga.') }}</p>
                @elseif($latest->review_link && in_array($latest->review_state, App\Support\Skatteverket\Agd\AgdErrors::REVIEW_STATES, true))
                    <a href="{{ $latest->review_link }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-accent-600 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Granska och skicka in på Mina sidor') }}</a>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Länken öppnar granskningssidan för arbetsgivardeklarationen på Skatteverkets Mina sidor i en ny flik. Där loggar en behörig företrädare in med e-legitimation, granskar deklarationen och skickar in den. Först då är den lämnad och Skatteverket ger en kvittens. Kom sedan tillbaka och hämta status.') }}</p>
                @endif

                @if($latest->period_fetched_at)
                    <p class="mt-3 text-gray-600">
                        {{ $periodStates[$latest->period_state] ?? __('Status hos Skatteverket: :state', ['state' => $latest->period_state ?? '–']) }}
                        @if($latest->latest_receipt_id)
                            · {{ __('Kvittens :receipt, underskriven :date.', ['receipt' => $latest->latest_receipt_id, 'date' => $latest->latest_signed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '–']) }}
                        @endif
                    </p>
                    @if($latest->latest_receipt_id && $submission?->status === AgiSubmission::GENERATED)
                        <p class="mt-1 text-xs text-gray-500">{{ __('Markera filen som inlämnad i listan ovan, med kvittensnumret, så att en senare fil för månaden blir en rättelse.') }}</p>
                    @endif
                @endif
            </div>
        @endif

        @if($result && $resultUpload === $latest?->id && $result['messages'] !== [])
            <div class="mt-5 rounded-lg bg-red-50 p-4 text-sm text-red-900" role="status">
                <p class="font-semibold">{{ __('Skatteverkets kontroll') }}</p>
                @if(collect($result['messages'])->contains('kind', 'FORMAT'))
                    <p class="mt-1">{{ __('Filen följer inte Skatteverkets format för arbetsgivardeklarationen, så Skatteverket har inte läst den. Texterna från Skatteverkets formatkontroll är tekniska och på engelska: de anger vilket fält (element) och vilken rad i filen som är fel, ofta ett person- eller organisationsnummer som saknas eller är fel. Rätta uppgiften i NONOX, skapa filen på nytt och skicka den igen.') }}</p>
                @endif
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    @foreach($result['messages'] as $message)
                        <li>
                            <span class="font-semibold">{{ match ($message['kind']) { 'ARENDE' => __('Rimlighetsfel'), 'FORMAT' => __('Formatfel'), default => __('Stoppande fel') } }}:</span>
                            @if($message['specification'] !== null)<span class="text-xs">{{ __('Specifikationsnummer :number', ['number' => $message['specification']]) }}</span>@endif
                            {{ $message['text'] }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">{{ __('Texterna är Skatteverkets egna. Ett stoppande fel måste rättas innan deklarationen kan skickas in. Ett rimlighetsfel stoppar inte, men kontrollera uppgiften. Specifikationsnumret är den anställdes nummer i NONOX.') }}</p>
            </div>
        @endif

        @if($status)
            <div class="mt-5 rounded-lg border border-gray-200 p-4 text-sm" data-agi-period-status>
                <p class="font-semibold">{{ __('Hos Skatteverket för :period', ['period' => $period]) }}</p>
                @if($status['period'] === null)
                    <p class="mt-1 text-gray-600">{{ __('Skatteverket har ingen arbetsgivardeklaration och inget underlag för månaden.') }}</p>
                @else
                    <p class="mt-1">{{ $periodStates[$status['period']['state']] ?? __('Status hos Skatteverket: :state', ['state' => $status['period']['state']]) }}</p>
                    @if($status['period']['deadline'])
                        <p class="mt-1 text-gray-600">{{ __('Sista dag att deklarera :date.', ['date' => $status['period']['deadline']]) }}@if($status['period']['paymentDate']) {{ __('Sista dag att betala :date.', ['date' => $status['period']['paymentDate']]) }}@endif</p>
                    @endif
                    <p class="mt-1 text-gray-600">{{ __('Individuppgifter i eget utrymme: :ok utan fel, :errors med stoppande fel, :warnings med varning.', ['ok' => $status['period']['iuOk'], 'errors' => $status['period']['iuErrors'], 'warnings' => $status['period']['iuWarnings']]) }}</p>
                    @if($status['period']['correcting'])
                        <p class="mt-1 text-accent-800">{{ __('Deklarationen är underskriven men en rättelse har påbörjats.') }}</p>
                    @endif
                @endif

                @if($status['receipts'] !== [])
                    <p class="mt-3 font-semibold">{{ __('Kvittenser') }}</p>
                    <ul class="mt-1 space-y-1">
                        @foreach($status['receipts'] as $receipt)
                            <li>{{ __('Kvittens :receipt, underskriven :date', ['receipt' => $receipt['id'] ?: '–', 'date' => $time($receipt['signedAt'])]) }}@if($receipt['individuals'] !== null), {{ __(':count individuppgifter', ['count' => $receipt['individuals']]) }}@endif</li>
                        @endforeach
                    </ul>
                @endif

                @if($status['events'] !== [])
                    <p class="mt-3 font-semibold">{{ __('Händelser') }}</p>
                    <ul class="mt-1 space-y-1">
                        @foreach($status['events'] as $event)
                            <li><span class="tabular-nums text-gray-600">{{ $time($event['at']) }}</span> {{ $event['text'] }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Händelserna är Skatteverkets egna. Skatteverket sparar dem i högst 60 dagar och tar bort dem när deklarationen skrivs under.') }}</p>
                @endif
            </div>
        @endif
    @endif
</x-ui.card>
