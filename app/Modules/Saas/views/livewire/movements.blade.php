@php
    use App\Modules\Saas\Domain\Months;
    use App\Modules\Saas\Support\SaasReport;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Rörelser per månad')" :lead="__('MRR vid månadens slut = ingående MRR + rättelse + valutaeffekt ± källa av eller på + nya + återaktiverade + återupptagna + utökningar − minskningar − pauser − churn. Varje belopp öppnar kunderna bakom det.')" />

    @include('saas::partials.note')

    @if($months->isEmpty())
        <x-ui.empty-state :title="__('Inga månader än')">{{ __('Månaderna räknas fram när det finns ett avtal eller en avtalsfakturering.') }}</x-ui.empty-state>
    @else
        <x-ui.card>
            <x-slot:action>
                <a href="{{ route('saas.export', ['list' => 'rorelser']) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
            </x-slot:action>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Rörelser per månad') }}</caption>
                    <thead>
                        <tr class="text-right text-gray-600">
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Månad') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Ingående MRR') }}</th>
                            @foreach(array_keys(SaasReport::CATEGORIES) as $category)
                                <th scope="col" class="py-2 pr-4 font-medium">{{ SaasReport::label($category) }}</th>
                            @endforeach
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('MRR') }}</th>
                            <th scope="col" class="py-2 text-left font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($months as $key => $month)
                            <tr class="text-right">
                                <th scope="row" class="py-2 pr-4 text-left font-medium text-ink whitespace-nowrap">{{ Months::short($key) }}</th>
                                <td class="py-2 pr-4"><x-ui.amount :money="Money::ofOre($month->opening_mrr_ore)" :currency="false" /></td>
                                @foreach(SaasReport::CATEGORIES as $category => $column)
                                    @php($value = (int) $month->getAttribute($column))
                                    <td class="py-2 pr-4">
                                        @if($value === 0)
                                            <span class="text-gray-400">–</span>
                                        @else
                                            <a href="{{ route('saas.movements.show', ['month' => substr($key, 0, 7), 'kategori' => $category]) }}" class="hover:underline" aria-label="{{ SaasReport::label($category) }} {{ Months::label($key) }}: {{ Money::ofOre($value)->format() }}"><x-ui.amount :money="Money::ofOre($value)" :currency="false" /></a>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="py-2 pr-4 font-semibold"><x-ui.amount :money="Money::ofOre($month->mrr_ore)" :currency="false" /></td>
                                <td class="py-2 text-left">
                                    @if($month->isClosed())
                                        <x-ui.badge tone="success">{{ __('Stängd') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Öppen') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Belopp i kronor.') }}</p>
        </x-ui.card>
    @endif
</div>
