@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format())
<div class="space-y-6">
    <x-ui.card>
        <p class="text-sm text-gray-600">{{ __('Underlaget visar lön och premier per anställd för lönekörningar som är attesterade och betalas ut under månaden. Arbetarnas lön rapporteras till Fora varje månad, tjänstemännens lön och ITP till Collectum. NONOX skickar ingenting själv: ladda ner underlaget och rapportera i Foras och Collectums egna tjänster. Filen innehåller personnummer, och varje nedladdning loggas.') }}</p>
        <x-ui.field for="report_month" :label="__('Utbetalningsmånad')" class="mt-4">
            <input id="report_month" type="month" wire:model.live="month" value="{{ $selectedMonth }}" class="mt-1 block w-44 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums">
        </x-ui.field>
    </x-ui.card>

    @foreach([['key' => App\Modules\Agreements\Support\PremiumBasis::FORA, 'title' => __('Fora – arbetare'), 'rows' => $fora, 'slug' => 'fora'], ['key' => App\Modules\Agreements\Support\PremiumBasis::COLLECTUM, 'title' => __('Collectum – tjänstemän'), 'rows' => $collectum, 'slug' => 'collectum']] as $report)
        <x-ui.card :title="$report['title']">
            @if($report['rows'] === [])
                <p class="text-sm text-gray-600">{{ __('Inga attesterade löner för anställda under ett avtal den här månaden.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Kontant bruttolön') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Tjänstepension') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Avtalsförsäkringar') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($report['rows'] as $row)
                                <tr>
                                    <td class="py-2 pr-4">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-4 text-gray-600">{{ $row['agreement'] }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $kr($row['cash_gross_ore']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $kr($row['pension_ore']) }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $kr(array_sum($row['insurances'])) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($canDownload)
                    <a href="{{ route('agreements.reports.download', ['rapport' => $report['slug'], 'manad' => $selectedMonth]) }}" class="mt-4 inline-flex text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner underlag (CSV)') }}</a>
                @endif
            @endif
        </x-ui.card>
    @endforeach
</div>
