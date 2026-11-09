@php
    use App\Modules\Saas\Domain\Months;
    use App\Modules\Saas\Domain\SourceType;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Uppsagd MRR')" :lead="__('Avtal som är uppsagda eller har ett slutdatum men fortfarande löper. Kunden betalar perioden ut, så intäkten räknas som churn först den månad då avtalet har upphört, inte när uppsägningen kom.')" />

    @include('saas::partials.note')

    @if($contracts === [])
        <x-ui.empty-state :title="__('Inga uppsagda avtal')">{{ __('Inget avtal som räknas har ett slutdatum just nu.') }}</x-ui.empty-state>
    @else
        <x-ui.card :title="__('Per :month', ['month' => Months::label($latest->key())])">
            <x-slot:action>
                <a href="{{ route('saas.export', ['list' => 'uppsagda']) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
            </x-slot:action>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Uppsagda avtal') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('MRR som upphör') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Sista dag') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Churn i') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($contracts as $contract)
                            <tr>
                                <td class="py-2 pr-4"><a href="{{ route('saas.customers.show', ['customer' => $contract['customer_id']]) }}" class="font-medium text-ink hover:text-accent-700 hover:underline">{{ $contract['customer']?->name ?? __('Kund :id', ['id' => $contract['customer_id']]) }}</a></td>
                                <td class="py-2 pr-4">{{ SourceType::from($contract['source_type'])->label() }} · {{ $contract['description'] }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($contract['mrr_ore'])" /></td>
                                <td class="py-2 pr-4 tabular-nums whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($contract['ends'])->subDay()->toDateString() }}</td>
                                <td class="py-2 whitespace-nowrap">{{ Months::label($contract['churn_month']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <th scope="row" colspan="2" class="py-2 pr-4 text-left">{{ __('Summa') }}</th>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre(array_sum(array_column($contracts, 'mrr_ore')))" /></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
