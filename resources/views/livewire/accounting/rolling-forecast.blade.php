@php
    // Whole kronor, half up, integer arithmetic only (AGENTS.md §1.2).
    $kr = function (int $ore): string {
        $kronor = intdiv(abs($ore) + 50, 100);

        return ($ore < 0 && $kronor > 0 ? '−' : '').number_format($kronor, 0, ',', "\u{00A0}");
    };
    $tone = fn (int $ore): string => $ore < 0 ? 'text-accent-700' : '';
    $label = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMM YY');
    $long = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    $num = 'px-2 py-1.5 text-right tabular-nums whitespace-nowrap';
    $months = $report['months'];
    $sources = collect($report['sources'])->filter(fn (array $source): bool => $source['version_id'] !== null)->unique('version_id');
    $missing = collect($report['sources'])->filter(fn (array $source): bool => $source['version_id'] === null)->keys();
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('accounting.budgets') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Alla budgetar') }}</a>
        <a href="{{ route('accounting.budgets.report') }}" wire:navigate class="text-sm font-medium text-gray-700 underline">{{ __('Budget mot utfall') }}</a>
        <span class="ms-auto flex items-center gap-2">
            <x-secondary-button type="button" wire:click="previous" aria-label="{{ __('En månad tidigare') }}">‹</x-secondary-button>
            <span class="text-sm font-semibold text-ink">{{ __(':from – :to', ['from' => $long($months[0]), 'to' => $long($months[count($months) - 1])]) }}</span>
            <x-secondary-button type="button" wire:click="next" aria-label="{{ __('En månad senare') }}">›</x-secondary-button>
        </span>
    </div>

    <x-ui.card :title="__('Varifrån siffrorna kommer')">
        <ul class="mt-2 space-y-1 text-sm text-gray-700" data-rolling-sources>
            @forelse($sources as $month => $source)
                @php
                    $covered = collect($report['sources'])->filter(fn (array $s): bool => $s['version_id'] === $source['version_id'])->keys();
                @endphp
                <li wire:key="source-{{ $source['version_id'] }}">
                    <x-ui.badge :tone="$source['kind'] === App\Domain\Budget\BudgetKind::Forecast ? 'accent' : 'neutral'">{{ $source['kind']?->label() }}</x-ui.badge>
                    <a href="{{ route('accounting.budgets.show', ['budgetVersion' => $source['version_id']]) }}" wire:navigate class="ms-1 font-semibold text-ink underline-offset-2 hover:underline">{{ $source['name'] }}</a>
                    <span>{{ __(':from – :to', ['from' => $label($covered->first()), 'to' => $label($covered->last())]) }}</span>
                    @if($source['cutoff'] !== null)
                        <span>· {{ __('utfall till och med :month', ['month' => $long($source['cutoff'])]) }}</span>
                    @endif
                    @if($source['stale'])
                        <span class="block font-medium text-amber-800" data-rolling-stale>{{ __('Prognosen är äldre än bokföringen. Räkna om den eller gör en ny.') }}</span>
                    @endif
                </li>
            @empty
                <li>{{ __('Inga av månaderna har någon prognos eller budget.') }}</li>
            @endforelse
            @if($missing->isNotEmpty() && $sources->isNotEmpty())
                <li>{{ __('Utan prognos eller budget: :months. Gör en budget eller prognos för det räkenskapsåret, eller lägg upp räkenskapsåret först.', ['months' => $missing->map($label)->implode(', ')]) }}</li>
            @endif
        </ul>
        <p class="mt-3 text-xs text-gray-500">{{ __('Varje månad läses ur prognosen som används för sitt räkenskapsår, annars ur budgeten som används i rapporterna. Månader till och med prognosens brytmånad är utfall.') }}</p>
    </x-ui.card>

    <x-ui.card :title="__('Resultat per månad')" :description="__('Intäkter visas positiva och kostnader negativa, som i resultatrapporten.')">
        @if($report['groups'] === [])
            <x-ui.empty-state :title="__('Inget att visa')" class="mt-4">{{ __('Gör en prognos eller en budget under Budget, så visas de kommande tolv månaderna här.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm" data-rolling-grid>
                    <thead>
                        <tr class="text-gray-500">
                            <th scope="col" class="py-2 pe-4 text-left font-medium">{{ __('Konto') }}</th>
                            @foreach($months as $month)
                                <th scope="col" @class(['px-2 py-2 text-right font-medium', 'bg-stone-50' => $report['sources'][$month]['actual']])>
                                    <span class="block">{{ $label($month) }}</span>
                                    <span class="block text-xs font-normal">{{ $report['sources'][$month]['actual'] ? __('Utfall') : ($report['sources'][$month]['kind']?->label() ?? '–') }}</span>
                                </th>
                            @endforeach
                            <th scope="col" class="px-2 py-2 text-right font-medium">{{ __('Summa') }}</th>
                        </tr>
                    </thead>
                    @foreach($report['groups'] as $group)
                        <tbody wire:key="group-{{ $group['key'] }}" class="border-t border-stone-200">
                            <tr><th scope="colgroup" colspan="{{ count($months) + 2 }}" class="pt-3 pb-1 text-left font-semibold text-ink">{{ $group['label'] }}</th></tr>
                            @foreach($group['rows'] as $row)
                                <tr wire:key="row-{{ $row['number'] }}">
                                    <th scope="row" class="py-1.5 pe-4 text-left font-normal"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</th>
                                    @foreach($months as $month)
                                        <td @class([$num, $tone($row['amounts'][$month]), 'bg-stone-50' => $report['sources'][$month]['actual']])>{{ $kr($row['amounts'][$month]) }}</td>
                                    @endforeach
                                    <td @class([$num, 'font-semibold', $tone($row['total'])])>{{ $kr($row['total']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="font-semibold">
                                <th scope="row" class="py-1.5 pe-4 text-left">{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</th>
                                @foreach($months as $month)
                                    <td @class([$num, $tone($group['amounts'][$month]), 'bg-stone-50' => $report['sources'][$month]['actual']])>{{ $kr($group['amounts'][$month]) }}</td>
                                @endforeach
                                <td @class([$num, $tone($group['total'])])>{{ $kr($group['total']) }}</td>
                            </tr>
                        </tbody>
                    @endforeach
                    <tfoot>
                        <tr class="border-t-2 border-stone-300 font-semibold text-ink">
                            <th scope="row" class="py-2 pe-4 text-left">{{ __('Resultat') }}</th>
                            @foreach($months as $month)
                                <td @class([$num, $tone($report['result']['amounts'][$month])])>{{ $kr($report['result']['amounts'][$month]) }}</td>
                            @endforeach
                            <td @class([$num, $tone($report['result']['total'])])>{{ $kr($report['result']['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
