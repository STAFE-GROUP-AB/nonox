@php
    use App\Domain\Calendar\SwedishHolidays;
    use App\Modules\Planning\Domain\WorkingTimeChecks;
    use App\Support\Money\Money;
    use Carbon\CarbonImmutable;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $locale = app()->getLocale();
    $areaById = $areas->keyBy('id');
    $skillById = $skills->keyBy('id');
    $peopleById = $people->keyBy('id');
    $byCell = $shifts->groupBy(fn ($shift) => ($rows === 'avdelningar' ? ($shift->area_id ?? 'none') : ($shift->employee_id ?? 'open')).'|'.$shift->starts_at->toDateString());
    $isDay = $view === 'dag';
    $money = fn (int $ore): string => Money::ofOre($ore)->format();
    $hours = fn (int $minutes): string => WorkingTimeChecks::hours($minutes);
    $rowList = $rows === 'avdelningar'
        ? collect([['key' => 'none', 'label' => __('Utan avdelning'), 'colour' => '#a8a29e', 'minutes' => null]])->merge($areas->whereNull('archived_at')->map(fn ($area) => ['key' => (string) $area->id, 'label' => $area->name, 'colour' => $area->colour, 'minutes' => null]))
        : collect([['key' => 'open', 'label' => __('Öppna pass'), 'colour' => null, 'minutes' => null]])->merge($people->map(fn ($person) => ['key' => (string) $person->id, 'label' => $person->first_name.' '.$person->last_name, 'colour' => null, 'minutes' => (int) $shifts->where('employee_id', $person->id)->sum(fn ($shift) => $shift->workedMinutes())]));
    $chipLabel = function ($shift) use ($rows, $areaById, $skillById, $peopleById) {
        $parts = [];
        if ($rows === 'avdelningar') {
            $parts[] = $shift->employee_id === null ? __('Öppet pass') : (($p = $peopleById->get($shift->employee_id)) ? $p->first_name.' '.mb_substr($p->last_name, 0, 1) : __('Tidigare anställd'));
        } elseif ($shift->area_id !== null) {
            $parts[] = $areaById->get($shift->area_id)?->name;
        }
        if ($shift->skill_id !== null) {
            $parts[] = $skillById->get($shift->skill_id)?->name;
        }

        return implode(' · ', array_filter($parts));
    };
@endphp

