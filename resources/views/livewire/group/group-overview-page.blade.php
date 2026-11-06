@php
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $button = 'rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-hidden focus:ring-2 focus:ring-accent-500 focus:ring-offset-2';
    $quiet = 'text-sm font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline';
    $m = fn (int $ore): Money => Money::ofOre($ore);
    $tabs = [
        'resultat' => __('Resultat'),
        'balans' => __('Balans'),
        'elimineringar' => __('Elimineringsförslag'),
        'installningar' => __('Koncernen och markeringar'),
    ];
    $th = 'py-2 pl-4 text-right font-medium';
    $td = 'py-2 pl-4 text-right';
    $first = 'sticky left-0 z-[1] bg-white py-2 pr-4 text-left max-sm:min-w-40';
@endphp

<div class="space-y-6">
    {{-- ADR-060: this is internal reporting and must say so on the page, in print too. --}}
    <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
        <p class="font-semibold">{{ __('Intern översikt, inte en koncernredovisning') }}</p>
        <p class="mt-1">{{ __('Siffrorna är bara en sammanställning av företagens egen bokföring för ditt eget underlag. Det är ingen koncernredovisning enligt årsredovisningslagen: ingenting konsolideras, ingen bokföring ändras och elimineringarna är förslag som inte bokförs någonstans.') }}</p>
    </div>

    <div class="flex flex-wrap items-end gap-4 print:hidden">
        @if($groups->isNotEmpty())
            <x-ui.field for="group_select" :label="__('Koncern')" class="min-w-56">
                <select id="group_select" class="{{ $input }} mt-1" wire:change="select($event.target.value)">
                    @foreach($groups as $option)
                        <option value="{{ $option->id }}" @selected($current && $option->id === $current->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
        <form wire:submit="create" class="flex flex-wrap items-end gap-2">
            <x-ui.field for="new_group" :label="__('Ny koncern')">
                <input id="new_group" type="text" maxlength="100" class="{{ $input }} mt-1" wire:model="newName" placeholder="{{ __('Till exempel Holding och dotterbolag') }}">
            </x-ui.field>
            <button type="submit" class="{{ $button }}">{{ __('Skapa koncern') }}</button>
        </form>
    </div>

    @if(! $current)
        <x-ui.card>
            <x-ui.empty-state :title="__('Du har ingen koncern ännu')">
                {{ __('Skapa en koncern ovan och lägg sedan till de företag där du får se rapporter. Du ser bara företag som du själv är medlem i.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Koncernöversikt') }}">
            @foreach($tabs as $key => $label)
                <button type="button" wire:click="show('{{ $key }}')" @class([
                    'rounded-full px-4 py-1.5 text-sm font-medium focus:outline-hidden focus:ring-2 focus:ring-accent-500',
                    'bg-gray-900 text-white' => $tab === $key,
                    'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $tab !== $key,
                ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
        </nav>

        <h2 class="hidden print:block text-lg font-semibold">{{ $current->name }} · {{ __('Intern översikt, inte en koncernredovisning') }}</h2>

        @if($hidden > 0)
            <p class="text-sm text-gray-600" role="status">{{ trans_choice('{1} Ett företag i koncernen visas inte, eftersom du inte har tillgång till dess rapporter.|[2,*] :count företag i koncernen visas inte, eftersom du inte har tillgång till deras rapporter.', $hidden, ['count' => $hidden]) }}</p>
        @endif

        @if($tab === 'installningar')
            <div class="grid gap-6 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Namn') }}</h3>
                    <form wire:submit="rename" class="mt-3 flex flex-wrap items-end gap-2">
                        <x-ui.field for="rename_group" :label="__('Koncernens namn')">
                            <input id="rename_group" type="text" maxlength="100" class="{{ $input }} mt-1" wire:model="renameTo">
                        </x-ui.field>
                        <button type="submit" class="{{ $button }}">{{ __('Spara namnet') }}</button>
                    </form>
                    <div class="mt-4 border-t border-gray-200 pt-4" x-data="{ sure: false }">
                        <button type="button" class="text-sm font-medium text-red-700 hover:underline focus:outline-hidden focus:underline" x-show="! sure" x-on:click="sure = true">{{ __('Ta bort koncernen') }}</button>
                        <div x-show="sure" x-cloak class="space-y-2">
                            <p class="text-sm text-gray-700">{{ __('Koncernen och dina markeringar tas bort. Inget i företagens bokföring ändras.') }}</p>
                            <button type="button" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 focus:outline-hidden focus:ring-2 focus:ring-red-500 focus:ring-offset-2" wire:click="deleteGroup">{{ __('Ja, ta bort koncernen') }}</button>
                            <button type="button" class="{{ $quiet }}" x-on:click="sure = false">{{ __('Avbryt') }}</button>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Företag i koncernen') }}</h3>
                    @if($members->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">{{ __('Inga företag ännu. Lägg till de företag där du får se rapporter.') }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100">
                            @foreach($members as $row)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="member-{{ $row['company']->id }}">
                                    <span class="text-sm text-gray-900">{{ $row['company']->name }} <span class="text-gray-500 tabular-nums">{{ $row['company']->org_number }}</span></span>
                                    <span class="flex items-center gap-3">
                                        <label class="flex items-center gap-1 text-sm text-gray-600">
                                            <span class="sr-only">{{ __('Ägarandel i procent för :company', ['company' => $row['company']->name]) }}</span>
                                            <input type="text" inputmode="decimal" class="w-20 rounded-lg border-gray-300 py-1 text-right text-sm tabular-nums focus:border-accent-500 focus:ring-accent-500" value="{{ $row['member']->ownership_bp === null ? '' : rtrim(rtrim(number_format($row['member']->ownership_bp / 100, 2, ',', ''), '0'), ',') }}" placeholder="{{ __('Andel') }}" wire:change="setShare({{ $row['company']->id }}, $event.target.value)"> %
                                        </label>
                                        <button type="button" class="text-sm font-medium text-red-700 hover:underline focus:outline-hidden focus:underline" wire:click="remove({{ $row['company']->id }})" aria-label="{{ __('Ta bort :company ur koncernen', ['company' => $row['company']->name]) }}">{{ __('Ta bort') }}</button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($pickable->isNotEmpty())
                        <form wire:submit="add" class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-200 pt-4">
                            <x-ui.field for="add_company" :label="__('Lägg till företag')">
                                <select id="add_company" class="{{ $input }} mt-1" wire:model="addCompany">
                                    <option value="">{{ __('Välj företag') }}</option>
                                    @foreach($pickable as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field for="add_share" :label="__('Ägarandel, procent (frivilligt)')">
                                <input id="add_share" type="text" inputmode="decimal" class="{{ $input }} mt-1 w-28" wire:model="addShare" placeholder="100">
                            </x-ui.field>
                            <button type="submit" class="{{ $button }}">{{ __('Lägg till') }}</button>
                        </form>
                    @endif
                    <p class="mt-3 text-xs text-gray-500">{{ __('Ägarandelen visas bara. Den påverkar inga belopp.') }}</p>
                </x-ui.card>
            </div>

            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900">{{ __('Koncerninterna konton') }}</h3>
                        <p class="text-sm text-gray-600">{{ __('Markera de konton som gäller koncernföretag, så kan elimineringsförslagen räknas. Du kan också låta NONOX föreslå konton med "koncern" i namnet.') }}</p>
                    </div>
                    <button type="button" class="{{ $quiet }}" wire:click="suggest">{{ __('Föreslå markeringar') }}</button>
                </div>

                @if($members->isNotEmpty())
                    <form wire:submit="mark" class="mt-4 flex flex-wrap items-end gap-2">
                        <x-ui.field for="mark_company" :label="__('Företag')">
                            <select id="mark_company" class="{{ $input }} mt-1" wire:model.live="markCompany">
                                <option value="">{{ __('Välj företag') }}</option>
                                @foreach($members as $row)
                                    <option value="{{ $row['company']->id }}">{{ $row['company']->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="mark_account" :label="__('Konto')">
                            <input id="mark_account" type="text" inputmode="numeric" maxlength="4" list="mark_accounts" class="{{ $input }} mt-1 w-28" wire:model="markAccount" placeholder="1660">
                            <datalist id="mark_accounts">
                                @foreach($accounts as $account)
                                    <option value="{{ $account->number }}">{{ $account->name }}</option>
                                @endforeach
                            </datalist>
                        </x-ui.field>
                        <x-ui.field for="mark_kind" :label="__('Vad kontot innehåller')">
                            <select id="mark_kind" class="{{ $input }} mt-1" wire:model="markKind">
                                <option value="">{{ __('Välj') }}</option>
                                @foreach($kinds as $kind)
                                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <button type="submit" class="{{ $button }}">{{ __('Markera konto') }}</button>
                    </form>
                @endif

                @if($marks->isNotEmpty())
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ __('Markerade konton') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Konto') }}</th>
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Innehåll') }}</th>
                                <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                            </tr></thead>
                            <tbody>
                                @foreach($marks as $mark)
                                    <tr class="border-b border-gray-100" wire:key="mark-{{ $mark->id }}">
                                        <td class="py-2 pr-4">{{ $names[$mark->company_id] }}</td>
                                        <td class="py-2 pr-4 tabular-nums">{{ $mark->account_number }}</td>
                                        <td class="py-2 pr-4">{{ \App\Domain\Group\MarkKind::tryFrom($mark->kind)?->label() }}</td>
                                        <td class="py-2 text-right"><button type="button" class="text-sm font-medium text-red-700 hover:underline focus:outline-hidden focus:underline" wire:click="unmark({{ $mark->id }})" aria-label="{{ __('Ta bort markeringen av konto :number i :company', ['number' => $mark->account_number, 'company' => $names[$mark->company_id]]) }}">{{ __('Ta bort') }}</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @elseif($report)
            <x-ui.card class="print:hidden">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.field for="group_year" :label="__('Räkenskapsår')">
                        <select id="group_year" class="{{ $input }} mt-1" wire:model.live="year">
                            @foreach($report['years'] as $option)
                                <option value="{{ $option['key'] }}" @selected($option['key'] === $report['year'])>{{ $option['label'] }} ({{ trans_choice('{1} ett företag|[2,*] :count företag', $option['companies'], ['count' => $option['companies']]) }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="group_till" :label="__('Till och med')">
                        <input id="group_till" type="date" class="{{ $input }} mt-1" wire:model.live.blur="till" value="{{ $report['till']?->toDateString() }}">
                    </x-ui.field>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-end gap-4">
                    <a href="{{ route('group.csv', ['group' => $current->id, 'rapport' => $tab, 'ar' => $report['year'], 'till' => $report['till']?->toDateString()]) }}" class="{{ $quiet }}">{{ __('Hämta som CSV') }}</a>
                    <button type="button" class="{{ $quiet }}" x-on:click="window.print()">{{ __('Skriv ut') }}</button>
                </div>
            </x-ui.card>

            @if($report['year'])
                <p class="text-sm text-gray-600">{{ __('Period :period.', ['period' => \App\Domain\Group\GroupOverview::describe($report['year'])]) }} @if($report['till']) {{ __('Till och med :date.', ['date' => $report['till']->toDateString()]) }} @endif</p>
            @endif

            @if($report['excluded'] !== [])
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700" role="status">
                    <p class="font-medium">{{ __('Dessa företag ingår inte i siffrorna, eftersom deras räkenskapsår inte stämmer med det du valt:') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach($report['excluded'] as $excluded)
                            <li>{{ $excluded['name'] }}: {{ $excluded['reason'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($report['companies'] === [])
                <x-ui.card>
                    <x-ui.empty-state :title="__('Inga företag att visa')">
                        {{ __('Lägg till företag i koncernen under Koncernen och markeringar. Du ser bara företag där du får se rapporter.') }}
                    </x-ui.empty-state>
                </x-ui.card>
            @elseif($tab === 'elimineringar')
                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Förslag på elimineringar') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Förslag är inte bokningar. Det mindre beloppet i varje par är det som skulle kunna elimineras, och skillnaden är något att reda ut.') }}</p>
                    @if($marks->isEmpty())
                        <p class="mt-3 text-sm text-gray-700">{{ __('Inga konton är markerade ännu. Markera koncerninterna konton under Koncernen och markeringar.') }}</p>
                    @endif
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ __('Förslag på elimineringar') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Par') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Ena sidan') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Andra sidan') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Kan elimineras') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Skillnad') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($report['eliminations'] as $pair)
                                    <tr class="border-b border-gray-100">
                                        <th scope="row" class="py-2 pr-4 text-left font-medium text-gray-900">{{ $pair['label'] }}</th>
                                        <td class="{{ $td }}"><span class="block text-xs text-gray-500">{{ $pair['left_label'] }}</span><x-ui.amount :money="$m($pair['left'])" /></td>
                                        <td class="{{ $td }}"><span class="block text-xs text-gray-500">{{ $pair['right_label'] }}</span><x-ui.amount :money="$m($pair['right'])" /></td>
                                        <td class="{{ $td }} font-medium"><x-ui.amount :money="$m($pair['eliminate'])" /></td>
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($pair['difference'])" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <h4 class="mt-6 font-medium text-gray-900">{{ __('Summor före och efter förslagen') }}</h4>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ __('Summor före och efter förslagen') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Summa') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Före') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Efter förslagen') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($report['adjusted'] as $total)
                                    <tr class="border-b border-gray-100">
                                        <th scope="row" class="py-2 pr-4 text-left font-medium text-gray-900">{{ $total['label'] }}</th>
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($total['before'])" /></td>
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($total['after'])" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

                @if($report['marks'] !== [])
                    <x-ui.card>
                        <h3 class="font-semibold text-gray-900">{{ __('Markerade konton och belopp') }}</h3>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Markerade konton och belopp') }}</caption>
                                <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Företag') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Konto') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Innehåll') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Belopp') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($report['marks'] as $row)
                                        <tr class="border-b border-gray-100">
                                            <td class="py-2 pr-4">{{ $row['company'] }}</td>
                                            <td class="py-2 pr-4"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</td>
                                            <td class="py-2 pr-4">{{ $row['kind']->label() }}</td>
                                            <td class="{{ $td }}"><x-ui.amount :money="$m($row['amount'])" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @endif
            @else
                @php
                    $sections = $tab === 'resultat'
                        ? [['label' => null, 'groups' => $report['income']['groups'], 'unbooked' => null, 'total' => ['label' => __('Årets resultat'), 'amounts' => $report['income']['result']['amounts'], 'sum' => $report['income']['result']['sum']]]]
                        : collect($report['balance']['sides'])->map(fn ($side) => ['label' => $side['label'], 'groups' => $side['groups'], 'unbooked' => $side['unbooked']['sum'] !== 0 ? $side['unbooked'] : null, 'total' => ['label' => $side['label'], 'amounts' => $side['totals'], 'sum' => $side['sum']]])->values()->all();
                @endphp
                <x-ui.card>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ $tab === 'resultat' ? __('Resultat per företag och summa') : __('Balans per företag och summa') }}</caption>
                            <thead class="text-gray-600"><tr class="border-b border-gray-200">
                                <th scope="col" class="{{ $first }} font-medium">{{ __('Konto') }}</th>
                                @foreach($report['companies'] as $company)
                                    <th scope="col" class="{{ $th }}">{{ $company['name'] }}@if($company['ownership_bp'] !== null)<span class="block text-xs font-normal text-gray-500">{{ __('Ägarandel :share %', ['share' => rtrim(rtrim(number_format($company['ownership_bp'] / 100, 2, ',', ''), '0'), ',')]) }}</span>@endif</th>
                                @endforeach
                                <th scope="col" class="{{ $th }}">{{ __('Summa') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($sections as $section)
                                    @if($section['label'])
                                        <tr><th scope="rowgroup" colspan="{{ count($report['companies']) + 2 }}" class="pt-6 pb-1 text-left text-base font-semibold text-gray-900">{{ $section['label'] }}</th></tr>
                                    @endif
                                    @foreach($section['groups'] as $group)
                                        @if($group['rows'] !== [])
                                            <tr><th scope="rowgroup" colspan="{{ count($report['companies']) + 2 }}" class="pt-4 pb-1 text-left font-medium text-gray-900">{{ $group['label'] }}</th></tr>
                                            @foreach($group['rows'] as $row)
                                                <tr class="border-b border-gray-100">
                                                    <th scope="row" class="{{ $first }} font-normal"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}@if($row['name_differs']) <span class="text-xs text-gray-500">({{ __('namnet skiljer sig mellan företagen') }})</span>@endif</th>
                                                    @foreach($report['companies'] as $company)
                                                        <td class="{{ $td }}"><x-ui.amount :money="$m($row['amounts'][$company['id']] ?? 0)" :currency="false" /></td>
                                                    @endforeach
                                                    <td class="{{ $td }} font-medium"><x-ui.amount :money="$m($row['sum'])" :currency="false" /></td>
                                                </tr>
                                            @endforeach
                                            <tr class="border-b border-gray-200 font-medium">
                                                <th scope="row" class="{{ $first }}">{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</th>
                                                @foreach($report['companies'] as $company)
                                                    <td class="{{ $td }}"><x-ui.amount :money="$m($group['totals'][$company['id']] ?? 0)" :currency="false" /></td>
                                                @endforeach
                                                <td class="{{ $td }}"><x-ui.amount :money="$m($group['sum'])" :currency="false" /></td>
                                            </tr>
                                        @endif
                                        @if($group['subtotal'] ?? null)
                                            <tr class="border-b border-gray-300 bg-gray-50 font-semibold">
                                                <th scope="row" class="{{ $first }} bg-gray-50">{{ $group['subtotal']['label'] }}</th>
                                                @foreach($report['companies'] as $company)
                                                    <td class="{{ $td }}"><x-ui.amount :money="$m($group['subtotal']['amounts'][$company['id']] ?? 0)" :currency="false" /></td>
                                                @endforeach
                                                <td class="{{ $td }}"><x-ui.amount :money="$m($group['subtotal']['sum'])" :currency="false" /></td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    @if($section['unbooked'])
                                        <tr class="border-b border-gray-200">
                                            <th scope="row" class="{{ $first }} font-normal">{{ $section['unbooked']['label'] }}</th>
                                            @foreach($report['companies'] as $company)
                                                <td class="{{ $td }}"><x-ui.amount :money="$m($section['unbooked']['amounts'][$company['id']] ?? 0)" :currency="false" /></td>
                                            @endforeach
                                            <td class="{{ $td }}"><x-ui.amount :money="$m($section['unbooked']['sum'])" :currency="false" /></td>
                                        </tr>
                                    @endif
                                    <tr class="border-t border-gray-900 font-semibold">
                                        <th scope="row" class="{{ $first }}">{{ $section['total']['label'] }}</th>
                                        @foreach($report['companies'] as $company)
                                            <td class="{{ $td }}"><x-ui.amount :money="$m($section['total']['amounts'][$company['id']] ?? 0)" :currency="false" /></td>
                                        @endforeach
                                        <td class="{{ $td }}"><x-ui.amount :money="$m($section['total']['sum'])" :currency="false" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
