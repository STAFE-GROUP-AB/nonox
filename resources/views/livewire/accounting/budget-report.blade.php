@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
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
    $label = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    $num = 'px-2 py-1.5 text-right tabular-nums whitespace-nowrap';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap gap-3">
        <a href="{{ route('accounting.budgets') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Alla budgetar') }}</a>
        @if($version)
            <a href="{{ route('accounting.budgets.show', ['budgetVersion' => $version->id]) }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Öppna budgeten') }}</a>
        @endif
    </div>

    @if(! $version)
        <x-ui.empty-state :title="__('Ingen budget ännu')">{{ __('Skapa en budget under Budget, så jämförs den här med bokföringen.') }}</x-ui.empty-state>
    @else
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field for="report_budget" :label="__('Budget')">
                    <select id="report_budget" class="{{ $input }} mt-1" wire:model.live="budget">
                        @foreach($versions as $option)
                            <option value="{{ $option->id }}" @selected($option->id === $version->id)>{{ $option->name }} · {{ $option->fiscalYear->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="report_month" :label="__('Månad')">
                    <select id="report_month" class="{{ $input }} mt-1" wire:model.live="manad">
                        @foreach($report['months'] as $month)
                            <option value="{{ $month }}" @selected($month === $report['month'])>{{ $label($month) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="report_cost_center" :label="__('Kostnadsställe')">
                    <select id="report_cost_center" class="{{ $input }} mt-1" wire:model.live="kst">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($costCenters as $object)
                            <option value="{{ $object->code }}">{{ $object->code }} {{ $object->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="report_project" :label="__('Projekt')">
                    <select id="report_project" class="{{ $input }} mt-1" wire:model.live="projekt">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($projects as $object)
                            <option value="{{ $object->code }}">{{ $object->code }} {{ $object->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Resultat mot budget')" :description="__('Intäkter visas positiva och kostnader negativa, som i resultatrapporten. Avvikelse är utfall minus budget.')">
            @if($report['groups'] === [])
                <x-ui.empty-state :title="__('Inget att jämföra')" class="mt-4">{{ __('Varken budgeten eller bokföringen har belopp på resultatkonton för det här urvalet.') }}</x-ui.empty-state>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-gray-500">
                                <th scope="col" class="py-2 pe-4"></th>
                                <th scope="colgroup" colspan="4" class="border-b border-stone-200 px-2 py-2 text-center font-medium">{{ $label($report['month']) }}</th>
                                <th scope="colgroup" colspan="4" class="border-b border-stone-200 px-2 py-2 text-center font-medium">{{ __('Hittills i år') }}</th>
                                <th scope="col" class="py-2"></th>
                            </tr>
                            <tr class="text-right text-gray-500">
                                <th scope="col" class="py-2 pe-4 text-left font-medium">{{ __('Konto') }}</th>
                                @foreach([1, 2] as $part)
                                    <th scope="col" class="px-2 py-2 font-medium">{{ __('Utfall') }}</th>
                                    <th scope="col" class="px-2 py-2 font-medium">{{ __('Budget') }}</th>
                                    <th scope="col" class="px-2 py-2 font-medium">{{ __('Avvikelse') }}</th>
                                    <th scope="col" class="px-2 py-2 font-medium">{{ __('Avvikelse %') }}</th>
                                @endforeach
                                <th scope="col" class="px-2 py-2 font-medium">{{ __('Budget helår') }}</th>
                            </tr>
                        </thead>
                        @foreach($report['groups'] as $group)
                            <tbody wire:key="group-{{ $group['key'] }}" class="divide-y divide-stone-100">
                                <tr><th scope="rowgroup" colspan="10" class="pt-4 pb-1 text-left font-semibold text-ink">{{ $group['label'] }}</th></tr>
                                @foreach($group['rows'] as $row)
                                    <tr>
                                        <th scope="row" class="py-1.5 pe-4 text-left font-normal">
                                            @if($ledgerLink)
                                                <a href="{{ $ledgerLink($row['number']) }}" wire:navigate class="tabular-nums underline-offset-2 hover:underline">{{ $row['number'] }} {{ $row['name'] }}</a>
                                            @else
                                                <span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}
                                            @endif
                                        </th>
                                        @foreach(['month', 'ytd'] as $part)
                                            <td class="{{ $num }} {{ $tone($row[$part]['actual']) }}">{{ $kr($row[$part]['actual']) }}</td>
                                            <td class="{{ $num }} text-gray-600">{{ $kr($row[$part]['budget']) }}</td>
                                            <td class="{{ $num }} {{ $tone($row[$part]['difference']) }}">{{ $kr($row[$part]['difference']) }}</td>
                                            <td class="{{ $num }} {{ $tone($row[$part]['difference']) }}">{{ $pct($row[$part]['percent']) }}</td>
                                        @endforeach
                                        <td class="{{ $num }} text-gray-600">{{ $kr($row['year_budget']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <th scope="row" class="py-1.5 pe-4 text-left">{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</th>
                                    @foreach(['month', 'ytd'] as $part)
                                        <td class="{{ $num }} {{ $tone($group['total'][$part]['actual']) }}">{{ $kr($group['total'][$part]['actual']) }}</td>
                                        <td class="{{ $num }}">{{ $kr($group['total'][$part]['budget']) }}</td>
                                        <td class="{{ $num }} {{ $tone($group['total'][$part]['difference']) }}">{{ $kr($group['total'][$part]['difference']) }}</td>
                                        <td class="{{ $num }} {{ $tone($group['total'][$part]['difference']) }}">{{ $pct($group['total'][$part]['percent']) }}</td>
                                    @endforeach
                                    <td class="{{ $num }}">{{ $kr($group['total']['year_budget']) }}</td>
                                </tr>
                                @if($group['subtotal'])
                                    <tr class="bg-stone-50 font-semibold text-ink">
                                        <th scope="row" class="py-1.5 pe-4 text-left">{{ $group['subtotal']['label'] }}</th>
                                        @foreach(['month', 'ytd'] as $part)
                                            <td class="{{ $num }} {{ $tone($group['subtotal'][$part]['actual']) }}">{{ $kr($group['subtotal'][$part]['actual']) }}</td>
                                            <td class="{{ $num }}">{{ $kr($group['subtotal'][$part]['budget']) }}</td>
                                            <td class="{{ $num }} {{ $tone($group['subtotal'][$part]['difference']) }}">{{ $kr($group['subtotal'][$part]['difference']) }}</td>
                                            <td class="{{ $num }} {{ $tone($group['subtotal'][$part]['difference']) }}">{{ $pct($group['subtotal'][$part]['percent']) }}</td>
                                        @endforeach
                                        <td class="{{ $num }}">{{ $kr($group['subtotal']['year_budget']) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        @endforeach
                        <tfoot>
                            <tr class="border-t-2 border-stone-300 font-bold text-ink">
                                <th scope="row" class="py-2 pe-4 text-left">{{ __('Resultat') }}</th>
                                @foreach(['month', 'ytd'] as $part)
                                    <td class="{{ $num }} {{ $tone($report['result'][$part]['actual']) }}">{{ $kr($report['result'][$part]['actual']) }}</td>
                                    <td class="{{ $num }}">{{ $kr($report['result'][$part]['budget']) }}</td>
                                    <td class="{{ $num }} {{ $tone($report['result'][$part]['difference']) }}">{{ $kr($report['result'][$part]['difference']) }}</td>
                                    <td class="{{ $num }} {{ $tone($report['result'][$part]['difference']) }}">{{ $pct($report['result'][$part]['percent']) }}</td>
                                @endforeach
                                <td class="{{ $num }}">{{ $kr($report['result']['year_budget']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
