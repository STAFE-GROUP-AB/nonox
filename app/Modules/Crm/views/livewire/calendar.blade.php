@php
    $zone = 'Europe/Stockholm';
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $detailsWarning = __('Med detaljer hamnar rubriken, platsen, affären, anteckningen och personens namn i kalendern. Länken fungerar utan inloggning: den som får tag i den ser allt det, och kalendertjänsten (till exempel Google, Microsoft eller Apple) lagrar det. Slå bara på detaljer om kalendern är din egen och inte delas med andra.');
@endphp

<div class="space-y-6">
    <x-ui.card :title="__('Prenumerera på kalender')" :description="__('En hemlig länk som din kalender hämtar på egen hand. Nya, flyttade och klara aktiviteter syns i kalendern efter nästa uppdatering, oftast inom en timme.')">
        <div class="space-y-4" data-testid="crm-calendar-feed">
            <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
                <li>{{ __('Med: dina planerade samtal, möten och uppgifter med tid i det här företaget, och affärernas nästa steg. Från 30 dagar bakåt till ett år framåt.') }}</li>
                <li>{{ __('Inte med: klara aktiviteter, anteckningar och aktiviteter om någon vars behandling är begränsad. Inget ur bokföringen.') }}</li>
                <li>{{ __('Utan detaljer står det bara till exempel "Möte: Företaget AB" och en länk till NONOX. En privatperson visas som "privatkund".') }}</li>
            </ul>

            @if($feedUrl)
                <div class="space-y-2 rounded-2xl bg-stone-50 p-4" x-data="{ copied: false }">
                    <p class="text-sm font-semibold text-ink">{{ __('Din kalenderlänk') }}</p>
                    <code class="block break-all rounded-lg bg-white px-3 py-2 font-mono text-xs text-gray-900 ring-1 ring-stone-200" data-calendar-url>{{ $feedUrl }}</code>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 sm:min-h-9"
                            x-on:click="navigator.clipboard.writeText(@js($feedUrl)); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-show="! copied">{{ __('Kopiera länken') }}</span><span x-show="copied" x-cloak>{{ __('Kopierad') }}</span>
                        </button>
                        <a href="{{ preg_replace('#^https?://#', 'webcal://', $feedUrl) }}" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 sm:min-h-9">{{ __('Öppna i kalendern') }}</a>
                    </div>
                    <p class="text-xs text-gray-600" role="status">{{ __('Länken visas bara nu. Behöver du den igen skapar du en ny; den gamla slutar då att fungera.') }}</p>
                </div>
            @elseif($feed)
                <p class="text-sm text-gray-700">
                    {{ __('Du har en kalenderlänk sedan :date.', ['date' => $feed->created_at->setTimezone($zone)->toDateString()]) }}
                    @if($feed->last_used_at) {{ __('Senast hämtad :time.', ['time' => $feed->last_used_at->setTimezone($zone)->translatedFormat('j M H:i')]) }}@endif
                </p>
            @endif

            @if(! $detailsAllowed)
                <p class="text-sm text-gray-600">{{ __('Företaget tillåter bara typ och företag i kalendrar, inga detaljer.') }}</p>
            @elseif($feed)
                <div class="rounded-2xl p-4 ring-1 {{ $feed->include_details ? 'bg-amber-50 ring-amber-200' : 'bg-white ring-stone-200' }}">
                    <p class="text-sm font-semibold text-ink">{{ $feed->include_details ? __('Detaljer visas i kalendern') : __('Bara typ och företag visas i kalendern') }}</p>
                    @if($feed->include_details)
                        <p class="mt-1 text-sm text-gray-700">{{ $detailsWarning }}</p>
                        <button type="button" class="mt-3 min-h-11 text-sm font-semibold text-ink underline sm:min-h-9" wire:click="setFeedDetails(false)">{{ __('Dölj detaljerna') }}</button>
                    @else
                        <button type="button" class="mt-2 min-h-11 text-sm font-semibold text-accent-700 underline sm:min-h-9" wire:click="setFeedDetails(true)" wire:confirm="{{ $detailsWarning }}">{{ __('Visa detaljer i kalendern') }}</button>
                    @endif
                </div>
            @else
                <label class="flex items-start gap-3 text-sm text-gray-800">
                    <input type="checkbox" class="mt-0.5 size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model.live="newDetails" aria-describedby="crm-calendar-details-warning">
                    <span>{{ __('Visa detaljer i kalendern (rubrik, plats, anteckning och personer)') }}</span>
                </label>
                @if($newDetails)
                    <p id="crm-calendar-details-warning" class="rounded-xl bg-amber-50 p-3 text-sm text-gray-800 ring-1 ring-amber-200">{{ $detailsWarning }}</p>
                @else
                    <p id="crm-calendar-details-warning" class="text-sm text-gray-600">{{ __('Utan detaljer: rekommenderat, särskilt om kalendern delas med andra.') }}</p>
                @endif
            @endif

            <div class="flex flex-wrap items-center gap-4">
                <x-secondary-button type="button" wire:click="issueFeed" :wire:confirm="$feed ? __('Skapa en ny länk? Den gamla slutar fungera, och kalendrar som använder den måste prenumerera på den nya.') : null">{{ $feed ? __('Skapa ny länk') : __('Skapa kalenderlänk') }}</x-secondary-button>
                @if($feed)
                    <button type="button" class="min-h-11 text-sm font-semibold text-red-800 underline sm:min-h-9" wire:click="revokeFeed" wire:confirm="{{ __('Stänga av kalenderlänken? Kalendrar som använder den slutar uppdateras.') }}">{{ __('Stäng av länken') }}</button>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Så prenumererar du')">
        <div class="space-y-3 text-sm text-gray-700">
            <details class="rounded-xl bg-stone-50 p-3">
                <summary class="min-h-11 cursor-pointer font-semibold text-ink sm:min-h-0">{{ __('Google Kalender') }}</summary>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>{{ __('Öppna Google Kalender i en webbläsare på datorn.') }}</li>
                    <li>{{ __('Välj plustecknet vid Andra kalendrar och sedan Från webbadress.') }}</li>
                    <li>{{ __('Klistra in länken och välj Lägg till kalender. Kalendern syns sedan också i telefonen.') }}</li>
                </ol>
                <p class="mt-2">{{ __('Google hämtar en prenumererad kalender på egen hand, ibland bara några gånger per dygn.') }}</p>
            </details>
            <details class="rounded-xl bg-stone-50 p-3">
                <summary class="min-h-11 cursor-pointer font-semibold text-ink sm:min-h-0">{{ __('Outlook') }}</summary>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>{{ __('Öppna Outlook på webben (eller nya Outlook) och gå till Kalender.') }}</li>
                    <li>{{ __('Välj Lägg till kalender och sedan Prenumerera från webben.') }}</li>
                    <li>{{ __('Klistra in länken, ge kalendern ett namn och välj Importera.') }}</li>
                </ol>
            </details>
            <details class="rounded-xl bg-stone-50 p-3">
                <summary class="min-h-11 cursor-pointer font-semibold text-ink sm:min-h-0">{{ __('Apples Kalender (iPhone, iPad och Mac)') }}</summary>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>{{ __('På iPhone: öppna Inställningar, Kalender, Konton, Lägg till konto, Annat och Lägg till prenumererad kalender.') }}</li>
                    <li>{{ __('På Mac: öppna Kalender och välj Arkiv och Ny kalenderprenumeration.') }}</li>
                    <li>{{ __('Klistra in länken. Eller tryck Öppna i kalendern direkt efter att du skapat länken.') }}</li>
                </ol>
            </details>
            <p>{{ __('Enstaka möten lägger du in med Lägg till i kalendern på aktiviteten, utan prenumeration.') }}</p>
        </div>
    </x-ui.card>

    @if($providers !== [] || $connection)
        <x-ui.card :title="__('Koppla din kalender')" :description="__('Tvåvägssynk: NONOX skriver dina samtal, möten och uppgifter i din egen kalender, och flyttar du eller ställer in en av dem där följer aktiviteten i NONOX med. Andra händelser i din kalender läses aldrig in.')">
            <div class="space-y-4" data-testid="crm-calendar-connection">
                @if($connection)
                    <p class="text-sm text-gray-800">
                        {{ __(':provider är kopplad', ['provider' => $connection->provider->label()]) }}@if($connection->account_email) ({{ $connection->account_email }})@endif.
                        @if($connection->calendar_name) {{ __('NONOX skriver i kalendern :name.', ['name' => $connection->calendar_name]) }}@endif
                        @if($connection->last_synced_at) {{ __('Senast synkad :time.', ['time' => $connection->last_synced_at->setTimezone($zone)->translatedFormat('j M H:i')]) }}@endif
                    </p>
                    @if($connection->status === App\Modules\Crm\Models\CalendarConnection::ERROR)
                        <p class="rounded-xl bg-accent-50 p-3 text-sm text-accent-800 ring-1 ring-accent-200" role="alert">{{ __('Åtkomsten till kalendern har upphört. Koppla den igen för att fortsätta synka.') }}</p>
                        <a href="{{ route('crm.calendar.connect', $connection->provider->slug()) }}" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-black">{{ __('Koppla igen') }}</a>
                    @elseif($connection->status === App\Modules\Crm\Models\CalendarConnection::REVOKED)
                        <p class="text-sm text-gray-600" role="status">{{ __('Kopplas bort …') }}</p>
                    @else
                        @if($calendars === [])
                            <button type="button" class="min-h-11 text-sm font-semibold text-accent-700 underline sm:min-h-9" wire:click="loadCalendars">{{ __('Byt kalender') }}</button>
                        @else
                            <form wire:submit="chooseCalendar" class="flex flex-wrap items-end gap-3">
                                <x-ui.field for="crm-calendar-choice" :label="__('Kalender att skriva i')" error="calendar">
                                    <select id="crm-calendar-choice" class="{{ $input }} mt-1" wire:model="calendarId">
                                        @foreach($calendars as $calendar)
                                            <option value="{{ $calendar['id'] }}">{{ $calendar['name'] }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-button>{{ __('Spara') }}</x-button>
                            </form>
                        @endif
                        @if($detailsAllowed)
                        <div class="rounded-2xl p-4 ring-1 {{ $connection->include_details ? 'bg-amber-50 ring-amber-200' : 'bg-white ring-stone-200' }}">
                            <p class="text-sm font-semibold text-ink">{{ $connection->include_details ? __('Detaljer skrivs till kalendern') : __('Bara typ och företag skrivs till kalendern') }}</p>
                            @if($connection->include_details)
                                <button type="button" class="mt-2 min-h-11 text-sm font-semibold text-ink underline sm:min-h-9" wire:click="setConnectionDetails(false)">{{ __('Dölj detaljerna') }}</button>
                            @else
                                <button type="button" class="mt-2 min-h-11 text-sm font-semibold text-accent-700 underline sm:min-h-9" wire:click="setConnectionDetails(true)" wire:confirm="{{ __('Med detaljer hamnar rubriken, platsen, affären, anteckningen och personens namn i din kalender hos :provider. Slå bara på det om kalendern är din egen och inte delas med andra.', ['provider' => $connection->provider->label()]) }}">{{ __('Skriv detaljer till kalendern') }}</button>
                            @endif
                        </div>
                        @endif
                    @endif
                    @if($connection->status !== App\Modules\Crm\Models\CalendarConnection::REVOKED)
                        <button type="button" class="min-h-11 text-sm font-semibold text-red-800 underline sm:min-h-9" wire:click="disconnect" wire:confirm="{{ __('Koppla bort kalendern? NONOX tar bort sina händelser från den, och åtkomsten återkallas.') }}">{{ __('Koppla bort') }}</button>
                        @if($connection->provider === App\Modules\Crm\Calendar\CalendarProviderKey::Microsoft)
                            <p class="text-xs text-gray-600">{{ __('Microsoft låter inte NONOX återkalla åtkomsten själv. Ta bort NONOX under Appar och tjänster i ditt Microsoft-konto efter bortkopplingen.') }}</p>
                        @endif
                    @endif
                @else
                    <p class="text-sm text-gray-700">{{ __('Du loggar in hos Google eller Microsoft och godkänner att NONOX får läsa listan över dina kalendrar och skriva händelser. NONOX sparar åtkomsten krypterad och tar bort den när du kopplar bort.') }}</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach($providers as $provider)
                            <a href="{{ route('crm.calendar.connect', $provider->slug()) }}" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-black">{{ __('Koppla :provider', ['provider' => $provider->label()]) }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-ui.card>
    @endif
</div>
