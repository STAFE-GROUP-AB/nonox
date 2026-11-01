@php
    $input = 'block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $done = $migration?->status === 'COMPLETE';
@endphp
<div class="space-y-6">
    @if($migration === null)
        <x-ui.card :title="__('Kommer ni från ett annat lönesystem?')">
            <p class="text-sm text-gray-600">{{ __('Läs in de anställda, lön, skatt och arbetsgivaravgifter hittills i år, semestersaldona och de gamla lönebeskeden innan den första lönen räknas här. Bokföringen för året läses in för sig, som SIE-fil. Medan flytten pågår kan ingen lönekörning räknas.') }}</p>
            <form wire:submit="start" class="mt-4 flex flex-wrap items-end gap-4">
                <x-ui.field for="migration_year" :label="__('Året som beloppen gäller')" error="year">
                    <input id="migration_year" type="number" class="block w-28 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model="year">
                </x-ui.field>
                <x-button wire:loading.attr="disabled">{{ __('Starta flytten') }}</x-button>
            </form>
        </x-ui.card>
    @else
        <x-ui.card>
            <p class="text-sm font-medium {{ $done ? 'text-gray-900' : 'text-accent-700' }}">
                {{ $done ? __('Flytten är klar :date. Beloppen hittills i år :year stämmer med bokföringen.', ['date' => $migration->completed_at?->toDateString(), 'year' => $migration->year]) : __('Flytten pågår för :year. Ingen lönekörning kan räknas förrän den är klar.', ['year' => $migration->year]) }}
            </p>
            <p class="mt-2 text-sm text-gray-600">{{ __('Filerna är semikolonseparerade CSV med kolumnnamnen på första raden. Hämta en mall för varje steg. Belopp skrivs i kronor, till exempel 32 000,00. En fil läses in bara när alla rader är rätt, och samma fil kan läsas in igen efter rättning.') }}</p>
        </x-ui.card>

        @foreach([
            ['employeesFile', 'importEmployees', 'anstallda', __('1. Anställda'), __('En rad per anställd med anställningsnumret från det tidigare systemet, som de andra filerna använder.'), count($migration->report['employees'] ?? []) > 0 ? __(':count inlästa', ['count' => count($migration->report['employees'] ?? [])]) : null],
            ['yearToDateFile', 'importYearToDate', 'hittills-i-ar', __('2. Hittills i år'), __('Bruttolön, skatt, avgiftsunderlag och arbetsgivaravgifter för året till och med den sista lönen i det tidigare systemet. Används för skatt på engångsbelopp, avgifter och årsbeskedet.'), isset($migration->report['year_to_date']) ? __(':count inlästa', ['count' => count($migration->report['year_to_date'])]) : null],
            ['vacationFile', 'importVacation', 'semester', __('3. Semester'), __('Saldot för det semesterår som pågår: intjänade, uttagna och sparade dagar, och semesterlönegrundande lön för procentregeln.'), $vacationCount > 0 ? __(':count inlästa', ['count' => $vacationCount]) : null],
        ] as [$property, $action, $template, $title, $text, $status])
            <x-ui.card :title="$title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <p class="max-w-2xl text-sm text-gray-600">{{ $text }}</p>
                    @if($status)<x-ui.badge tone="success">{{ $status }}</x-ui.badge>@endif
                </div>
                @unless($done)
                    <form wire:submit="{{ $action }}" class="mt-4 flex flex-wrap items-center gap-4">
                        <input type="file" accept=".csv,text/csv" class="{{ $input }} max-w-sm" wire:model="{{ $property }}" aria-label="{{ $title }}">
                        <x-button wire:loading.attr="disabled">{{ __('Läs in') }}</x-button>
                        <x-ui.link :href="route('payroll.migration.template', $template)">{{ __('Hämta mall') }}</x-ui.link>
                    </form>
                    <x-input-error :for="$property" class="mt-2" />
                @endunless
            </x-ui.card>
        @endforeach

        <x-input-error for="file" />

        <x-ui.card :title="__('4. Gamla lönebesked')">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <p class="max-w-2xl text-sm text-gray-600">{{ __('Lönebeskeden från det tidigare systemet som PDF, döpta anställningsnummer_ÅÅÅÅ-MM.pdf. De sparas i Lönearkivet och visas för den anställde på Min sida. Lönebesked ska sparas lika länge som annan räkenskapsinformation.') }}</p>
                @if($payslipCount > 0)<x-ui.badge tone="success">{{ __(':count sparade', ['count' => $payslipCount]) }}</x-ui.badge>@endif
            </div>
            @unless($done)
                <form wire:submit="importPayslips" class="mt-4 flex flex-wrap items-center gap-4">
                    <input type="file" multiple accept="application/pdf" class="{{ $input }} max-w-sm" wire:model="payslipFiles" aria-label="{{ __('Gamla lönebesked') }}">
                    <x-button wire:loading.attr="disabled">{{ __('Spara lönebeskeden') }}</x-button>
                </form>
            @endunless
            <x-input-error for="payslipFiles" class="mt-2" />
            <x-input-error for="payslips" class="mt-2" />
        </x-ui.card>

        @if($reconciliation)
            <x-ui.card :title="__('5. Avstämning mot bokföringen')">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Bruttolön') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Skatt') }}</th><th class="py-2 text-right font-medium">{{ __('Arbetsgivaravgifter') }}</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($reconciliation['employees'] as $row)
                                <tr><td class="py-2 pr-4">{{ $row['name'] }}</td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['gross_ore'])" :currency="false" /></td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['tax_ore'])" :currency="false" /></td><td class="py-2 text-right"><x-ui.amount :money="$money($row['contributions_ore'])" :currency="false" /></td></tr>
                            @endforeach
                        </tbody>
                        <tfoot class="font-semibold">
                            <tr class="border-t border-gray-300"><td class="py-2 pr-4" colspan="2">{{ __('Summa inläst') }}</td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($reconciliation['tax_ore'])" :currency="false" /></td><td class="py-2 text-right"><x-ui.amount :money="$money($reconciliation['contributions_ore'])" :currency="false" /></td></tr>
                            <tr><td class="py-2 pr-4" colspan="2">{{ __('Bokfört :year (2710 kredit, 7510 netto)', ['year' => $migration->year]) }}</td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($reconciliation['booked_tax_ore'])" :currency="false" /></td><td class="py-2 text-right"><x-ui.amount :money="$money($reconciliation['booked_contributions_ore'])" :currency="false" /></td></tr>
                        </tfoot>
                    </table>
                </div>
                <p @class(['mt-4 text-sm font-medium', 'text-gray-900' => $reconciliation['agrees'], 'text-accent-700' => ! $reconciliation['agrees']])>
                    {{ $reconciliation['agrees'] ? __('Beloppen stämmer med bokföringen.') : __('Beloppen stämmer inte med bokföringen. Läs in bokföringen för året som SIE-fil eller rätta filen med beloppen hittills i år.') }}
                </p>
                @if(! $done && $reconciliation['agrees'])
                    <x-button type="button" class="mt-4" wire:click="complete" wire:loading.attr="disabled">{{ __('Flytten är klar') }}</x-button>
                @endif
                <x-input-error for="complete" class="mt-2" />
            </x-ui.card>
        @endif
    @endif
</div>