<div
    class="space-y-4"
    x-data="planningGrid({ step: {{ $step }}, view: @js($view), canManage: @js($canManage), rows: @js($rows), copiedText: @js(__('Passet är kopierat. Tryck v i en annan ruta för att klistra in.')) })"
    x-on:pointerdown="pointerDown($event)"
    x-on:keydown="keydown($event)"
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-3xl bg-white p-3 shadow-xs ring-1 ring-stone-200">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="previous" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50" aria-label="{{ $isDay ? __('Föregående dag') : __('Föregående vecka') }}">‹</button>
            <p class="min-w-44 text-center text-sm font-bold text-ink tabular-nums">
                @if($isDay)
                    {{ ucfirst($from->locale($locale)->isoFormat('dddd D MMMM YYYY')) }}
                @else
                    {{ __('Vecka :week', ['week' => $from->isoWeek()]) }} · {{ $from->locale($locale)->isoFormat('D MMM') }}–{{ $to->locale($locale)->isoFormat('D MMM YYYY') }}
                @endif
            </p>
            <button type="button" wire:click="next" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50" aria-label="{{ $isDay ? __('Nästa dag') : __('Nästa vecka') }}">›</button>
            <button type="button" wire:click="today" class="rounded-xl px-3 py-2 text-sm font-semibold text-accent-700 hover:bg-accent-50">{{ __('Idag') }}</button>
            <div class="ms-1 inline-flex rounded-xl bg-stone-100 p-1 text-sm font-semibold" role="group" aria-label="{{ __('Vy') }}">
                <button type="button" wire:click="showWeek" @class(['rounded-lg px-3 py-1', 'bg-white shadow-xs text-ink' => ! $isDay, 'text-gray-600' => $isDay]) aria-pressed="{{ $isDay ? 'false' : 'true' }}">{{ __('Vecka') }}</button>
                <button type="button" wire:click="showDay('{{ $isDay ? $from->toDateString() : CarbonImmutable::today('Europe/Stockholm')->max($from)->min($to)->toDateString() }}')" @class(['rounded-lg px-3 py-1', 'bg-white shadow-xs text-ink' => $isDay, 'text-gray-600' => ! $isDay]) aria-pressed="{{ $isDay ? 'true' : 'false' }}">{{ __('Dag') }}</button>
            </div>
        </div>

        @if($canManage)
            <div class="flex flex-wrap items-center gap-2">
                @unless($isDay)
                    <x-secondary-button type="button" wire:click="openCopy">{{ __('Kopiera veckan') }}</x-secondary-button>
                    <x-secondary-button type="button" wire:click="openTemplate('apply')">{{ __('Lägg ut mall') }}</x-secondary-button>
                    <x-secondary-button type="button" wire:click="openTemplate('save')">{{ __('Spara som mall') }}</x-secondary-button>
                @endunless
                <x-secondary-button type="button" wire:click="recalculate" title="{{ __('Räkna om kostnaderna med dagens löner och regler') }}">{{ __('Räkna om') }}</x-secondary-button>
                <x-button type="button" wire:click="openPublish" :disabled="$drafts === 0">
                    {{ $drafts > 0 ? __('Publicera (:count)', ['count' => $drafts]) : __('Publicerat') }}
                </x-button>
            </div>
        @endif
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3 text-sm">
        <label class="flex items-center gap-2">
            <span class="text-gray-600">{{ __('Rader') }}</span>
            <select wire:model.live="rows" class="{{ $input }} w-auto">
                <option value="personal">{{ __('Personal') }}</option>
                <option value="avdelningar">{{ __('Avdelningar') }}</option>
            </select>
        </label>
        <label class="flex items-center gap-2">
            <span class="text-gray-600">{{ __('Avdelning') }}</span>
            <select wire:model.live="area" class="{{ $input }} w-auto">
                <option value="">{{ __('Alla') }}</option>
                @foreach($areas as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
        @if($skills->isNotEmpty())
            <label class="flex items-center gap-2">
                <span class="text-gray-600">{{ __('Kompetens') }}</span>
                <select wire:model.live="skill" class="{{ $input }} w-auto">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($skills as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($locations->isNotEmpty())
            <label class="flex items-center gap-2">
                <span class="text-gray-600">{{ __('Lokal') }}</span>
                <select wire:model.live="location" class="{{ $input }} w-auto">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($locations as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <button type="button" x-on:click="help = ! help" class="ms-auto text-sm font-semibold text-accent-700 underline">{{ __('Kortkommandon') }}</button>
    </div>

    <div x-show="help" x-cloak class="rounded-2xl bg-stone-50 p-4 text-sm text-gray-700 ring-1 ring-stone-200">
        <ul class="grid gap-1 sm:grid-cols-2">
            <li><kbd class="font-mono font-semibold">← → ↑ ↓</kbd> {{ __('flytta mellan rutorna') }}</li>
            <li><kbd class="font-mono font-semibold">Enter</kbd> {{ __('öppna passet, eller nytt pass i rutan') }}</li>
            <li><kbd class="font-mono font-semibold">Delete</kbd> {{ __('ta bort ett utkast (ett publicerat pass ställs in)') }}</li>
            <li><kbd class="font-mono font-semibold">c</kbd> / <kbd class="font-mono font-semibold">v</kbd> {{ __('kopiera passet och klistra in i en annan ruta') }}</li>
            <li>{{ __('Dra ett pass till en annan person eller dag. Håll Alt för att kopiera.') }}</li>
            <li>{{ __('I dagvyn: dra i kanterna för att ändra tiden, eller rita ett nytt pass på en tom rad.') }}</li>
        </ul>
    </div>

    @if($canManage && $people->isEmpty() && $rows !== 'avdelningar')
        <x-ui.card>
            <x-ui.empty-state :title="__('Ingen personal att planera')">{{ __('Schemat visar de anställda under Lön som har en anställning under perioden. Lägg till personal under Lön → Anställda.') }}</x-ui.empty-state>
        </x-ui.card>
    @endif

    {{-- Phone: a read-only list --}}
    <div class="space-y-3 sm:hidden">
        @foreach($days as $day)
            @php($dayShifts = $shifts->filter(fn ($shift) => $shift->starts_at->isSameDay($day)))
            <section class="rounded-2xl bg-white p-4 shadow-xs ring-1 ring-stone-200">
                <h2 class="text-sm font-bold text-ink">{{ ucfirst($day->locale($locale)->isoFormat('dddd D MMMM')) }}
                    @if($holiday = SwedishHolidays::nameOf($day))<span class="ms-1 font-normal text-accent-700">{{ $holiday }}</span>@endif
                </h2>
                <ul class="mt-2 divide-y divide-stone-100">
                    @forelse($dayShifts as $shift)
                        <li class="flex items-baseline justify-between gap-3 py-2 text-sm">
                            <span class="tabular-nums font-semibold text-ink">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                            <span class="min-w-0 flex-1 truncate text-gray-700">{{ $shift->employee_id === null ? __('Öppet pass') : (($p = $peopleById->get($shift->employee_id)) ? $p->first_name.' '.$p->last_name : __('Tidigare anställd')) }}</span>
                            @if($shift->area_id)<span class="text-xs text-gray-500">{{ $areaById->get($shift->area_id)?->name }}</span>@endif
                        </li>
                    @empty
                        <li class="py-2 text-sm text-gray-500">{{ __('Inga pass.') }}</li>
                    @endforelse
                </ul>
            </section>
        @endforeach
        <p class="text-xs text-gray-500">{{ __('Planera på en surfplatta eller dator. På mobilen visas schemat som en lista.') }}</p>
    </div>

    {{-- Tablet and desktop: the grid --}}
    <div class="hidden overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200 sm:block">
        @if(! $isDay)
            <div class="grid min-w-[56rem]" style="grid-template-columns: 12rem repeat(7, minmax(0, 1fr));" role="grid" aria-label="{{ __('Veckoschema') }}">
                <div class="sticky left-0 z-10 border-b border-stone-200 bg-white p-3 text-xs font-semibold uppercase tracking-wide text-gray-500" role="columnheader">{{ $rows === 'avdelningar' ? __('Avdelning') : __('Person') }}</div>
                @foreach($days as $day)
                    @php($holiday = SwedishHolidays::nameOf($day))
                    <button type="button" wire:click="showDay('{{ $day->toDateString() }}')" role="columnheader" @class(['border-b border-l border-stone-200 p-3 text-left hover:bg-stone-50', 'bg-accent-50/50' => $day->isToday()])>
                        <span class="block text-sm font-bold text-ink">{{ ucfirst($day->locale($locale)->isoFormat('ddd D/M')) }}</span>
                        <span @class(['block truncate text-xs', 'text-accent-700' => $holiday, 'text-gray-400' => ! $holiday])>{{ $holiday ?? ($day->isWeekend() ? __('Helg') : ' ') }}</span>
                    </button>
                @endforeach

                @foreach($rowList as $rowIndex => $row)
                    <div class="sticky left-0 z-10 flex items-center justify-between gap-2 border-b border-stone-200 bg-white px-3 py-2" role="rowheader">
                        <span class="flex min-w-0 items-center gap-2">
                            @if($row['colour'])<span class="size-2.5 shrink-0 rounded-full" style="background: {{ $row['colour'] }}"></span>@endif
                            <span @class(['truncate text-sm', 'font-semibold text-ink' => $rowIndex > 0, 'italic text-gray-600' => $rowIndex === 0])>{{ $row['label'] }}</span>
                        </span>
                        @if($row['minutes'] !== null)<span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $hours($row['minutes']) }}</span>@endif
                    </div>
                    @foreach($days as $day)
                        @php($cellShifts = $byCell->get($row['key'].'|'.$day->toDateString(), collect()))
                        <div
                            data-cell data-row="{{ $row['key'] }}" data-date="{{ $day->toDateString() }}"
                            tabindex="0" role="gridcell"
                            aria-label="{{ $row['label'] }}, {{ $day->locale($locale)->isoFormat('dddd D MMMM') }}"
                            @class(['group relative min-h-16 border-b border-l border-stone-200 p-1.5 outline-none focus-visible:ring-2 focus-visible:ring-accent-500', 'bg-stone-50/70' => $rowIndex === 0, 'bg-accent-50/30' => $day->isToday() && $rowIndex > 0])
                        >
                            @include('planning::partials.availability', ['mark' => $rows !== 'avdelningar' && $row['key'] !== 'open' ? ($availability[(int) $row['key']][$day->toDateString()] ?? null) : null])
                            <div data-chips class="space-y-1">
                                @foreach($cellShifts as $shift)
                                    @include('planning::partials.chip', ['shift' => $shift])
                                @endforeach
                            </div>
                            @if($canManage)
                                <button type="button" wire:click="create({{ $rows === 'avdelningar' || $row['key'] === 'open' ? 'null' : $row['key'] }}, '{{ $day->toDateString() }}')" class="mt-1 w-full rounded-lg py-0.5 text-xs font-semibold text-gray-400 opacity-0 hover:bg-stone-100 hover:text-accent-700 focus:opacity-100 group-hover:opacity-100" tabindex="-1" aria-label="{{ __('Nytt pass') }}">+</button>
                            @endif
                        </div>
                    @endforeach
                @endforeach

                @include('planning::partials.totals', ['days' => $days, 'totals' => $totals, 'money' => $money, 'hours' => $hours])
            </div>
        @else
            @php($date = $from->toDateString())
            <div class="min-w-[56rem]" role="grid" aria-label="{{ __('Dagschema') }}">
                <div class="grid border-b border-stone-200" style="grid-template-columns: 12rem 1fr;">
                    <div class="p-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $rows === 'avdelningar' ? __('Avdelning') : __('Person') }}</div>
                    <div class="relative h-10 border-l border-stone-200">
                        @for($hour = 0; $hour < 24; $hour += 2)
                            <span class="absolute top-3 -translate-x-1/2 text-xs tabular-nums text-gray-500" style="left: {{ $hour / 24 * 100 }}%">{{ sprintf('%02d', $hour) }}</span>
                        @endfor
                    </div>
                </div>
                @foreach($rowList as $rowIndex => $row)
                    @php($rowShifts = $byCell->get($row['key'].'|'.$date, collect()))
                    <div class="grid border-b border-stone-200" style="grid-template-columns: 12rem 1fr;">
                        <div class="flex items-center gap-2 px-3 py-2" role="rowheader">
                            @if($row['colour'])<span class="size-2.5 shrink-0 rounded-full" style="background: {{ $row['colour'] }}"></span>@endif
                            <span @class(['truncate text-sm', 'font-semibold text-ink' => $rowIndex > 0, 'italic text-gray-600' => $rowIndex === 0])>{{ $row['label'] }}</span>
                            @include('planning::partials.availability', ['mark' => $rows !== 'avdelningar' && $row['key'] !== 'open' ? ($availability[(int) $row['key']][$date] ?? null) : null])
                        </div>
                        <div
                            data-track data-row="{{ $row['key'] }}" data-date="{{ $date }}"
                            tabindex="0" role="gridcell" aria-label="{{ $row['label'] }}"
                            @class(['relative h-14 select-none border-l border-stone-200 outline-none focus-visible:ring-2 focus-visible:ring-accent-500', 'bg-stone-50/70' => $rowIndex === 0, 'cursor-crosshair' => $canManage])
                            style="background-image: repeating-linear-gradient(to right, transparent 0, transparent calc(100% / 24 - 1px), rgb(231 229 228) calc(100% / 24 - 1px), rgb(231 229 228) calc(100% / 24));"
                        >
                            @foreach($rowShifts as $shift)
                                @include('planning::partials.chip', ['shift' => $shift, 'timeline' => true])
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <div class="grid" style="grid-template-columns: 12rem repeat(1, minmax(0, 1fr));">
                    @include('planning::partials.totals', ['days' => $days, 'totals' => $totals, 'money' => $money, 'hours' => $hours])
                </div>
            </div>
        @endif
    </div>

    <p class="text-xs text-gray-500">
        {{ __('Kostnaden är en uppskattning: timlön (månadslön räknad per timme), OB enligt kollektivavtalet och, när reglerna är kontrollerade, semesterersättning och arbetsgivaravgifter. Lönen räknas alltid i lönekörningen.') }}
        {{ __('Försäljningen är bokförda intäkter på konto :from–:to; ≈ är snittet för samma veckodag de fyra veckorna före.', ['from' => $settings['sales_account_from'], 'to' => $settings['sales_account_to']]) }}
    </p>

    {{-- The shift editor --}}
    <x-dialog-modal wire:model="showEditor" maxWidth="lg">
        <x-slot name="title">{{ $editing === null ? __('Nytt pass') : __('Ändra pass') }}</x-slot>
        <x-slot name="content">
            <form id="shift-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="shift-person" :label="__('Person')" error="form.employee_id" class="sm:col-span-2">
                    <select id="shift-person" wire:model="form.employee_id" class="{{ $input }} mt-1">
                        <option value="">{{ __('Öppet pass (ingen ännu)') }}</option>
                        @foreach($people as $person)
                            <option value="{{ $person->id }}">{{ $person->first_name }} {{ $person->last_name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="shift-date" :label="__('Datum')" error="form.date" class="sm:col-span-2">
                    <input id="shift-date" type="date" wire:model="form.date" class="{{ $input }} mt-1" required>
                </x-ui.field>
                <x-ui.field for="shift-start" :label="__('Starttid')" error="form.start">
                    <input id="shift-start" type="time" step="{{ $step * 60 }}" wire:model="form.start" class="{{ $input }} mt-1 tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="shift-end" :label="__('Sluttid')" :hint="__('Före starttiden betyder nästa dag.')" error="form.end">
                    <input id="shift-end" type="time" step="{{ $step * 60 }}" wire:model="form.end" class="{{ $input }} mt-1 tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="shift-break" :label="__('Rast (minuter)')" error="form.break_minutes">
                    <input id="shift-break" type="number" min="0" max="720" step="5" wire:model="form.break_minutes" class="{{ $input }} mt-1 tabular-nums">
                </x-ui.field>
                <x-ui.field for="shift-area" :label="__('Avdelning')" error="form.area_id">
                    <select id="shift-area" wire:model="form.area_id" class="{{ $input }} mt-1">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($areas as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}{{ $option->archived_at ? ' ('.__('arkiverad').')' : '' }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($skills->isNotEmpty())
                    <x-ui.field for="shift-skill" :label="__('Kompetens')" error="form.skill_id">
                        <select id="shift-skill" wire:model="form.skill_id" class="{{ $input }} mt-1">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($skills as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="shift-note" :label="__('Anteckning')" error="form.note" class="sm:col-span-2">
                    <input id="shift-note" wire:model="form.note" maxlength="500" class="{{ $input }} mt-1">
                </x-ui.field>
                @if($editing && ($current = $shifts->firstWhere('id', $editing)))
                    @if($current->cost_details)
                        <div class="rounded-xl bg-stone-50 p-3 text-sm text-gray-700 sm:col-span-2">
                            <p class="font-semibold text-ink">{{ __('Uppskattad kostnad') }}: {{ $current->cost_estimate_ore === null ? '–' : $money($current->cost_estimate_ore) }}</p>
                            @foreach((array) ($current->cost_details['missing'] ?? []) as $missing)
                                <p class="mt-1 text-amber-800">{{ $missing }}</p>
                            @endforeach
                            @foreach((array) ($current->cost_details['notes'] ?? []) as $note)
                                <p class="mt-1 text-gray-500">{{ $note }}</p>
                            @endforeach
                        </div>
                    @endif
                    @foreach($warnings[$current->id] ?? [] as $warning)
                        <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2">{{ $warning->message }} @if($warning->source)<span class="text-amber-700">({{ $warning->source }})</span>@endif</p>
                    @endforeach
                @endif
            </form>
        </x-slot>
        <x-slot name="footer">
            <div class="flex w-full flex-wrap items-center justify-between gap-3">
                <div>
                    @if($editing && ($current = $shifts->firstWhere('id', $editing)))
                        <button type="button" wire:click="deleteShift('{{ $editing }}')"
                            wire:confirm="{{ $current->published_at ? __('Ställa in passet? Det är publicerat, så personen får besked om att det är inställt.') : __('Ta bort utkastet?') }}"
                            class="text-sm font-semibold text-red-800 underline">{{ $current->published_at ? __('Ställ in passet') : __('Ta bort') }}</button>
                    @endif
                </div>
                <div class="flex gap-3">
                    <x-secondary-button type="button" wire:click="$set('showEditor', false)">{{ __('Avbryt') }}</x-secondary-button>
                    <x-button type="submit" form="shift-form">{{ __('Spara') }}</x-button>
                </div>
            </div>
        </x-slot>
    </x-dialog-modal>

    {{-- Publicera --}}
    <x-dialog-modal wire:model="showPublish" maxWidth="lg">
        <x-slot name="title">{{ __('Publicera schemat') }}</x-slot>
        <x-slot name="content">
            @if($summary)
                <p>{{ __('Perioden :period publiceras. Personalen ser sina pass och utkasten blir det gällande schemat.', ['period' => App\Modules\Planning\Support\ScheduleNotifier::period($from, $to)]) }}</p>
                <ul class="mt-3 space-y-1">
                    <li>{{ trans_choice('{0} Inga nya pass.|{1} 1 nytt pass.|[2,*] :count nya pass.', $summary['new'], ['count' => $summary['new']]) }}</li>
                    <li>{{ trans_choice('{0} Inga ändrade pass.|{1} 1 ändrat pass.|[2,*] :count ändrade pass.', $summary['changed'], ['count' => $summary['changed']]) }}</li>
                    @if(($summary['unavailable'] ?? 0) > 0)<li class="text-amber-800">{{ trans_choice('{1} 1 pass ligger på en tid som personen har sagt inte passar.|[2,*] :count pass ligger på tider som personerna har sagt inte passar.', $summary['unavailable'], ['count' => $summary['unavailable']]) }}</li>@endif
                    @if($summary['open'] > 0)<li>{{ trans_choice('{1} 1 öppet pass utan person.|[2,*] :count öppna pass utan person.', $summary['open'], ['count' => $summary['open']]) }}</li>@endif
                </ul>
                @if(! $settings['notify_on_publish'])
                    <p class="mt-3">{{ __('Ingen avisering skickas: det är avstängt i inställningarna.') }}</p>
                @elseif($summary['notify'] !== [])
                    <p class="mt-3 font-semibold text-ink">{{ __('Får besked i NONOX eller med e-post:') }}</p>
                    <p>{{ implode(', ', $summary['notify']) }}</p>
                @endif
                @if($summary['unreachable'] !== [])
                    <p class="mt-3 text-amber-800">{{ __('Saknar inloggning och e-post och får inget besked: :names', ['names' => implode(', ', $summary['unreachable'])]) }}</p>
                @endif
            @endif
        </x-slot>
        <x-slot name="footer">
            <x-secondary-button type="button" wire:click="$set('showPublish', false)">{{ __('Avbryt') }}</x-secondary-button>
            <x-button type="button" wire:click="publish" wire:loading.attr="disabled" class="ms-3">{{ __('Publicera') }}</x-button>
        </x-slot>
    </x-dialog-modal>

    {{-- Kopiera veckan --}}
    <x-dialog-modal wire:model="showCopy" maxWidth="md">
        <x-slot name="title">{{ __('Kopiera veckan') }}</x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <p>{{ __('Passen i vecka :week kopieras som utkast. Pass som redan finns kopieras inte igen.', ['week' => $from->isoWeek()]) }}</p>
                <x-ui.field for="copy-target" :label="__('Till veckan som börjar')" error="copy.target">
                    <input id="copy-target" type="date" wire:model="copy.target" class="{{ $input }} mt-1">
                </x-ui.field>
                <x-ui.field for="copy-times" :label="__('Antal veckor i följd')" error="copy.times">
                    <input id="copy-times" type="number" min="1" max="8" wire:model="copy.times" class="{{ $input }} mt-1 w-24">
                </x-ui.field>
                <label class="flex items-center gap-3"><x-checkbox wire:model="copy.people" /> <span>{{ __('Med personerna (annars blir passen öppna)') }}</span></label>
            </div>
        </x-slot>
        <x-slot name="footer">
            <x-secondary-button type="button" wire:click="$set('showCopy', false)">{{ __('Avbryt') }}</x-secondary-button>
            <x-button type="button" wire:click="copyWeek" class="ms-3">{{ __('Kopiera') }}</x-button>
        </x-slot>
    </x-dialog-modal>

    {{-- Mall --}}
    <x-dialog-modal wire:model="showTemplate" maxWidth="md">
        <x-slot name="title">{{ $template['mode'] === 'save' ? __('Spara veckan som mall') : __('Lägg ut en mall') }}</x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                @if($template['mode'] === 'save')
                    <x-ui.field for="template-name" :label="__('Mallens namn')" error="name">
                        <input id="template-name" wire:model="template.name" maxlength="100" class="{{ $input }} mt-1" placeholder="{{ __('Vanlig vecka') }}">
                    </x-ui.field>
                @elseif($templates->isEmpty())
                    <p>{{ __('Det finns inga mallar ännu. Spara en vecka som mall, eller skapa en under Mallar.') }}</p>
                @else
                    <x-ui.field for="template-pick" :label="__('Mall')" error="template">
                        <select id="template-pick" wire:model="template.template" class="{{ $input }} mt-1">
                            @foreach($templates as $option)
                                <option value="{{ $option->id }}">{{ $option->name }} ({{ trans_choice('{1} 1 vecka|[2,*] :count veckor', $option->weeks, ['count' => $option->weeks]) }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="template-times" :label="__('Antal gånger i följd från vecka :week', ['week' => $from->isoWeek()])" error="times">
                        <input id="template-times" type="number" min="1" max="8" wire:model="template.times" class="{{ $input }} mt-1 w-24">
                    </x-ui.field>
                @endif
                <label class="flex items-center gap-3"><x-checkbox wire:model="template.people" /> <span>{{ __('Med personerna (annars blir passen öppna)') }}</span></label>
            </div>
        </x-slot>
        <x-slot name="footer">
            <x-secondary-button type="button" wire:click="$set('showTemplate', false)">{{ __('Avbryt') }}</x-secondary-button>
            <x-button type="button" wire:click="applyTemplate" class="ms-3">{{ $template['mode'] === 'save' ? __('Spara mallen') : __('Lägg ut') }}</x-button>
        </x-slot>
    </x-dialog-modal>
</div>
