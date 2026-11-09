@php
    use App\Modules\Saas\Domain\Months;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Rättelser av stängda månader')" :lead="__('En stängd månad ändras aldrig. Får NONOX veta något senare som gäller en stängd månad, till exempel en prisändring med ett tidigare datum, bokas skillnaden här som rättelse i den första öppna månaden.')" />

    @include('saas::partials.note')

    @if($corrections->total() === 0)
        <x-ui.empty-state :title="__('Inga rättelser')">{{ __('Ingenting har ändrats i en stängd månad efter att den stängdes.') }}</x-ui.empty-state>
    @else
        <x-ui.card>
            <x-slot:action>
                <a href="{{ route('saas.export', ['list' => 'rattelser']) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
            </x-slot:action>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Rättelser') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Bokad i') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Gäller månad') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Registrerad') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($corrections as $correction)
                            <tr>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ Months::label($correction->booked_month->toDateString()) }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ Months::label($correction->affects_month->toDateString()) }}</td>
                                <td class="py-2 pr-4"><a href="{{ route('saas.customers.show', ['customer' => $correction->customer_id]) }}" class="font-medium text-ink hover:text-accent-700 hover:underline">{{ $correction->customer?->name ?? __('Kund :id', ['id' => $correction->customer_id]) }}</a></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($correction->amount_ore)" /></td>
                                <td class="py-2 tabular-nums whitespace-nowrap">{{ $correction->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $corrections->links() }}</div>
        </x-ui.card>
    @endif
</div>
