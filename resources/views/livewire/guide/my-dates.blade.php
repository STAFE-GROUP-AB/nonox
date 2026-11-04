{{--
    Mina datum (App\Livewire\Guide\MyDates): the nearest date and where the
    dates come from, then the dates by month and day, and beside them the
    legend and the calendar link.
--}}
@php
    $all = array_merge(...array_values($months ?: [[]]));
    $next = collect($all)->first(fn ($deadline) => $deadline->daysLeft($today) >= 0);
    $soonCount = collect($all)->filter(fn ($deadline) => $deadline->daysLeft($today) >= 0 && $deadline->daysLeft($today) <= 30 && ! $deadline->isRecommendation)->count();
    $help = App\Support\Manual\Manual::enabled() ? App\Support\Manual\Manual::articleForRoute('guide.dates') : null;
@endphp

<div class="space-y-6">
    <section class="overflow-hidden rounded-3xl bg-ink text-white shadow-xs" aria-labelledby="dates-summary">
        <div class="flex flex-col gap-6 p-6 sm:p-8 md:flex-row md:items-center">
            @if($next)
                @php $nextDays = $next->daysLeft($today); @endphp
                <div class="flex size-24 flex-none flex-col items-center justify-center rounded-3xl bg-accent-500 text-ink">
                    <span class="text-4xl font-bold leading-none tabular-nums">{{ $next->date->day }}</span>
                    <span class="mt-1 text-sm font-bold uppercase">{{ $next->date->translatedFormat('M') }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold uppercase tracking-wide text-accent-200">{{ $company->name }} · {{ __('Nästa datum') }}</p>
                    <h2 id="dates-summary" class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $next->title }}</h2>
                    <p class="mt-1 text-stone-300 tabular-nums">
                        @if($nextDays === 0)
                            {{ __('I dag') }}
                        @elseif($nextDays === 1)
                            {{ __('I morgon') }}
                        @else
                            {{ __('Om :days dagar', ['days' => $nextDays]) }}
                        @endif
                        · {{ $next->date->translatedFormat('l j F Y') }}
                    </p>
                </div>
                <dl class="grid flex-none grid-cols-2 gap-3 text-center md:w-64">
                    <div class="rounded-2xl bg-white/10 px-3 py-3">
                        <dt class="text-xs text-stone-300">{{ __('Inom 30 dagar') }}</dt>
                        <dd class="text-2xl font-bold tabular-nums">{{ $soonCount }}</dd>
                    </div>
                    <div class="rounded-2xl bg-white/10 px-3 py-3">
                        <dt class="text-xs text-stone-300">{{ __('Alla datum') }}</dt>
                        <dd class="text-2xl font-bold tabular-nums">{{ count($all) }}</dd>
                    </div>
                </dl>
            @else
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-accent-200">{{ $company->name }}</p>
                    <h2 id="dates-summary" class="mt-1 text-2xl font-bold tracking-tight">{{ __('Inga datum de kommande 15 månaderna') }}</h2>
                    <p class="mt-1 text-stone-300">{{ __('Är företaget momsregistrerat eller arbetsgivare? Kontrollera det under företagets uppgifter.') }}</p>
                </div>
            @endif
        </div>
        <p class="border-t border-white/10 bg-white/5 px-6 py-3 text-sm text-stone-200 sm:px-8">{{ __('Datumen räknas fram ur företagets inställningar och räkenskapsår i NONOX. Kontrollera dem hos Skatteverket, som kan ha beslutat något annat för just ditt företag.') }}</p>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-8">
            @foreach($months as $month => $items)
                <section aria-labelledby="month-{{ $month }}" data-dates-month="{{ $month }}">
                    <h2 id="month-{{ $month }}" class="mb-3 flex items-center gap-3 text-sm font-bold uppercase tracking-wide text-gray-700">
                        {{ ucfirst(Carbon\CarbonImmutable::parse("{$month}-01")->translatedFormat('F Y')) }}
                        <span class="h-px flex-1 bg-stone-200" aria-hidden="true"></span>
                    </h2>
                    <ol class="space-y-3">
                        @foreach(collect($items)->groupBy(fn ($deadline) => $deadline->date->toDateString()) as $day => $sameDay)
                            @php
                                $date = $sameDay->first()->date;
                                $days = $sameDay->first()->daysLeft($today);
                                $soon = $days >= 0 && $days <= 7;
                                $late = $days < 0;
                                $onlyAdvice = $sameDay->every(fn ($deadline) => $deadline->isRecommendation);
                            @endphp
                            <li @class([
                                'flex gap-4 rounded-2xl bg-white p-4 ring-1 sm:gap-5 sm:p-5',
                                'ring-stone-200' => ! $soon && ! $late,
                                'ring-2 ring-accent-300' => $soon,
                                'ring-red-200' => $late,
                            ]) data-dates-day="{{ $day }}">
                                <div class="flex w-16 flex-none flex-col items-center gap-2">
                                    <span @class([
                                        'flex size-14 flex-col items-center justify-center rounded-2xl leading-none sm:size-16',
                                        'bg-accent-100 text-accent-900' => $soon,
                                        'bg-red-100 text-red-900' => $late,
                                        'bg-sky-50 text-sky-900 ring-1 ring-sky-300' => $onlyAdvice && ! $soon && ! $late,
                                        'bg-stone-100 text-ink' => ! $onlyAdvice && ! $soon && ! $late,
                                    ])>
                                        <span class="text-2xl font-bold tabular-nums">{{ $date->day }}</span>
                                        <span class="mt-1 text-[11px] font-bold uppercase">{{ $date->translatedFormat('D') }}</span>
                                    </span>
                                    <span @class([
                                        'text-center text-xs font-semibold leading-tight tabular-nums',
                                        'text-red-800' => $late,
                                        'text-accent-800' => $soon,
                                        'text-gray-500' => ! $soon && ! $late,
                                    ])>
                                        @if($late)
                                            {{ trans_choice('Passerat för :count dag sedan|Passerat för :count dagar sedan', -$days, ['count' => -$days]) }}
                                        @elseif($days === 0)
                                            {{ __('I dag') }}
                                        @elseif($days === 1)
                                            {{ __('I morgon') }}
                                        @else
                                            {{ __('Om :days dagar', ['days' => $days]) }}
                                        @endif
                                    </span>
                                </div>
                                <ul class="min-w-0 flex-1 divide-y divide-stone-100">
                                    @foreach($sameDay as $deadline)
                                        @php
                                            $guideUrl = $articleUrl($deadline);
                                            $actUrl = $deadline->route !== null && Route::has($deadline->route) ? route($deadline->route) : null;
                                        @endphp
                                        <li class="py-3 first:pt-0 last:pb-0" data-deadline="{{ $deadline->key }}">
                                            <h3 class="font-semibold leading-snug text-ink">
                                                {{ $deadline->title }}
                                                @if($deadline->isRecommendation)
                                                    <span class="ms-1 inline-flex rounded-full bg-sky-50 px-2 py-0.5 align-middle text-xs font-semibold text-sky-900 ring-1 ring-sky-200">{{ __('Rekommendation') }}</span>
                                                @endif
                                            </h3>
                                            <p class="mt-1 text-sm text-gray-700">{{ $deadline->appliesBecause }}</p>
                                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                                @if($deadline->legalVerify)
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-900 ring-1 ring-amber-200"><span class="size-2 rounded-full bg-amber-500" aria-hidden="true"></span>{{ __('Kontrollera datumet hos Skatteverket') }}</span>
                                                @endif
                                                @if($actUrl)
                                                    <a href="{{ $actUrl }}" class="font-semibold text-accent-700 underline hover:text-accent-800">{{ __('Gör det i NONOX') }}<span class="sr-only">: {{ $deadline->title }}</span></a>
                                                @endif
                                                @if($guideUrl)
                                                    <a href="{{ $guideUrl }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Läs mer') }}<span class="sr-only">: {{ $deadline->title }}</span></a>
                                                @endif
                                                @if($late && $missedUrl)
                                                    <a href="{{ $missedUrl }}" class="font-medium text-red-800 underline hover:text-red-900">{{ __('Om du har missat datumet') }}</a>
                                                @endif
                                                @if($deadline->sourceUrl)
                                                    <a href="{{ $deadline->sourceUrl }}" target="_blank" rel="noopener" class="text-gray-600 underline hover:text-gray-800">{{ __('Källa') }}<span class="sr-only">: {{ $deadline->title }} {{ __('(öppnas i en ny flik)') }}</span></a>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </div>

        <aside class="space-y-6 xl:sticky xl:top-20 xl:self-start" aria-label="{{ __('Om datumen') }}">
            <section class="rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200" aria-labelledby="dates-legend">
                <h2 id="dates-legend" class="text-base font-bold text-ink">{{ __('Förklaring') }}</h2>
                <ul class="mt-3 space-y-3 text-sm text-gray-700">
                    <li class="flex gap-3"><span class="mt-1 size-3 flex-none rounded-full bg-ink" aria-hidden="true"></span><span><strong class="text-ink">{{ __('Datum i lagen') }}</strong><br>{{ __('Står i lag eller i Skatteverkets regler.') }}</span></li>
                    <li class="flex gap-3"><span class="mt-1 size-3 flex-none rounded-full border-2 border-sky-600" aria-hidden="true"></span><span><strong class="text-ink">{{ __('Rekommendation') }}</strong><br>{{ __('Ett råd från Företagsguiden, inget krav i lagen.') }}</span></li>
                    <li class="flex gap-3"><span class="mt-1 size-3 flex-none rounded-full bg-amber-500" aria-hidden="true"></span><span><strong class="text-ink">{{ __('Kontrollera datumet hos Skatteverket') }}</strong><br>{{ __('Regeln är inte bekräftad ännu, eller beror på ett beslut om just ditt företag.') }}</span></li>
                </ul>
                <p class="mt-4 rounded-xl bg-stone-100 px-3 py-2 text-xs text-gray-700">{{ __('Företagsguiden är i beta. Texterna är kontrollerade mot källorna men ännu inte granskade av en redovisningskonsult.') }}</p>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200" aria-labelledby="dates-calendar" data-dates-calendar>
                <h2 id="dates-calendar" class="text-base font-bold text-ink">{{ __('Lägg till i din kalender') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('Prenumerera på datumen i Google Kalender, Apple Kalender eller Outlook. Kalendern hämtar dem själv och påminner en vecka och en dag före. Länken är personlig: den som har den ser företagets datum, så dela den inte.') }}</p>

                @if($newLink)
                    <div class="mt-4 rounded-2xl bg-accent-50 p-4 ring-1 ring-accent-200" x-data="{ copied: false }">
                        <label for="dates-link" class="text-sm font-semibold text-ink">{{ __('Din kalenderlänk') }}</label>
                        <input id="dates-link" type="text" readonly value="{{ $newLink }}" class="mt-2 w-full rounded-lg border-stone-300 font-mono text-xs" x-on:focus="$event.target.select()">
                        <button type="button" class="mt-2 inline-flex min-h-10 w-full items-center justify-center rounded-full bg-ink px-4 text-sm font-semibold text-white hover:bg-accent-600"
                                x-on:click="navigator.clipboard.writeText(document.getElementById('dates-link').value).then(() => copied = true)">
                            <span x-show="! copied">{{ __('Kopiera länken') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Kopierad') }}</span>
                        </button>
                        <p class="mt-2 text-xs text-gray-600">{{ __('Länken visas bara nu. Tappar du bort den skapar du en ny, så slutar den gamla att fungera.') }}</p>
                    </div>
                @elseif($feed)
                    <p class="mt-4 text-sm text-gray-700">{{ __('Du har en kalenderlänk sedan :date.', ['date' => $feed->created_at->format('Y-m-d')]) }}
                        @if($feed->last_used_at) {{ __('Kalendern hämtade datumen senast :date.', ['date' => $feed->last_used_at->format('Y-m-d H:i')]) }} @endif
                    </p>
                @endif

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="issueLink" wire:loading.attr="disabled" class="inline-flex min-h-10 items-center rounded-full bg-ink px-4 text-sm font-semibold text-white hover:bg-accent-600 disabled:opacity-60">
                        {{ $feed || $newLink ? __('Skapa en ny länk') : __('Skapa kalenderlänk') }}
                    </button>
                    @if($feed || $newLink)
                        <button type="button" wire:click="revokeLink" wire:loading.attr="disabled" class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold text-gray-700 ring-1 ring-stone-300 hover:bg-stone-100 disabled:opacity-60">{{ __('Stäng av länken') }}</button>
                    @endif
                </div>
                @if($help)
                    <p class="mt-3"><a href="{{ $help }}#lagg-till-i-din-kalender" target="_blank" rel="noopener" class="text-sm font-medium text-accent-700 underline hover:text-accent-800">{{ __('Så lägger du till länken i din kalender') }}</a></p>
                @endif
            </section>
        </aside>
    </div>
</div>
