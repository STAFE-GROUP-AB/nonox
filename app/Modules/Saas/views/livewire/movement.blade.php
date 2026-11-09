@php
    use App\Modules\Saas\Domain\Months;
    use App\Modules\Saas\Support\SaasReport;
    use App\Support\Money\Money;

    $key = $monthRow->key();
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__(':category, :month', ['category' => SaasReport::label($category), 'month' => Months::label($key)])" :lead="__('Kunderna bakom beloppet och avtalen bakom varje kund. Raderna summerar till månadens belopp.')">
        <x-slot:action>
            <a href="{{ route('saas.movements') }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Alla månader') }}</a>
        </x-slot:action>
    </x-ui.page-heading>

    <nav aria-label="{{ __('Kategorier') }}" class="flex flex-wrap gap-2">
        @foreach($categories as $each)
            <a href="{{ route('saas.movements.show', ['month' => substr($key, 0, 7), 'kategori' => $each]) }}"
               @if($each === $category) aria-current="page" @endif
               @class(['rounded-full px-3 py-1 text-sm font-medium ring-1', 'bg-ink text-white ring-ink' => $each === $category, 'text-gray-700 ring-stone-300 hover:bg-stone-50' => $each !== $category])>{{ SaasReport::label($each) }}</a>
        @endforeach
    </nav>

    <x-ui.card>
        <x-slot:action>
            <a href="{{ route('saas.export', ['list' => 'manad', 'manad' => substr($key, 0, 7), 'kategori' => $category]) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
        </x-slot:action>

        @if($rows->isEmpty())
            <x-ui.empty-state :title="__('Inga kunder i den här kategorin')">{{ __('Ingen kund rörde sig så den här månaden.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __(':category, :month', ['category' => SaasReport::label($category), 'month' => Months::label($key)]) }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('MRR vid månadens slut') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Avtal och rader') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 align-top">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">
                                    <a href="{{ route('saas.customers.show', ['customer' => $row->customer_id]) }}" class="font-medium text-ink hover:text-accent-700 hover:underline">{{ $row->customer?->name ?? __('Kund :id', ['id' => $row->customer_id]) }}</a>
                                    @if($row->reclass_ore !== 0)
                                        <span class="block text-xs text-gray-600">{{ __('Pausad längre än 12 månader: räknas nu som churn.') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre(SaasReport::amount($row, $category))" /></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($row->mrr_ore)" /></td>
                                <td class="py-2">@include('saas::partials.sources', ['lines' => $row->lineMonths(), 'labels' => $labels, 'mrr' => $row->mrr_ore])</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <th scope="row" class="py-2 pr-4 text-left">{{ __('Summa') }}</th>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($total)" /></td>
                            <td colspan="2" class="py-2 text-sm font-normal {{ $total === $expected ? 'text-green-800' : 'text-red-800' }}">
                                {{ $total === $expected ? __('Stämmer med månadens belopp.') : __('Skiljer sig från månadens belopp :amount. Kör en omräkning eller kontakta supporten.', ['amount' => Money::ofOre($expected)->format()]) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
