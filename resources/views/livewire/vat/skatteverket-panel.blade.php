@php
    use App\Domain\Vat\VatSubmissionStatus;
@endphp

<x-ui.card :title="__('Lämna deklarationen till Skatteverket')" class="print:hidden">
    @if($test)
        <p class="mb-4 rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-900">{{ __('Testmiljö: NONOX är kopplat till Skatteverkets testtjänst. Ingenting lämnas på riktigt.') }}</p>
    @endif

    <div class="space-y-2 text-sm text-gray-700">
        <p>{{ __('NONOX skickar rutorna ovan till Skatteverket som ett utkast i företagets eget utrymme hos Skatteverket och låser det för underskrift. Att skicka utkastet är inte att lämna deklarationen. Skyldigheten att lämna den är inte fullgjord förrän en behörig person har skrivit under den hos Skatteverket, och det ska ske senast sista dag för deklarationen.') }}</p>
        <p>{{ __('Utkastet går att ändra i Skatteverkets e-tjänst Lämna momsdeklaration på Mina sidor. Det tas bort 30 dagar efter att det skickades om ingen har arbetat med det eller skrivit under det.') }}</p>
        <p class="text-xs text-gray-500">{{ __('Skatteverket loggar varje anrop från NONOX och använder loggarna för att förebygga och utreda säkerhetsincidenter och otillbörlig användning. Loggarna gallras enligt Skatteverkets gällande beslut.') }}</p>
    </div>

    @if(! $signedIn)
        <div class="mt-5">
            <a href="{{ route('skatteverket.signIn', ['tillbaka' => $back, 'tjanst' => 'moms']) }}" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-accent-600 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Logga in hos Skatteverket med e-legitimation') }}</a>
            <p class="mt-2 text-xs text-gray-500">{{ __('Du loggar in hos Skatteverket, inte i NONOX, med e-legitimation, till exempel BankID. Skatteverket kontrollerar vid varje steg att du är behörig firmatecknare, deklarationsombud, ombud för momsdeklaration eller representant för enkla bolag eller partrederi. Inloggningen gäller i ungefär en timme.') }}</p>
        </div>
    @else
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button type="button" wire:click="checkReturn" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Låt Skatteverket kontrollera') }}</button>
            @if($canSend)
                <div x-data="{ confirming: false }" class="flex flex-wrap items-center gap-3">
                    <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Skicka till Skatteverket') }}</x-button>
                    <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-gray-700">{{ __('Rutorna skickas som utkast och låses för underskrift. Har NONOX redan skickat ett låst utkast för perioden låses det upp och ersätts.') }}</span>
                        <x-ui.review-confirmation model="figuresReviewed" />
                        <x-button type="button" wire:click="send" wire:loading.attr="disabled" :disabled="! $figuresReviewed">{{ __('Ja, skicka utkastet') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                    </div>
                </div>
            @endif
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Hämta status från Skatteverket') }}</button>
            <button type="button" wire:click="signOut" class="text-sm text-gray-500 underline">{{ __('Logga ut från Skatteverket') }}</button>
        </div>
    @endif

    @if($check)
        <div @class([
            'mt-5 rounded-lg p-4 text-sm',
            'bg-green-50 text-green-900' => $check['status'] === 'OK',
            'bg-amber-50 text-amber-950' => $check['status'] === 'WARNING',
            'bg-red-50 text-red-900' => $check['status'] === 'ERROR',
        ]) role="status">
            <p class="font-semibold">
                @switch($check['status'])
                    @case('OK') {{ __('Skatteverkets kontroll: inga fel') }} @break
                    @case('WARNING') {{ __('Skatteverkets kontroll: varningar') }} @break
                    @default {{ __('Skatteverkets kontroll: fel som stoppar') }}
                @endswitch
            </p>
            @if($check['resultat'] !== [])
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    @foreach($check['resultat'] as $row)
                        <li><span class="font-mono text-xs">{{ $row['kod'] }}</span> {{ $row['beskrivning'] }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">{{ __('Texterna är Skatteverkets egna. Ett fel stoppar utkastet; en varning stoppar inte, men kontrollera beloppen.') }}</p>
            @endif
        </div>
    @endif

    @if($submission)
        <div class="mt-5 rounded-lg border border-gray-200 p-4 text-sm">
            <p class="font-semibold">{{ $submission->status->label() }}</p>
            <p class="mt-1 text-gray-600">{{ __('Skickat :date.', ['date' => $submission->sent_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '–']) }}</p>

            @if($submission->status === VatSubmissionStatus::ReadyToSign && $submission->signing_link)
                <a href="{{ $submission->signing_link }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-accent-600 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Skriv under hos Skatteverket') }}</a>
                <p class="mt-2 text-xs text-gray-500">{{ __('Länken öppnar Skatteverkets sida Skriv under digitalt i en ny flik. Där loggar du in med e-legitimation, granskar deklarationen och skriver under. Först då är den lämnad. Kom sedan tillbaka och hämta status.') }}</p>
            @endif

            @if($submission->receipt_number)
                <p class="mt-1">{{ __('Kvittens :receipt, inlämnad :date.', ['receipt' => $submission->receipt_number, 'date' => $submission->submitted_at?->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</p>
                @if($submission->decided_boxes !== null)
                    @php($differs = collect($submission->decided_boxes)->filter(fn (int $amount, int $box): bool => ($submission->boxes[$box] ?? 0) !== $amount)->isNotEmpty())
                    <p @class(['mt-1', 'text-accent-800' => $differs])>
                        {{ $differs
                            ? __('Skatteverkets beslut skiljer sig från det NONOX skickade. Jämför rutorna med beslutet på Mina sidor.')
                            : __('Skatteverkets beslut stämmer med det NONOX skickade.') }}
                    </p>
                @endif
            @endif
        </div>
    @endif
</x-ui.card>
