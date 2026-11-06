{{-- Från Skatteverket on Mina datum (App\Livewire\Guide\SkatteverketEvents, ADR-054 stage 4). --}}
<div>
    @if($offered)
        @php
            $signedIn = $granted !== null && in_array(App\Support\Skatteverket\Skatteverket::SCOPE_KUNDHANDELSER, $granted, true);
            $time = fn ($value): string => $value === null ? '–' : Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i');
        @endphp
        <section id="fran-skatteverket" class="scroll-mt-20 rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6" aria-labelledby="dates-skatteverket" data-skatteverket-events>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="dates-skatteverket" class="text-lg font-bold text-ink">{{ __('Från Skatteverket') }}</h2>
                <x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-gray-600">{{ __('Skatteverkets kundhändelser om företaget: vad som har hänt i företagets ärenden hos Skatteverket, och om Skatteverket väntar på något från företaget.') }}</p>

            @if($test)
                <p class="mt-4 rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-900">{{ __('Testmiljö: NONOX är kopplat till Skatteverkets testtjänst. Händelserna är Skatteverkets testdata.') }}</p>
            @endif

            <div class="mt-4 space-y-2 text-sm text-gray-700">
                <p>{{ __('NONOX hämtar händelserna från Skatteverket bara när du klickar på Hämta från Skatteverket, aldrig av sig själv. Skatteverket visar händelser två år bakåt. NONOX visar varje händelse med Skatteverkets egen rubrik och beskrivning, utan att korta dem.') }}</p>
                <p>{{ __('Händelserna får bara hämtas för att få återkoppling om företagets ärenden hos Skatteverket, och bara av den som företräder företaget eller är dess ombud. Skatteverket kontrollerar din behörighet vid varje hämtning.') }}</p>
                <p>{{ __('Händelser hämtas och visas för den som själv har loggat in hos Skatteverket. NONOX sparar dina händelser bara för dig; andra i företaget hämtar sina egna. NONOX tar bort händelserna två år efter händelsen, som Skatteverket gör. Skatteverkets texter kan innehålla personuppgifter.') }}</p>
                <p class="font-semibold text-ink">{{ __('Skatteverket loggar alla anrop från NONOX (auditloggning) och sparar loggarna i upp till 5 år. Skatteverket använder dem för att förebygga och utreda säkerhetsincidenter.') }}</p>
            </div>

            @if($soleTrader)
                <p class="mt-4 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 ring-1 ring-amber-200" data-skatteverket-sole-trader>{{ __('Kundhändelser från Skatteverket kan bara hämtas för juridiska personer, till exempel aktiebolag och handelsbolag. För en enskild firma finns händelserna på Mina sidor hos Skatteverket.') }}</p>
            @elseif(! $signedIn)
                <div class="mt-5">
                    <a href="{{ route('skatteverket.signIn', ['tillbaka' => $back, 'tjanst' => 'kundhandelser']) }}" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg bg-accent-600 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Logga in hos Skatteverket med e-legitimation') }}</a>
                    <p class="mt-2 text-xs text-gray-500">
                        @if($granted !== null)
                            {{ __('Din inloggning hos Skatteverket gäller inte kundhändelser. Logga in igen; inloggningen gäller då också för tjänsterna du redan är inloggad i.') }}
                        @else
                            {{ __('Du loggar in hos Skatteverket, inte i NONOX, med e-legitimation, till exempel BankID. Inloggningen gäller i ungefär en timme.') }}
                        @endif
                    </p>
                </div>
            @else
                <div class="mt-5 flex flex-wrap items-center gap-4">
                    <x-button type="button" wire:click="fetch" wire:loading.attr="disabled" wire:target="fetch">{{ __('Hämta från Skatteverket') }}</x-button>
                    <span class="text-xs text-gray-500" role="status" aria-live="polite">
                        <span wire:loading.remove wire:target="fetch">{{ $fetchedAt === null ? __('Inte hämtat än.') : __('Senast hämtat :time.', ['time' => $time($fetchedAt)]) }}</span>
                        <span wire:loading wire:target="fetch">{{ __('Hämtar …') }}</span>
                    </span>
                </div>
            @endif

            @if($awaiting->isEmpty() && $others->isEmpty())
                @if($fetchedAt !== null)
                    <p class="mt-5 text-sm text-gray-600">{{ __('Skatteverket har inga kundhändelser om företaget.') }}</p>
                @endif
            @else
                @if($awaiting->isNotEmpty())
                    <h3 class="mt-6 font-semibold text-ink">{{ __('Skatteverket väntar på företaget') }}</h3>
                    <ul class="mt-2 space-y-3" data-skatteverket-awaiting>
                        @foreach($awaiting as $event)
                            @include('livewire.guide.partials.skatteverket-event', ['event' => $event])
                        @endforeach
                    </ul>
                @endif

                @if($others->isNotEmpty())
                    <h3 class="mt-6 font-semibold text-ink">{{ __('Andra händelser') }}</h3>
                    <ul class="mt-2 space-y-3" data-skatteverket-others>
                        @foreach($others as $event)
                            @include('livewire.guide.partials.skatteverket-event', ['event' => $event])
                        @endforeach
                    </ul>
                    @if($moreOthers)
                        <button type="button" wire:click="showEverything" class="mt-3 text-sm font-semibold text-accent-700 underline hover:text-accent-800">{{ __('Visa alla händelser') }}</button>
                    @endif
                @endif
            @endif
        </section>
    @endif
</div>
