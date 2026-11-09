@php
    use App\Modules\Saas\Domain\Arithmetic;
    use App\Modules\Saas\Domain\Months;

    $width = max(0, ...array_values(array_map(fn ($ages) => count($ages), $cohorts ?: [[]])));
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Kohorter')" :lead="__('Kunderna grupperas efter månaden då de fick sin första MRR. En kund som kommer tillbaka stannar i sin första kohort.')">
        <x-slot:action>
            @include('saas::partials.month-picker')
        </x-slot:action>
    </x-ui.page-heading>

    @include('saas::partials.note')

    @if($cohorts === [])
        <x-ui.empty-state :title="__('Inga kohorter än')">{{ __('Kohorterna visas när kunder har haft MRR.') }}</x-ui.empty-state>
    @else
        <x-ui.card>
            <x-slot:action>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex rounded-lg ring-1 ring-stone-300" role="group" aria-label="{{ __('Visa') }}">
                        <button type="button" wire:click="$set('show', 'kunder')" aria-pressed="{{ $revenue ? 'false' : 'true' }}" @class(['rounded-l-lg px-3 py-1.5 text-sm font-medium', 'bg-ink text-white' => ! $revenue, 'text-gray-700 hover:bg-stone-50' => $revenue])>{{ __('Kunder kvar') }}</button>
                        <button type="button" wire:click="$set('show', 'intakt')" aria-pressed="{{ $revenue ? 'true' : 'false' }}" @class(['rounded-r-lg px-3 py-1.5 text-sm font-medium', 'bg-ink text-white' => $revenue, 'text-gray-700 hover:bg-stone-50' => ! $revenue])>{{ __('Intäkt kvar') }}</button>
                    </div>
                    <a href="{{ route('saas.export', ['list' => 'kohorter', 'manad' => substr($selected->key(), 0, 7)]) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
                </div>
            </x-slot:action>
            <p class="text-sm text-gray-600">
                {{ $revenue
                    ? __('Kohortens MRR som andel av dess MRR den första månaden, alla månader i valutakurserna för :month.', ['month' => Months::label($selected->key())])
                    : __('Andel av kohortens kunder som har MRR, månad för månad efter starten.') }}
            </p>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Kohorter per :month', ['month' => Months::label($selected->key())]) }}</caption>
                    <thead>
                        <tr class="text-right text-gray-600">
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Kohort') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kunder') }}</th>
                            @for($age = 0; $age < $width; $age++)
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('Mån :n', ['n' => $age]) }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 text-right tabular-nums">
                        @foreach($cohorts as $cohort => $ages)
                            @php($start = $ages[0] ?? ['customers' => 0, 'mrr_ore' => 0])
                            <tr>
                                <th scope="row" class="py-1.5 pr-4 text-left font-normal whitespace-nowrap">{{ Months::short($cohort) }}</th>
                                <td class="py-1.5 pr-4">{{ $start['customers'] }}</td>
                                @for($age = 0; $age < $width; $age++)
                                    @php($cell = $ages[$age] ?? null)
                                    <td class="py-1.5 pr-3">
                                        @if($cell === null)
                                            <span class="text-gray-300"></span>
                                        @else
                                            {{ $revenue ? (Arithmetic::percent($cell['mrr_ore'], $start['mrr_ore']) ?? '–') : (Arithmetic::percent($cell['customers'], $start['customers']) ?? '–') }}
                                        @endif
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
