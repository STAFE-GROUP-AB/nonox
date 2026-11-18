@php
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $quiet = 'text-sm font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline';
    $m = fn (int $ore): Money => Money::ofOre($ore);
    $th = 'py-2 pl-4 text-right font-medium';
    $td = 'py-1.5 pl-4 text-right';
    $columns = $report && $report['detailed'] ? 4 : 2;
@endphp

<div class="space-y-6">
    {{-- ADR-060 2.4 and S2-R14: every consolidated screen, print, CSV and PDF says what it is. --}}
    <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note" data-group-report-label>
        <p class="font-semibold">{{ __('Intern koncernrapport, inte en koncernredovisning enligt ÅRL') }}</p>
        <p class="mt-1">{{ __('Rapporterna visar en sparad konsolidering: företagens egen bokföring summerad efter koncernens kontomappning, med de elimineringar som är godkända i koncernjournalen för samma period. De är ett internt underlag och inte en koncernredovisning, och ingenting bokförs i något av företagen.') }}</p>
    </div>

    @if($groups->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Du ser ingen koncern')">
                {{ __('En koncern visas när du får se rapporter i alla dess företag, eller när du har fått rollen att läsa koncernens sammanställning. Koncerner skapas under Koncernstruktur.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card class="print:hidden">
            <div class="flex flex-wrap items-end gap-4">
                <x-ui.field for="reports_group" :label="__('Koncern')" class="min-w-56">
                    <select id="reports_group" class="{{ $input }} mt-1" wire:change="selectGroup($event.target.value)">
                        @foreach($groups as $option)
                            <option value="{{ $option->id }}" @selected($current && $option->id === $current->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($runs->isNotEmpty())
                    <x-ui.field for="reports_run" :label="__('Körning')" class="min-w-72">
                        <select id="reports_run" class="{{ $input }} mt-1" wire:change="selectRun($event.target.value)">
                            @foreach($runs as $option)
                                <option value="{{ $option->id }}" @selected($selected && $option->id === $selected->id)>{{ __('Nr :id, :from – :to, körd :when', ['id' => $option->id, 'from' => $option->period_from->toDateString(), 'to' => $option->period_to->toDateString(), 'when' => $option->created_at->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Rapporterna bygger på körningarna i körloggen. Kör en ny konsolidering under Konsolidering för att få med det som har bokförts eller eliminerats sedan.') }}</p>
        </x-ui.card>

        @if($report)
            <section class="space-y-6" aria-labelledby="report-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 id="report-heading" class="text-lg font-semibold text-gray-900">{{ __('Intern koncernrapport :from – :to', ['from' => $report['from'], 'to' => $report['to']]) }}</h2>
                        <p class="text-sm text-gray-600">{{ $current->name }} · {{ trans_choice('{1} ett företag ingår|[2,*] :count företag ingår', count($report['members']), ['count' => count($report['members'])]) }}: {{ implode(', ', $report['members']) }}</p>
                        <p class="text-xs text-gray-500">{{ __('Körning nr :id, gjord :when', ['id' => $report['run_id'], 'when' => $report['created_at']->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}@if($report['runner']) {{ __('av :name', ['name' => $report['runner']]) }}@endif · {{ __('Kontrollsumma') }} <span class="font-mono">{{ substr($report['output_hash'], 0, 12) }}</span></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if($report['preliminary'])
                            <x-ui.badge tone="warning">{{ __('Preliminär') }}</x-ui.badge>
                        @else
                            <x-ui.badge tone="success">{{ __('Låsta perioder') }}</x-ui.badge>
                        @endif
                        <a href="{{ route('group.reports.csv', ['run' => $report['run_id']]) }}" class="{{ $quiet }} print:hidden">{{ __('Ladda ner CSV') }}</a>
                        <a href="{{ route('group.reports.pdf', ['run' => $report['run_id']]) }}" class="{{ $quiet }} print:hidden">{{ __('Ladda ner PDF') }}</a>
                        <button type="button" class="{{ $quiet }} print:hidden" x-on:click="window.print()">{{ __('Skriv ut') }}</button>
                    </div>
                </div>

                <p class="hidden print:block text-sm font-semibold">{{ $report['label'] }}</p>

                @if(! $report['intact'] || ! $report['entries_intact'] || ! $report['consistent'])
                    <p class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ __('Körningen eller koncernjournalen stämmer inte med sina kontrollsummor. Använd inte siffrorna och kontakta den som driver NONOX.') }}</p>
                @endif
                @if($report['preliminary'])
                    <p class="text-sm text-gray-700" role="status">{{ __('Körningen är preliminär: minst ett av företagen har inte låst perioden eller stängt räkenskapsåret, så siffrorna kan ändras.') }}</p>
                @endif
                @if($report['excluded_count'] > 0)
                    <p class="text-sm text-gray-700" role="status">{{ trans_choice('{1} Ett företag i koncernen ingår inte i siffrorna.|[2,*] :count företag i koncernen ingår inte i siffrorna.', $report['excluded_count'], ['count' => $report['excluded_count']]) }}</p>
                @endif
                @if($report['cut'] > 0)
                    <p class="text-sm text-gray-600">{{ trans_choice('{1} Ett dotterföretag ingår bara för en del av perioden.|[2,*] :count dotterföretag ingår bara för en del av perioden.', $report['cut'], ['count' => $report['cut']]) }}</p>
                @endif
                @if($report['detailed'])
                    <p class="text-sm text-gray-600">{{ __('Företagen är företagens egen bokföring för de dagar de ingår i koncernen, summerad efter kontomappningen. Elimineringar är posterna i koncernjournalen som körningen drog. Koncernen är summan av båda.') }}</p>
                @endif

                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Resultaträkning') }}</h3>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm" data-group-report-income>
                            <caption class="sr-only">{{ __('Koncernens resultaträkning') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Konto') }}</th>
                                @if($report['detailed'])
                                    <th scope="col" class="{{ $th }}">{{ __('Företagen') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Elimineringar') }}</th>
                                @endif
                                <th scope="col" class="{{ $th }}">{{ __('Koncernen') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($report['income']['groups'] as $group)
                                    @if($group['rows'] !== [])
                                        <tr><th scope="rowgroup" colspan="{{ $columns }}" class="pt-4 pb-1 text-left font-semibold text-gray-900">{{ $group['label'] }}</th></tr>
                                        @foreach($group['rows'] as $row)
                                            @include('livewire.group.partials.group-report-row', ['label' => $row['number'].' '.$row['name'], 'columns' => $row, 'class' => 'border-b border-gray-100'])
                                        @endforeach
                                        @include('livewire.group.partials.group-report-row', ['label' => __('Summa :group', ['group' => mb_strtolower($group['label'])]), 'columns' => $group['total'], 'class' => 'border-b border-gray-200 font-medium'])
                                    @endif
                                    @if($group['subtotal'])
                                        @include('livewire.group.partials.group-report-row', ['label' => $group['subtotal']['label'], 'columns' => $group['subtotal'], 'class' => 'border-b border-gray-200 bg-stone-50 font-semibold'])
                                    @endif
                                @endforeach
                                @include('livewire.group.partials.group-report-row', ['label' => __('Periodens resultat'), 'columns' => $report['income']['result'], 'class' => 'font-semibold text-gray-900'])
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Balansräkning :date', ['date' => $report['to']]) }}</h3>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm" data-group-report-balance>
                            <caption class="sr-only">{{ __('Koncernens balansräkning') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Konto') }}</th>
                                @if($report['detailed'])
                                    <th scope="col" class="{{ $th }}">{{ __('Företagen') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Elimineringar') }}</th>
                                @endif
                                <th scope="col" class="{{ $th }}">{{ __('Koncernen') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($report['balance'] as $sideKey => $side)
                                    <tr><th scope="rowgroup" colspan="{{ $columns }}" class="pt-4 pb-1 text-left text-base font-semibold text-gray-900">{{ $side['label'] }}</th></tr>
                                    @foreach($side['groups'] as $group)
                                        @if($group['rows'] !== [])
                                            <tr><th scope="rowgroup" colspan="{{ $columns }}" class="pt-2 pb-1 text-left font-medium text-gray-700">{{ $group['label'] }}</th></tr>
                                            @foreach($group['rows'] as $row)
                                                @include('livewire.group.partials.group-report-row', ['label' => $row['number'].' '.$row['name'], 'columns' => $row, 'class' => 'border-b border-gray-100'])
                                            @endforeach
                                        @endif
                                    @endforeach
                                    @if($sideKey === 'equity' && ($side['unbooked']['group'] !== 0 || ($side['unbooked']['companies'] ?? 0) !== 0))
                                        @include('livewire.group.partials.group-report-row', ['label' => __('Årets resultat som ännu inte är bokfört mot eget kapital'), 'columns' => $side['unbooked'], 'class' => 'border-b border-gray-100'])
                                    @endif
                                    @include('livewire.group.partials.group-report-row', ['label' => __('Summa :side', ['side' => mb_strtolower($side['label'])]), 'columns' => $side['total'], 'class' => 'border-b border-gray-300 font-semibold'])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-gray-600">{{ __('Beloppen summeras konto för konto över företagen, som är olika juridiska personer. En skattefordran i ett företag kan därför minska en skatteskuld i ett annat, och ett negativt banksaldo i ett företag kan minska ett annat företags bankmedel. I företagens egna redovisningar kvittas sådana belopp inte.') }}</p>
                    @if($report['result_outside_period'] !== 0)
                        <p class="mt-3 text-xs text-gray-600">{{ __('Årets resultat i balansräkningen skiljer sig med :amount från periodens resultat ovan. Skillnaden är resultat före periodens början, ett dotterföretags resultat före förvärvet och resultatet i ett dotterföretag som avyttrats under perioden. Någon förvärvsanalys görs inte i den interna rapporten.', ['amount' => $m($report['result_outside_period'])->format()]) }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Elimineringar per slag') }}</h3>
                    @if($report['eliminations'] === [])
                        <p class="mt-1 text-sm text-gray-700">{{ __('Inga elimineringar är godkända för den här perioden. Siffrorna är företagens summor.') }}</p>
                    @else
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach($report['eliminations'] as $elimination)
                                <li class="flex justify-between gap-4 border-b border-gray-100 py-1">
                                    <span>{{ $elimination['label'] }}</span>
                                    <span class="text-right">
                                        @if($elimination['amount'] === null)
                                            {{ trans_choice('{1} en post|[2,*] :count poster', $elimination['entries'], ['count' => $elimination['entries']]) }}
                                        @else
                                            <x-ui.amount :money="$m($elimination['amount'])" />
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        @if(! $report['detailed'] && collect($report['eliminations'])->contains(fn (array $elimination): bool => $elimination['amount'] === null))
                            <p class="mt-3 text-xs text-gray-600">{{ __('För ett slag som bara gäller ett par av företagen visas antalet poster i stället för beloppet.') }}</p>
                        @endif
                    @endif
                    @if($report['skipped_entries'] > 0)
                        <p class="mt-3 text-sm text-amber-800" role="status">{{ trans_choice('{1} En post i koncernjournalen för perioden är inte dragen, eftersom den gäller ett företag som inte ingår i körningen.|[2,*] :count poster i koncernjournalen för perioden är inte dragna, eftersom de gäller ett företag som inte ingår i körningen.', $report['skipped_entries'], ['count' => $report['skipped_entries']]) }}</p>
                    @endif
                </x-ui.card>
            </section>
        @elseif($withheld)
            <x-ui.card>
                <x-ui.empty-state :title="__('Körningen visas inte')">
                    {{ __('Den här körningen kan inte visas för den som bara ser koncernens summor, eftersom den har färre än två företag eller något företag har lämnats utanför eller bara ingår en del av perioden.') }}
                    {{ __('Den visas inte heller för den som inte får se rapporter i alla företag som körningen läste, till exempel ett företag som har lämnat koncernen sedan dess.') }}
                </x-ui.empty-state>
            </x-ui.card>
        @elseif($current)
            <x-ui.card>
                <x-ui.empty-state :title="__('Ingen körning ännu')">
                    {{ __('Rapporterna bygger på en sparad konsolidering. Gör en körning under Konsolidering, så visas den här.') }}
                    <a href="{{ route('group.consolidation', ['koncern' => $current->id]) }}" class="{{ $quiet }}" wire:navigate>{{ __('Till Konsolidering') }}</a>
                </x-ui.empty-state>
            </x-ui.card>
        @endif
    @endif
</div>
