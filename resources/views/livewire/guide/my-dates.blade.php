{{--
    Mina datum (App\Livewire\Guide\MyDates): how to read the page, the year
    in short (one line per kind of date, and the setting that decides it), the
    nearest date, the filter chips, then the dates by month and day, and
    beside them the calendar link.
--}}
@php
    $next = collect($shown)->first(fn ($deadline) => $deadline->daysLeft($today) >= 0);
    $soonCount = collect($shown)->filter(fn ($deadline) => $deadline->daysLeft($today) >= 0 && $deadline->daysLeft($today) <= 30 && ! $deadline->isRecommendation)->count();
    $help = App\Support\Manual\Manual::enabled() ? App\Support\Manual\Manual::articleForRoute('guide.dates') : null;
    $settingsUrl = route('teams.show', $company->id);
    $spanLabel = $showAll ? __('de kommande 15 månaderna') : __('de kommande sex månaderna');
@endphp

<div class="space-y-6">
    <details class="group rounded-2xl bg-white ring-1 ring-stone-200" @if(! $helpHidden) open @endif
             x-data x-on:toggle="$wire.rememberHelp($el.open)" data-dates-help>
        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-3 px-5 py-3 text-sm font-semibold text-ink [&::-webkit-details-marker]:hidden">
            <svg class="size-5 flex-none text-accent-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 16v-4M12 8h.01" /></svg>
            <span class="flex-1">{{ __('Så läser du sidan') }}</span>
            <span class="text-xs font-medium text-gray-600 group-open:hidden">{{ __('Visa') }}</span>
            <span class="hidden text-xs font-medium text-gray-600 group-open:inline">{{ __('Dölj') }}</span>
        </summary>
        <div class="grid gap-5 border-t border-stone-100 px-5 py-4 text-sm text-gray-700 md:grid-cols-3">
            <div>
                <h3 class="font-semibold text-ink">{{ __('Datumen kommer från dina inställningar') }}</h3>
                <p class="mt-1">{{ __('NONOX räknar fram datumen ur företagets uppgifter och räkenskapsår. Stämmer något inte, till exempel momsperioden, ändrar du det under Företagets uppgifter så ändras datumen.') }}</p>
                <p class="mt-2">{{ __('Infaller dagen på en lördag, söndag eller helgdag flyttas den till nästa vardag, och datumen här är redan flyttade. Gränsen för årsstämman och Bolagsverkets sjumånadersgräns visas utan flytt.') }}</p>
            </div>
            <div>
                <h3 class="font-semibold text-ink">{{ __('F-skatt är inte moms') }}</h3>
                <p class="mt-1">{{ __('Med F-skatt betalar företaget preliminärskatt varje månad enligt Skatteverkets beslut. Momsen deklareras och betalas en gång per momsperiod. Märket på varje rad visar vilket det är.') }}</p>
                <p class="mt-2 flex flex-wrap gap-2"><x-guide.deadline-chip :category="App\Domain\Deadlines\DeadlineCategory::PreliminaryTax" /><x-guide.deadline-chip :category="App\Domain\Deadlines\DeadlineCategory::Vat" /></p>
            </div>
            <div>
                <h3 class="font-semibold text-ink">{{ __('Märkena') }}</h3>
                <ul class="mt-1 space-y-1.5">
                    <li><strong class="text-ink">{{ __('Betalning') }}</strong> {{ __('eller') }} <strong class="text-ink">{{ __('Deklaration') }}</strong>: {{ __('vad du gör den dagen. Moms är båda samma dag.') }}</li>
                    <li><strong class="text-ink">{{ __('Datum i lagen') }}</strong>: {{ __('följer av lagen.') }} <strong class="text-ink">{{ __('Rekommendation') }}</strong>: {{ __('ett råd från Företagsguiden, inget krav i lagen.') }}</li>
                    <li><strong class="text-ink">{{ __('Kontrollera') }}</strong>: {{ __('datumet beror på ett beslut om just ditt företag eller en regel som inte är bekräftad. Det visas en gång per serie.') }}</li>
                    <li><strong class="text-ink">{{ __('Redovisad') }}</strong>: {{ __('momsen eller arbetsgivardeklarationen för perioden är redovisad i NONOX. Den påminner vi inte om.') }}</li>
                </ul>
            </div>
        </div>
    </details>

    <section class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6" aria-labelledby="dates-year" data-dates-year>
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="dates-year" class="text-lg font-bold text-ink">{{ __('Ditt år i korthet') }}</h2>
            <a href="{{ $settingsUrl }}" class="text-sm font-medium text-accent-700 underline hover:text-accent-800">{{ __('Ändra under Företagets uppgifter') }}</a>
        </div>
        <p class="mt-1 text-sm text-gray-600">{{ __('Det här gäller för :company enligt företagets uppgifter i NONOX.', ['company' => $company->name]) }}</p>
        <ul class="mt-4 divide-y divide-stone-100">
            @foreach($lines as $line)
                @php
                    $lineFilter = $line['category']->filter();
                    $lineArticle = $categoryUrl($line['category']);
                @endphp
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:gap-4" data-year-line="{{ $line['category']->value }}">
                    <div class="sm:w-40 sm:flex-none"><x-guide.deadline-chip :category="$line['category']" /></div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold leading-snug text-ink">{{ $line['title'] }}</p>
                        <p class="mt-0.5 text-sm text-gray-700">{{ $line['when'] }}</p>
                        @if($line['flag'])
                            <p class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-gray-700" data-year-flag>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-900 ring-1 ring-amber-200"><span class="size-2 rounded-full bg-amber-500" aria-hidden="true"></span>{{ __('Kontrollera hos :authority', ['authority' => $line['authority']]) }}</span>
                                {{ $line['flag'] }}
                            </p>
                        @endif
                        <p class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            @if($line['next'])
                                <span class="tabular-nums text-gray-700">{{ __('Nästa:') }} <strong class="text-ink">{{ $line['next']->date->translatedFormat('j F Y') }}</strong></span>
                                <a href="{{ $this->link($lineFilter->value, $showAll || $line['next']->date->greaterThan($until)) }}" class="font-semibold text-accent-700 underline hover:text-accent-800">{{ __('Visa datumen') }}<span class="sr-only">: {{ $line['category']->label() }}</span></a>
                            @endif
                            @if($lineArticle)
                                <a href="{{ $lineArticle }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Läs mer') }}<span class="sr-only">: {{ $line['category']->label() }}</span></a>
                            @endif
                            <span class="text-gray-600">{{ $line['setting'] }}</span>
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="overflow-hidden rounded-3xl bg-ink text-white shadow-xs" aria-labelledby="dates-summary">
        <div class="flex flex-col gap-6 p-6 sm:p-8 md:flex-row md:items-center">
            @if($next)
                @php $nextDays = $next->daysLeft($today); @endphp
                <div class="flex size-24 flex-none flex-col items-center justify-center rounded-3xl bg-accent-500 text-ink">
                    <span class="text-4xl font-bold leading-none tabular-nums">{{ $next->date->day }}</span>
                    <span class="mt-1 text-sm font-bold uppercase">{{ $next->date->translatedFormat('M') }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold uppercase tracking-wide text-accent-200">{{ $company->name }} · {{ $filterCase ? __('Nästa datum: :filter', ['filter' => $filterCase->label()]) : __('Nästa datum') }}</p>
                    <h2 id="dates-summary" class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $next->title }}</h2>
                    <p class="mt-2 flex flex-wrap gap-2"><x-guide.deadline-chip :category="App\Domain\Deadlines\DeadlineCategory::of($next)" class="ring-0" />@if($action = App\Domain\Deadlines\DeadlineAction::of($next, $largeEmployer))<span class="inline-flex items-center rounded-full bg-white/10 px-2 py-0.5 text-xs font-semibold text-white">{{ $action->label() }}</span>@endif</p>
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
                        <dt class="text-xs text-stone-300">{{ $showAll ? __('Kommande 15 månader') : __('Kommande 6 månader') }}</dt>
                        <dd class="text-2xl font-bold tabular-nums">{{ count($shown) }}</dd>
                    </div>
                </dl>
            @else
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-accent-200">{{ $company->name }}</p>
                    @if($filterCase)
                        <h2 id="dates-summary" class="mt-1 text-2xl font-bold tracking-tight">{{ __('Inga datum under :filter :span', ['filter' => $filterCase->label(), 'span' => $spanLabel]) }}</h2>
                        <p class="mt-1 text-stone-300"><a href="{{ $this->link(null) }}" class="font-semibold text-white underline">{{ __('Visa alla datum') }}</a></p>
                    @else
                        <h2 id="dates-summary" class="mt-1 text-2xl font-bold tracking-tight">{{ $showAll ? __('Inga datum de kommande 15 månaderna') : __('Inga datum de kommande sex månaderna') }}</h2>
                        <p class="mt-1 text-stone-300">{{ __('Är företaget momsregistrerat eller arbetsgivare? Kontrollera det under företagets uppgifter.') }}</p>
                    @endif
                </div>
            @endif
        </div>
        <p class="border-t border-white/10 bg-white/5 px-6 py-3 text-sm text-stone-200 sm:px-8">{{ __('Datumen räknas fram ur företagets inställningar och räkenskapsår i NONOX. Kontrollera dem hos Skatteverket eller Bolagsverket, som kan ha beslutat något annat för just ditt företag.') }}</p>
    </section>

    @if($moneyFigures)
        <section id="momsbufferten" class="scroll-mt-20 rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6" aria-labelledby="dates-money" data-dates-money>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="dates-money" class="text-lg font-bold text-ink">{{ __('Pengar som inte är dina') }}</h2>
                <span class="text-sm text-gray-600">{{ __('Momsbufferten') }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-600">{{ $company->isVatRegistered() ? __('En del av pengarna på kontot är moms och skatt som ska till Skatteverket. Så här mycket, enligt bokföringen.') : __('En del av pengarna på kontot kan vara skatt som ska till Skatteverket. Så här mycket, enligt bokföringen.') }}</p>
            <x-guide.money-not-yours :figures="$moneyFigures" :company="$company" full :vat-deadlines="$moneyVat" :article-url="$setAsideUrl" class="mt-4" />
        </section>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-8">
            <nav aria-label="{{ __('Visa datum för') }}" data-dates-filters>
                <ul class="flex flex-wrap gap-2">
                    <li><a href="{{ $this->link(null) }}" @if(! $filterCase) aria-current="page" @endif @class([
                        'inline-flex min-h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold ring-1',
                        'bg-ink text-white ring-ink' => ! $filterCase,
                        'bg-white text-ink ring-stone-300 hover:bg-stone-100' => $filterCase,
                    ]) data-dates-filter="alla">{{ __('Alla') }} <span class="tabular-nums opacity-80">{{ $windowCount }}</span></a></li>
                    @foreach(App\Domain\Deadlines\DeadlineFilter::cases() as $case)
                        @continue($counts[$case->value] === 0 && $filterCase !== $case)
                        <li><a href="{{ $this->link($case->value) }}" @if($filterCase === $case) aria-current="page" @endif @class([
                            'inline-flex min-h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold ring-1',
                            'bg-ink text-white ring-ink' => $filterCase === $case,
                            'bg-white text-ink ring-stone-300 hover:bg-stone-100' => $filterCase !== $case,
                        ]) data-dates-filter="{{ $case->value }}">{{ $case->label() }} <span class="tabular-nums opacity-80">{{ $counts[$case->value] }}</span></a></li>
                    @endforeach
                </ul>
            </nav>

            @if($missingEarlierYear)
                <p class="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 ring-1 ring-amber-200" data-dates-missing-year>{{ __('Datum för räkenskapsår före :date visas inte. Har företaget funnits längre, lägg in föregående räkenskapsår för att se dem.', ['date' => $missingEarlierYear->toDateString()]) }}</p>
            @endif
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
                                            @php
                                                $category = App\Domain\Deadlines\DeadlineCategory::of($deadline);
                                                $action = App\Domain\Deadlines\DeadlineAction::of($deadline, $largeEmployer);
                                            @endphp
                                            <p class="mb-1.5 flex flex-wrap items-center gap-2">
                                                <x-guide.deadline-chip :category="$category" />
                                                @if($deadline->isRecommendation)
                                                    <x-guide.deadline-chip recommendation />
                                                @endif
                                                @if($action)
                                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-gray-700" data-deadline-action="{{ $action->value }}">
                                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $action === App\Domain\Deadlines\DeadlineAction::Pay ? 'M3 7h18v12H3zM3 11h18M7 15h3' : ($action === App\Domain\Deadlines\DeadlineAction::Declare ? 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9ZM14 3v6h6M9 15l2 2 4-4' : 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9ZM14 3v6h6M8 13h8M8 17h8') }}" /></svg>
                                                        {{ $action->label() }}
                                                    </span>
                                                @endif
                                            </p>
                                            <h3 class="font-semibold leading-snug text-ink">{{ $deadline->title }}</h3>
                                            @if(in_array($deadline->key, $described, true))
                                                <p class="mt-1 text-sm text-gray-700">{{ $deadline->appliesBecause }}</p>
                                            @endif
                                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                                @if(in_array($deadline->key, $reported, true))
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-200" data-deadline-reported><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10" /></svg>{{ __('Redovisad') }}</span>
                                                @endif
                                                @if(in_array($deadline->key, $flagged, true))
                                                    <span data-deadline-flag class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-900 ring-1 ring-amber-200"><span class="size-2 rounded-full bg-amber-500" aria-hidden="true"></span>{{ __('Kontrollera datumet hos :authority', ['authority' => $deadline->checkWith()]) }}</span>
                                                @endif
                                                @if($actUrl)
                                                    <a href="{{ $actUrl }}" class="font-semibold text-accent-700 underline hover:text-accent-800">{{ __('Gör det i NONOX') }}<span class="sr-only">: {{ $deadline->title }}</span></a>
                                                @endif
                                                @if($guideUrl)
                                                    <a href="{{ $guideUrl }}" class="font-medium text-accent-700 underline hover:text-accent-800">{{ __('Läs mer') }}<span class="sr-only">: {{ $deadline->title }}</span></a>
                                                @endif
                                                @if($late && $missedUrl)
                                                    <a href="{{ $missedUrl }}" class="font-medium text-red-800 underline hover:text-red-900">{{ __('Om du har missat datumet') }}<span class="sr-only">: {{ $deadline->title }}</span></a>
                                                @endif
                                                @if($deadline->sourceUrl)
                                                    <a href="{{ $deadline->sourceUrl }}" target="_blank" rel="noopener" class="text-gray-600 underline hover:text-gray-800">{{ __('Källa') }}<span class="sr-only">: {{ $deadline->title }} {{ __('(öppnas i en ny flik)') }}</span></a>
                                                @endif
                                            </div>
                                            @if(in_array($deadline->key, $explained, true))
                                                <details class="group mt-2 text-sm" data-deadline-explained="{{ $category->value }}">
                                                    <summary class="inline-flex min-h-8 cursor-pointer list-none items-center gap-1.5 font-semibold text-gray-800 [&::-webkit-details-marker]:hidden">
                                                        <svg class="size-4 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                                                        {{ __('Vad är det här?') }}<span class="sr-only">: {{ $category->label() }}</span>
                                                    </summary>
                                                    <p class="mt-1 max-w-prose rounded-xl bg-stone-50 px-3 py-2 text-gray-700">{{ $category->explanation() }}</p>
                                                </details>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach

            <p class="text-center">
                @if($showAll)
                    <a href="{{ $this->link($filter ?: null, false) }}" class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold text-gray-700 ring-1 ring-stone-300 hover:bg-stone-100">{{ __('Visa de närmaste sex månaderna') }}</a>
                @else
                    <a href="{{ $this->link($filter ?: null, true) }}" class="inline-flex min-h-10 items-center rounded-full bg-white px-4 text-sm font-semibold text-ink ring-1 ring-stone-300 hover:bg-stone-100" data-dates-all>{{ __('Visa alla datum (15 månader)') }}</a>
                @endif
            </p>
        </div>

        <aside class="space-y-6 xl:sticky xl:top-20 xl:self-start" aria-label="{{ __('Om datumen') }}">
            <p class="rounded-2xl bg-stone-100 px-4 py-3 text-xs text-gray-700">{{ __('Företagsguiden är i beta. Texterna är kontrollerade mot källorna men ännu inte granskade av en redovisningskonsult.') }}</p>

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
