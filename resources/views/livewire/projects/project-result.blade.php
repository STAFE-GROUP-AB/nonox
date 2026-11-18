@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $money = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format();
    // Whole kronor, half up, integer arithmetic only (AGENTS.md §1.2).
    $kr = function (int $ore): string {
        $kronor = intdiv(abs($ore) + 50, 100);

        return ($ore < 0 && $kronor > 0 ? '−' : '').number_format($kronor, 0, ',', "\u{00A0}");
    };
    $pct = function (?int $bp): string {
        if ($bp === null) {
            return '–';
        }

        $tenths = intdiv(abs($bp) + 5, 10);

        return ($bp < 0 && $tenths > 0 ? '−' : '').number_format(intdiv($tenths, 10), 0, ',', "\u{00A0}").','.($tenths % 10)."\u{00A0}%";
    };
    $tone = fn (int $ore): string => $ore < 0 ? 'text-accent-700' : '';
    $label = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMM YY');
    $num = 'px-2 py-1.5 text-right tabular-nums whitespace-nowrap';
    $showBudget = $canSeeBudget;
    $columns = $showBudget ? 5 : 2;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap gap-3">
        <a href="{{ route('projects.index') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Alla projekt') }}</a>
    </div>

    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600" data-project-facts>
                <x-ui.badge>{{ $project->status->label() }}</x-ui.badge>
                @if($project->customer !== null)
                    <span>{{ __('Kund: :name', ['name' => $project->customer->name]) }}</span>
                @endif
                @if($manager !== null)
                    <span>{{ __('Projektledare: :name', ['name' => $manager->name]) }}</span>
                @endif
                @if($project->price_form !== null)
                    <span>{{ $project->price_form->label() }}</span>
                @endif
                @if($project->starts_on !== null || $project->ends_on !== null)
                    <span class="tabular-nums">{{ $project->starts_on?->toDateString() ?? '…' }} – {{ $project->ends_on?->toDateString() ?? '…' }}</span>
                @endif
                @if($project->defaultCostCenter !== null)
                    <span>{{ __('Kostnadsställe: :name', ['name' => $project->defaultCostCenter->label()]) }}</span>
                @endif
            </p>
            <x-ui.field for="project_period" :label="__('Period')" class="w-full sm:w-64">
                <select id="project_period" class="{{ $input }} mt-1" wire:model.live="ar">
                    <option value="">{{ __('Hela projektet') }}</option>
                    @foreach($years as $option)
                        <option value="{{ $option->id }}">{{ __('Räkenskapsåret :year', ['year' => $option->label()]) }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>
    </x-ui.card>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Nyckeltal') }}" data-project-kpis>
        @foreach([
            ['label' => __('Intäkter'), 'actual' => $report['actual']['revenue'], 'budget' => $report['budget']['revenue'], 'money' => true],
            ['label' => __('Kostnader'), 'actual' => $report['actual']['costs'], 'budget' => $report['budget']['costs'], 'money' => true],
            ['label' => __('Täckningsbidrag'), 'actual' => $report['actual']['contribution'], 'budget' => $report['budget']['contribution'], 'money' => true],
            ['label' => __('Marginal'), 'actual' => $report['actual']['margin'], 'budget' => $report['budget']['margin'], 'money' => false],
        ] as $kpi)
            <div @class(['rounded-3xl px-5 py-4', 'bg-ink text-white' => $loop->index === 2, 'bg-white shadow-xs ring-1 ring-stone-200' => $loop->index !== 2])>
                <span @class(['block text-sm', 'text-stone-300' => $loop->index === 2, 'text-gray-500' => $loop->index !== 2])>{{ $kpi['label'] }}</span>
                <span class="mt-1 block text-2xl font-bold tracking-tight tabular-nums">{{ $kpi['money'] ? $money((int) $kpi['actual']) : $pct($kpi['actual']) }}</span>
                @if($showBudget && $report['has_budget'])
                    <span @class(['mt-1 block text-xs tabular-nums', 'text-stone-300' => $loop->index === 2, 'text-gray-500' => $loop->index !== 2])>{{ __('Budget: :amount', ['amount' => $kpi['money'] ? $money((int) $kpi['budget']) : $pct($kpi['budget'])]) }}</span>
                @endif
            </div>
        @endforeach
    </section>

    @if($estimate !== null)
        <x-ui.card :title="__('Mot kalkylen')" :description="__('Hela projektets bokförda intäkter och kostnader jämförda med kontraktssumman och den beräknade totala kostnaden i projektregistret.')">
            @if($estimate['contract'] === null && $estimate['estimated_cost'] === null)
                <p class="text-sm text-gray-600">{{ __('Projektet har ingen kontraktssumma eller beräknad total kostnad. Fyll i dem under Uppgifter i projektregistret, så jämförs utfallet med dem här.') }}</p>
            @else
                <dl class="grid gap-4 text-sm sm:grid-cols-3" data-project-estimate>
                    <div>
                        <dt class="text-gray-500">{{ __('Kontraktssumma') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ $estimate['contract'] === null ? '–' : $money($estimate['contract']) }}</dd>
                        @if($estimate['contract'] !== null)
                            <dd class="mt-1 text-gray-600 tabular-nums">{{ __('Intäktsfört: :share', ['share' => $pct($estimate['revenue_share'])]) }}</dd>
                            <dd class="text-gray-600 tabular-nums">{{ __('Kvar att intäktsföra: :amount', ['amount' => $money((int) $estimate['revenue_left'])]) }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Beräknad total kostnad') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ $estimate['estimated_cost'] === null ? '–' : $money($estimate['estimated_cost']) }}</dd>
                        @if($estimate['estimated_cost'] !== null)
                            <dd @class(['mt-1 tabular-nums', 'text-accent-700' => ($estimate['cost_share'] ?? 0) > 10000, 'text-gray-600' => ($estimate['cost_share'] ?? 0) <= 10000])>{{ __('Förbrukat: :share', ['share' => $pct($estimate['cost_share'])]) }}</dd>
                            <dd class="text-gray-600 tabular-nums">{{ __('Kvar enligt kalkylen: :amount', ['amount' => $money((int) $estimate['cost_left'])]) }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Beräknat täckningsbidrag') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ $estimate['expected_contribution'] === null ? '–' : $money($estimate['expected_contribution']) }}</dd>
                        @if($estimate['expected_contribution'] !== null)
                            <dd class="mt-1 text-gray-600 tabular-nums">{{ __('Beräknad marginal: :share', ['share' => $pct($estimate['expected_margin'])]) }}</dd>
                        @endif
                    </div>
                </dl>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="$year === null ? __('Resultat för hela projektet') : __('Resultat för räkenskapsåret :year', ['year' => $year->label()])"
        :description="$showBudget
            ? __('Intäkter visas positiva och kostnader negativa, som i resultatrapporten. Budgeten är raderna med projektet i den budget som används i rapporterna. Avvikelse är utfall minus budget. Klicka på ett konto för att se raderna.')
            : __('Intäkter visas positiva och kostnader negativa, som i resultatrapporten. Klicka på ett konto för att se raderna.')">
        @if($report['groups'] === [])
            <x-ui.empty-state :title="__('Inget bokfört på projektet')">{{ __('När du väljer projektet på rader i verifikationer, leverantörsfakturor eller kundfakturor visas resultatet här.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto" data-project-result>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-right text-gray-500">
                            <th scope="col" class="py-2 pe-4 text-left font-medium">{{ __('Konto') }}</th>
                            <th scope="col" class="px-2 py-2 font-medium">{{ __('Utfall') }}</th>
                            @if($showBudget)
                                <th scope="col" class="px-2 py-2 font-medium">{{ __('Budget') }}</th>
                                <th scope="col" class="px-2 py-2 font-medium">{{ __('Avvikelse') }}</th>
                                <th scope="col" class="px-2 py-2 font-medium">{{ __('Avvikelse %') }}</th>
                            @endif
                        </tr>
                    </thead>
                    @foreach($report['groups'] as $group)
                        <tbody wire:key="group-{{ $group['key'] }}" class="divide-y divide-stone-100">
                            <tr><th scope="rowgroup" colspan="{{ $columns }}" class="pt-4 pb-1 text-left font-semibold text-ink">{{ $group['label'] }}</th></tr>
                            @foreach($group['rows'] as $row)
                                <tr wire:key="account-{{ $row['number'] }}">
                                    <th scope="row" class="py-1.5 pe-4 text-left font-normal">
                                        <button type="button" wire:click="drill({{ $row['number'] }})" aria-expanded="{{ $account === $row['number'] ? 'true' : 'false' }}" class="text-left underline-offset-2 hover:underline">
                                            <span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}
                                        </button>
                                    </th>
                                    <td class="{{ $num }} {{ $tone($row['actual']) }}">{{ $kr($row['actual']) }}</td>
                                    @if($showBudget)
                                        <td class="{{ $num }} text-gray-600">{{ $kr($row['budget']) }}</td>
                                        <td class="{{ $num }} {{ $tone($row['difference']) }}">{{ $kr($row['difference']) }}</td>
                                        <td class="{{ $num }} {{ $tone($row['difference']) }}">{{ $pct($row['percent']) }}</td>
                                    @endif
                                </tr>
                                @if($account === $row['number'] && $drill !== null)
                                    <tr>
                                        <td colspan="{{ $columns }}" class="bg-stone-50 px-3 py-3" data-project-drill>
                                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                <p class="font-semibold text-ink">{{ __('Rader på :account', ['account' => "{$row['number']} {$row['name']}"]) }}</p>
                                                <div class="flex gap-4">
                                                    @if($ledgerLink)
                                                        <a href="{{ $ledgerLink($row['number']) }}" wire:navigate class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna i huvudboken') }}</a>
                                                    @endif
                                                    <button type="button" wire:click="closeDrill" class="text-sm text-gray-600 underline">{{ __('Stäng') }}</button>
                                                </div>
                                            </div>
                                            @if($drill['rows'] === [])
                                                <p class="mt-2 text-sm text-gray-600">{{ __('Inga bokförda rader på kontot för perioden. Beloppet kommer från budgeten.') }}</p>
                                            @else
                                                <table class="mt-2 min-w-full text-sm">
                                                    <thead>
                                                        <tr class="text-gray-500">
                                                            <th scope="col" class="py-1 pe-3 text-left font-medium">{{ __('Datum') }}</th>
                                                            <th scope="col" class="px-3 py-1 text-left font-medium">{{ __('Verifikation') }}</th>
                                                            <th scope="col" class="px-3 py-1 text-left font-medium">{{ __('Text') }}</th>
                                                            <th scope="col" class="px-3 py-1 text-right font-medium">{{ __('Belopp') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-stone-200">
                                                        @foreach($drill['rows'] as $line)
                                                            <tr wire:key="row-{{ $line['row_id'] }}">
                                                                <td class="py-1 pe-3 tabular-nums whitespace-nowrap">{{ $line['date'] }}</td>
                                                                <td class="px-3 py-1 tabular-nums whitespace-nowrap">
                                                                    @if($line['linked'])
                                                                        <a href="{{ route('accounting.vouchers.show', $line['voucher_id']) }}" wire:navigate class="text-accent-600 hover:text-accent-700">{{ $line['reference'] }}</a>
                                                                    @else
                                                                        {{ $line['reference'] }}
                                                                    @endif
                                                                </td>
                                                                <td class="px-3 py-1">{{ $line['text'] }}</td>
                                                                <td class="px-3 py-1 text-right tabular-nums whitespace-nowrap {{ $tone($line['amount']) }}">{{ $money($line['amount']) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                                @if($drill['more'])
                                                    <p class="mt-2 text-sm text-gray-600">{{ __('Visar de första :count raderna. Resten finns i huvudboken.', ['count' => App\Domain\Projects\ProjectResults::DRILL_LIMIT]) }}</p>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            <tr class="font-semibold">
                                <th scope="row" class="py-1.5 pe-4 text-left">{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</th>
                                <td class="{{ $num }} {{ $tone($group['total']['actual']) }}">{{ $kr($group['total']['actual']) }}</td>
                                @if($showBudget)
                                    <td class="{{ $num }}">{{ $kr($group['total']['budget']) }}</td>
                                    <td class="{{ $num }} {{ $tone($group['total']['difference']) }}">{{ $kr($group['total']['difference']) }}</td>
                                    <td class="{{ $num }} {{ $tone($group['total']['difference']) }}">{{ $pct($group['total']['percent']) }}</td>
                                @endif
                            </tr>
                            @if($group['subtotal'])
                                <tr class="bg-stone-50 font-semibold text-ink" data-project-contribution>
                                    <th scope="row" class="py-1.5 pe-4 text-left">{{ __('Täckningsbidrag') }}</th>
                                    <td class="{{ $num }} {{ $tone($group['subtotal']['actual']) }}">{{ $kr($group['subtotal']['actual']) }}</td>
                                    @if($showBudget)
                                        <td class="{{ $num }}">{{ $kr($group['subtotal']['budget']) }}</td>
                                        <td class="{{ $num }} {{ $tone($group['subtotal']['difference']) }}">{{ $kr($group['subtotal']['difference']) }}</td>
                                        <td class="{{ $num }} {{ $tone($group['subtotal']['difference']) }}">{{ $pct($group['subtotal']['percent']) }}</td>
                                    @endif
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                    <tfoot>
                        <tr class="border-t-2 border-stone-300 font-bold text-ink">
                            <th scope="row" class="py-2 pe-4 text-left">{{ __('Projektresultat') }}</th>
                            <td class="{{ $num }} {{ $tone($report['result']['actual']) }}">{{ $kr($report['result']['actual']) }}</td>
                            @if($showBudget)
                                <td class="{{ $num }}">{{ $kr($report['result']['budget']) }}</td>
                                <td class="{{ $num }} {{ $tone($report['result']['difference']) }}">{{ $kr($report['result']['difference']) }}</td>
                                <td class="{{ $num }} {{ $tone($report['result']['difference']) }}">{{ $pct($report['result']['percent']) }}</td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($year !== null && $report['groups'] !== [])
        <x-ui.card :title="__('Per månad')" :description="__('Utfallet per månad under räkenskapsåret, i hela kronor.')">
            <div class="overflow-x-auto" data-project-months>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-right text-gray-500">
                            <th scope="col" class="py-2 pe-4 text-left font-medium">{{ __('Grupp') }}</th>
                            @foreach($report['months'] as $month)
                                <th scope="col" class="px-2 py-2 font-medium whitespace-nowrap">{{ $label($month) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($report['groups'] as $group)
                            <tr wire:key="month-group-{{ $group['key'] }}">
                                <th scope="row" class="py-1.5 pe-4 text-left font-normal">{{ $group['label'] }}</th>
                                @foreach($report['months'] as $month)
                                    @php($value = $report['monthly'][$group['key']][$month] ?? 0)
                                    <td class="{{ $num }} {{ $tone($value) }}">{{ $kr($value) }}</td>
                                @endforeach
                            </tr>
                            @if($group['subtotal'])
                                <tr class="bg-stone-50 font-semibold text-ink" wire:key="month-contribution">
                                    <th scope="row" class="py-1.5 pe-4 text-left">{{ __('Täckningsbidrag') }}</th>
                                    @foreach($report['months'] as $month)
                                        @php($value = $report['monthly']['contribution'][$month] ?? 0)
                                        <td class="{{ $num }} {{ $tone($value) }}">{{ $kr($value) }}</td>
                                    @endforeach
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-stone-300 font-bold text-ink">
                            <th scope="row" class="py-2 pe-4 text-left">{{ __('Projektresultat') }}</th>
                            @foreach($report['months'] as $month)
                                @php($value = $report['monthly']['result'][$month] ?? 0)
                                <td class="{{ $num }} {{ $tone($value) }}">{{ $kr($value) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if($limited)
        <p class="text-sm text-gray-500" data-personnel-hidden>{{ __('Personalkostnader (konto 7000–7699) visas inte, varken utfall eller budget, eftersom du är begränsad till vissa kostnadsställen och projekt. Täckningsbidraget och resultatet är därför högre än i bokföringen.') }}</p>
    @endif
</div>
