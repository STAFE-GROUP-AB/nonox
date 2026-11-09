@php
    use App\Modules\Saas\Domain\Months;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Kunder med återkommande intäkter')" :lead="__('MRR per kund vid månadens slut, hur den ändrades under månaden, kohort och vilka avtal den kommer från.')">
        <x-slot:action>
            @include('saas::partials.month-picker')
        </x-slot:action>
    </x-ui.page-heading>

    @include('saas::partials.note')

    @if($selected === null || $rows === null || $rows->total() === 0)
        <x-ui.empty-state :title="__('Inga kunder än')">{{ __('Kunder med ett avtal eller en avtalsfakturering visas här.') }}</x-ui.empty-state>
    @else
        <x-ui.card :title="Months::label($selected->key())">
            <x-slot:action>
                <a href="{{ route('saas.export', ['list' => 'kunder', 'manad' => substr($selected->key(), 0, 7)]) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
            </x-slot:action>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Kunder, :month', ['month' => Months::label($selected->key())]) }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('MRR') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Förändring') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kohort') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Källa') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rows as $row)
                            @php($change = $row->correction_ore + $row->fx_ore + $row->source_ore + $row->movement_ore)
                            <tr>
                                <td class="py-2 pr-4"><a href="{{ route('saas.customers.show', ['customer' => $row->customer_id]) }}" class="font-medium text-ink hover:text-accent-700 hover:underline">{{ $row->customer?->name ?? __('Kund :id', ['id' => $row->customer_id]) }}</a></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($row->mrr_ore)" /></td>
                                <td class="py-2 pr-4">
                                    @if($row->movement !== null)
                                        {{ $row->movement->label() }}
                                    @endif
                                    @if($change !== 0)
                                        <x-ui.amount :money="Money::ofOre($change)" />
                                    @elseif($row->movement === null)
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row->cohort_month === null ? '–' : Months::short($row->cohort_month->toDateString()) }}</td>
                                <td class="py-2 pr-4">{{ collect($row->lineMonths())->map(fn ($line) => $line->sourceType->label())->unique()->implode(', ') ?: '–' }}</td>
                                <td class="py-2">{{ $row->state->label() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $rows->links() }}</div>
        </x-ui.card>
    @endif
</div>
