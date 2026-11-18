@php
    use App\Domain\Group\GroupAccessLevel;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $button = 'rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-hidden focus:ring-2 focus:ring-accent-500 focus:ring-offset-2';
    $quiet = 'text-sm font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline';
    $m = fn (int $ore): Money => Money::ofOre($ore);
    $th = 'py-2 pl-4 text-right font-medium';
    $td = 'py-2 pl-4 text-right';
@endphp

<div class="space-y-6">
    {{-- ADR-060 2.4 and S2-R14: every consolidated screen and print says what it is. --}}
    <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
        <p class="font-semibold">{{ __('Intern koncernrapport, inte en koncernredovisning enligt ÅRL') }}</p>
        <p class="mt-1">{{ __('Siffrorna är företagens egen bokföring summerad efter koncernens kontomappning, med de elimineringar som är godkända i koncernjournalen för exakt samma period. Ingenting bokförs i något av företagen.') }}</p>
    </div>

    @if($groups->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Du ser ingen koncern')">
                {{ __('En koncern visas när du får se rapporter i alla dess företag, eller när du har fått rollen att läsa koncernens sammanställning. Koncerner skapas under Koncernstruktur.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card class="print:hidden">
            <form wire:submit="consolidate" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="consolidation_group" :label="__('Koncern')" class="min-w-56">
                    <select id="consolidation_group" class="{{ $input }} mt-1" wire:change="selectGroup($event.target.value)">
                        @foreach($groups as $option)
                            <option value="{{ $option->id }}" @selected($current && $option->id === $current->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="consolidation_from" :label="__('Från och med')">
                    <input id="consolidation_from" type="date" class="{{ $input }} mt-1" wire:model="from" required>
                </x-ui.field>
                <x-ui.field for="consolidation_to" :label="__('Till och med')">
                    <input id="consolidation_to" type="date" class="{{ $input }} mt-1" wire:model="to" required>
                </x-ui.field>
                <button type="submit" class="{{ $button }}" wire:loading.attr="disabled" wire:target="consolidate">{{ __('Kör konsolidering') }}</button>
            </form>
            <p class="mt-3 text-xs text-gray-500">{{ __('Perioden måste ligga inom ett av moderföretagets räkenskapsår. Ett dotterföretags intäkter och kostnader räknas från förvärvsdagen och fram till avyttringen, och dess balans tas med om det ingår i koncernen periodens sista dag. Dotterföretagets räkenskapsår måste omfatta de dagarna, annars tas det inte med.') }}</p>
        </x-ui.card>

        @if($view)
            <section class="space-y-6" aria-labelledby="run-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 id="run-heading" class="text-lg font-semibold text-gray-900">{{ __('Konsoliderat :from – :to', ['from' => $view['from'], 'to' => $view['to']]) }}</h2>
                        <p class="text-sm text-gray-600">{{ $current->name }} · {{ trans_choice('{1} ett företag ingår|[2,*] :count företag ingår', count($view['members']), ['count' => count($view['members'])]) }}: {{ implode(', ', $view['members']) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if($view['preliminary'])
                            <x-ui.badge tone="warning">{{ __('Preliminär') }}</x-ui.badge>
                        @else
                            <x-ui.badge tone="success">{{ __('Låsta perioder') }}</x-ui.badge>
                        @endif
                        <button type="button" class="{{ $quiet }} print:hidden" wire:click="check({{ $selected->id }})">{{ __('Kontrollera mot bokföringen') }}</button>
                        {{-- The same run as group reports, with CSV and PDF (ADR-081 E26). --}}
                        <a href="{{ route('group.reports', ['koncern' => $current->id, 'korning' => $selected->id]) }}" class="{{ $quiet }} print:hidden" wire:navigate>{{ __('Koncernrapporter') }}</a>
                        <button type="button" class="{{ $quiet }} print:hidden" x-on:click="window.print()">{{ __('Skriv ut') }}</button>
                    </div>
                </div>

                <p class="hidden print:block text-sm font-semibold">{{ $view['label'] }}</p>

                @if($view['preliminary'])
                    <p class="text-sm text-gray-700" role="status">{{ __('Körningen är preliminär: minst ett av företagen har inte låst perioden eller stängt räkenskapsåret, så siffrorna kan ändras.') }}</p>
                @endif

                @if($view['excluded_count'] > 0)
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700" role="status">
                        <p class="font-medium">{{ trans_choice('{1} Ett företag i koncernen ingår inte i siffrorna.|[2,*] :count företag i koncernen ingår inte i siffrorna.', $view['excluded_count'], ['count' => $view['excluded_count']]) }}</p>
                        @if($view['excluded'] !== [])
                            <ul class="mt-1 list-disc ps-5">
                                @foreach($view['excluded'] as $excluded)
                                    <li>{{ $excluded['name'] }}: {{ $excluded['reason'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                <div class="grid gap-6 xl:grid-cols-2">
                    <x-ui.card>
                        <h3 class="font-semibold text-gray-900">{{ __('Resultaträkning') }}</h3>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Konsoliderad resultaträkning') }}</caption>
                                <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Konto') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Koncernen') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($view['income'] as $group)
                                        @if($group['rows'] !== [])
                                            <tr><th scope="rowgroup" colspan="2" class="pt-4 pb-1 text-left font-semibold text-gray-900">{{ $group['label'] }}</th></tr>
                                            @foreach($group['rows'] as $row)
                                                <tr class="border-b border-gray-100">
                                                    <td class="py-1.5 pr-4"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</td>
                                                    <td class="{{ $td }} py-1.5"><x-ui.amount :money="$m($row['amount'])" /></td>
                                                </tr>
                                            @endforeach
                                            <tr class="border-b border-gray-200 font-medium">
                                                <td class="py-1.5 pr-4">{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</td>
                                                <td class="{{ $td }} py-1.5"><x-ui.amount :money="$m($group['total'])" /></td>
                                            </tr>
                                        @endif
                                        @if($group['subtotal'])
                                            <tr class="border-b border-gray-200 bg-stone-50 font-semibold">
                                                <td class="py-2 pr-4 ps-2">{{ $group['subtotal']['label'] }}</td>
                                                <td class="{{ $td }} pr-2"><x-ui.amount :money="$m($group['subtotal']['amount'])" /></td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    <tr class="font-semibold text-gray-900">
                                        <td class="py-3 pr-4">{{ __('Periodens resultat') }}</td>
                                        <td class="{{ $td }} py-3"><x-ui.amount :money="$m($view['result'])" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="font-semibold text-gray-900">{{ __('Balansräkning :date', ['date' => $view['to']]) }}</h3>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Konsoliderad balansräkning') }}</caption>
                                <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Konto') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Koncernen') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($view['balance'] as $sideKey => $side)
                                        <tr><th scope="rowgroup" colspan="2" class="pt-4 pb-1 text-left text-base font-semibold text-gray-900">{{ $side['label'] }}</th></tr>
                                        @foreach($side['groups'] as $group)
                                            @if($group['rows'] !== [])
                                                <tr><th scope="rowgroup" colspan="2" class="pt-2 pb-1 text-left font-medium text-gray-700">{{ $group['label'] }}</th></tr>
                                                @foreach($group['rows'] as $row)
                                                    <tr class="border-b border-gray-100">
                                                        <td class="py-1.5 pr-4"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</td>
                                                        <td class="{{ $td }} py-1.5"><x-ui.amount :money="$m($row['amount'])" /></td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                        @endforeach
                                        @if($sideKey === 'equity' && $view['unbooked_result'] !== 0)
                                            <tr class="border-b border-gray-100">
                                                <td class="py-1.5 pr-4">{{ __('Årets resultat som ännu inte är bokfört mot eget kapital') }}</td>
                                                <td class="{{ $td }} py-1.5"><x-ui.amount :money="$m($view['unbooked_result'])" /></td>
                                            </tr>
                                        @endif
                                        <tr class="border-b border-gray-300 font-semibold">
                                            <td class="py-2 pr-4">{{ __('Summa :side', ['side' => mb_strtolower($side['label'])]) }}</td>
                                            <td class="{{ $td }}"><x-ui.amount :money="$m($side['total'])" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3 text-xs text-gray-600">{{ __('Beloppen summeras konto för konto över företagen, som är olika juridiska personer. En skattefordran i ett företag kan därför minska en skatteskuld i ett annat, och ett negativt banksaldo i ett företag kan minska ett annat företags bankmedel. I företagens egna redovisningar kvittas sådana belopp inte.') }}</p>
                        @if($view['result_outside_period'] !== 0)
                            <p class="mt-3 text-xs text-gray-600">{{ __('Årets resultat i balansräkningen skiljer sig med :amount från periodens resultat ovan. Skillnaden är resultat före periodens början, ett dotterföretags resultat före förvärvet och resultatet i ett dotterföretag som avyttrats under perioden. Någon förvärvsanalys görs inte i den interna rapporten.', ['amount' => $m($view['result_outside_period'])->format()]) }}</p>
                        @endif
                    </x-ui.card>
                </div>

                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Elimineringar') }}</h3>
                    @if($view['eliminations'] === [])
                        <p class="mt-1 text-sm text-gray-700">{{ __('Inga elimineringar är godkända för den här perioden. Siffrorna är företagens summor.') }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">{{ trans_choice('{1} En post i koncernjournalen är dragen från siffrorna.|[2,*] :count poster i koncernjournalen är dragna från siffrorna.', $view['eliminated_entries'], ['count' => $view['eliminated_entries']]) }}</p>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Eliminerat per slag') }}</caption>
                                <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Slag') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Eliminerat belopp') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($view['eliminations'] as $elimination)
                                        <tr class="border-b border-gray-100">
                                            <td class="py-1.5 pr-4">{{ $elimination['label'] }}</td>
                                            <td class="{{ $td }} py-1.5">
                                                @if($elimination['amount'] === null)
                                                    {{ trans_choice('{1} en post|[2,*] :count poster', $elimination['entries'], ['count' => $elimination['entries']]) }}
                                                @else
                                                    <x-ui.amount :money="$m($elimination['amount'])" />
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if($view['level'] !== GroupAccessLevel::Full && collect($view['eliminations'])->contains(fn (array $elimination): bool => $elimination['amount'] === null))
                        <p class="mt-3 text-xs text-gray-600">{{ __('För ett slag som bara gäller ett par av företagen visas antalet poster i stället för beloppet.') }}</p>
                    @endif
                    @if($view['skipped_entries'] > 0)
                        <p class="mt-3 text-sm text-amber-800" role="status">{{ trans_choice('{1} En post i koncernjournalen för perioden är inte dragen, eftersom den gäller ett företag som inte ingår i körningen.|[2,*] :count poster i koncernjournalen för perioden är inte dragna, eftersom de gäller ett företag som inte ingår i körningen.', $view['skipped_entries'], ['count' => $view['skipped_entries']]) }}</p>
                    @endif
                    @if($view['level'] === GroupAccessLevel::Full)
                        <p class="mt-3 text-sm print:hidden"><a href="{{ route('group.eliminations', ['koncern' => $current->id, 'fran' => $view['from'], 'till' => $view['to']]) }}" class="{{ $quiet }}" wire:navigate>{{ __('Förslag och koncernjournal för perioden') }}</a></p>
                    @endif
                </x-ui.card>

                @if($view['level'] === GroupAccessLevel::Full && $view['companies'] !== [])
                    <x-ui.card class="print:hidden">
                        <h3 class="font-semibold text-gray-900">{{ __('Vad körningen läste') }}</h3>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Företagen i körningen') }}</caption>
                                <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Resultat för') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Balans') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Bokförda verifikationer') }}</th>
                                    <th scope="col" class="py-2 pl-4 text-left font-medium">{{ __('Status') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($view['companies'] as $company)
                                        <tr class="border-b border-gray-100">
                                            <td class="py-2 pr-4">{{ $company['name'] }} @if($company['role'] === 'parent')<span class="text-xs text-gray-500">{{ __('moderföretag') }}</span>@endif</td>
                                            <td class="py-2 pr-4 tabular-nums">{{ $company['income_from'] ? $company['income_from'].' – '.$company['income_to'] : __('inga dagar') }}</td>
                                            <td class="py-2 pr-4">{{ $company['balance'] ? __('ingår') : __('ingår inte') }}</td>
                                            <td class="{{ $td }} tabular-nums">{{ $company['posted_vouchers'] }}</td>
                                            <td class="py-2 pl-4">{{ $company['fiscal_year_closed'] ? __('året är stängt') : ($company['preliminary'] ? __('perioden är inte låst') : __('perioden är låst')) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @elseif($view['cut'] > 0)
                    <p class="text-sm text-gray-600">{{ trans_choice('{1} Ett dotterföretag ingår bara för en del av perioden.|[2,*] :count dotterföretag ingår bara för en del av perioden.', $view['cut'], ['count' => $view['cut']]) }}</p>
                @endif
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
                    {{ __('Välj period och Kör konsolidering. Körningen sparas i körloggen så att du kan se samma siffror igen.') }}
                </x-ui.empty-state>
            </x-ui.card>
        @endif

        @if($runs->isNotEmpty())
            <x-ui.card class="print:hidden">
                <h3 class="font-semibold text-gray-900">{{ __('Körlogg') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Varje körning sparas med kontrollsummor för det den läste och det den räknade fram. Samma indata ger alltid samma siffror. Körningar kan inte ändras eller tas bort.') }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">{{ __('Körlogg') }}</caption>
                        <thead class="text-gray-600"><tr class="border-b border-gray-200">
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Körd') }}</th>
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Period') }}</th>
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Av') }}</th>
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Kontrollsumma') }}</th>
                            <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                        </tr></thead>
                        <tbody>
                            @foreach($runs as $run)
                                <tr @class(['border-b border-gray-100', 'bg-stone-50' => $selected && $selected->id === $run->id]) wire:key="run-{{ $run->id }}">
                                    <td class="py-2 pr-4 tabular-nums">{{ $run->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $run->period_from->toDateString() }} – {{ $run->period_to->toDateString() }} @if($run->preliminary)<x-ui.badge tone="warning" class="ms-1">{{ __('Preliminär') }}</x-ui.badge>@endif</td>
                                    <td class="py-2 pr-4">{{ $run->runner?->name ?? __('Okänd') }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs text-gray-600" title="{{ __('Indata :inputs, resultat :output', ['inputs' => $run->inputs_hash, 'output' => $run->output_hash]) }}">{{ substr($run->output_hash, 0, 12) }}</td>
                                    <td class="py-2 text-right">
                                        @if(! $selected || $selected->id !== $run->id)
                                            <button type="button" class="{{ $quiet }}" wire:click="selectRun('{{ $run->id }}')">{{ __('Visa') }}</button>
                                        @else
                                            <span class="text-xs text-gray-500">{{ __('Visas') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        @if($level === GroupAccessLevel::Full)
            <x-ui.card class="print:hidden">
                <h3 class="font-semibold text-gray-900">{{ __('Kontomappning') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Som standard hamnar varje konto på samma nummer i koncernen. Lägg bara in de konton som ska hamna på ett annat koncernkonto, inom samma kontoklass. En ändring gäller från nästa körning.') }}</p>

                @if($administers)
                    <form wire:submit="saveMap" class="mt-4 flex flex-wrap items-end gap-2">
                        <x-ui.field for="map_company" :label="__('Företag')">
                            <select id="map_company" class="{{ $input }} mt-1" wire:model.live="mapCompany">
                                <option value="">{{ __('Välj företag') }}</option>
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="map_account" :label="__('Företagets konto')">
                            <input id="map_account" type="text" inputmode="numeric" maxlength="4" list="map_accounts" class="{{ $input }} mt-1 w-28" wire:model="mapAccount" placeholder="3011">
                            <datalist id="map_accounts">
                                @foreach($accounts as $account)
                                    <option value="{{ $account->number }}">{{ $account->name }}</option>
                                @endforeach
                            </datalist>
                        </x-ui.field>
                        <x-ui.field for="map_group_account" :label="__('Koncernkonto')">
                            <input id="map_group_account" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} mt-1 w-28" wire:model="mapGroupAccount" placeholder="3010">
                        </x-ui.field>
                        <button type="submit" class="{{ $button }}">{{ __('Spara mappning') }}</button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-gray-600">{{ __('Bara den som är ägare eller administratör i alla företag i koncernen kan ändra kontomappningen.') }}</p>
                @endif

                @if($maps->isEmpty())
                    <p class="mt-4 text-sm text-gray-700">{{ __('Inga avvikelser. Alla konton hamnar på sina egna nummer.') }}</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ __('Kontomappning') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företagets konto') }}</th>
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Koncernkonto') }}</th>
                                <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                            </tr></thead>
                            <tbody>
                                @foreach($maps as $map)
                                    <tr class="border-b border-gray-100" wire:key="map-{{ $map->id }}">
                                        <td class="py-2 pr-4">{{ $names[$map->company_id] ?? '' }}</td>
                                        <td class="py-2 pr-4 tabular-nums">{{ $map->account_number }}</td>
                                        <td class="py-2 pr-4 tabular-nums">{{ $map->group_account_number }}</td>
                                        <td class="py-2 text-right">
                                            @if($administers)
                                                <span x-data="{ sure: false }">
                                                    <button type="button" class="text-sm font-medium text-red-700 hover:underline focus:outline-hidden focus:underline" x-show="! sure" x-on:click="sure = true" aria-label="{{ __('Ta bort mappningen av konto :number', ['number' => $map->account_number]) }}">{{ __('Ta bort') }}</button>
                                                    <span x-show="sure" x-cloak class="inline-flex flex-wrap items-center gap-2">
                                                        <span class="text-xs text-gray-700">{{ __('Kontot hamnar på sitt eget nummer från nästa körning.') }}</span>
                                                        <button type="button" class="text-sm font-medium text-red-700 hover:underline" wire:click="removeMap({{ $map->id }})">{{ __('Ja, ta bort') }}</button>
                                                        <button type="button" class="{{ $quiet }}" x-on:click="sure = false">{{ __('Avbryt') }}</button>
                                                    </span>
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
