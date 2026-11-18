@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $money = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format();
    $pct = function (?int $bp): string {
        if ($bp === null) {
            return '–';
        }

        $tenths = intdiv(abs($bp) + 5, 10);

        return ($bp < 0 && $tenths > 0 ? '−' : '').number_format(intdiv($tenths, 10), 0, ',', "\u{00A0}").','.($tenths % 10)."\u{00A0}%";
    };
    $tone = fn (int $ore): string => $ore < 0 ? 'text-accent-700' : '';
    $num = 'px-3 py-2 text-right tabular-nums whitespace-nowrap';
    $empty = ['revenue' => 0, 'costs' => 0, 'contribution' => 0, 'margin' => null];
@endphp
<div class="space-y-6">
    <x-ui.card>
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.field for="project_status_filter" :label="__('Status')">
                <select id="project_status_filter" class="{{ $input }} mt-1" wire:model.live="status">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Hela projektens tid')" :description="__('Bokförda rader med projektet på resultatkonton under alla räkenskapsår. Täckningsbidraget är intäkterna minus kostnaderna för material, externa tjänster och personal (konto 4000–7699).')">
        @if($projects->isEmpty())
            <x-ui.empty-state :title="__('Inga projekt att visa')">
                {{ $limited
                    ? __('Du ser resultatet för de projekt du har behörighet till. Be ägaren eller en administratör att lägga till dem under Behörighet per enhet.')
                    : __('Lägg upp projekt under Bokföring → Projekt och välj dem på raderna när du bokför eller fakturerar.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto" data-project-results>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-right text-gray-500">
                            <th scope="col" class="py-2 pe-3 text-left font-medium">{{ __('Projekt') }}</th>
                            <th scope="col" class="px-3 py-2 text-left font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Intäkter') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Kostnader') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Täckningsbidrag') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Marginal') }}</th>
                            @if($canSeeBudget)
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Budget, täckningsbidrag') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($projects as $project)
                            @php($actual = $results[$project->id]['actual'] ?? $empty)
                            @php($budget = $results[$project->id]['budget'] ?? null)
                            <tr wire:key="project-{{ $project->id }}">
                                <th scope="row" class="py-2 pe-3 text-left font-normal">
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-medium text-ink underline-offset-2 hover:underline"><span class="tabular-nums">{{ $project->code }}</span> {{ $project->name }}</a>
                                    @if($project->customer !== null)
                                        <span class="block text-xs text-gray-500">{{ __('Kund: :name', ['name' => $project->customer->name]) }}</span>
                                    @endif
                                </th>
                                <td class="px-3 py-2"><x-ui.badge>{{ $project->status->label() }}</x-ui.badge></td>
                                <td class="{{ $num }}">{{ $money($actual['revenue']) }}</td>
                                <td class="{{ $num }}">{{ $money($actual['costs']) }}</td>
                                <td class="{{ $num }} font-semibold {{ $tone($actual['contribution']) }}">{{ $money($actual['contribution']) }}</td>
                                <td class="{{ $num }} {{ $tone($actual['contribution']) }}">{{ $pct($actual['margin']) }}</td>
                                @if($canSeeBudget)
                                    <td class="{{ $num }} text-gray-600">{{ $budget === null ? '–' : $money($budget['contribution']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($limited)
        <p class="text-sm text-gray-500" data-personnel-hidden>{{ __('Personalkostnader (konto 7000–7699) visas inte, varken utfall eller budget, eftersom du är begränsad till vissa kostnadsställen och projekt. Täckningsbidraget och resultatet är därför högre än i bokföringen.') }}</p>
    @endif
</div>
