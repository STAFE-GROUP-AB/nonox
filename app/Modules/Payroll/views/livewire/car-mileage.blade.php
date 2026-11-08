@php
    use App\Domain\Mileage\Distance;
    use App\Support\Money\Money;
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div>
    @if($enabled)
        <x-ui.card :title="__('Körjournal för företagets bilar')" :description="__('Underlag från körjournalen. Det är ett stöd för dig som sköter lönerna: NONOX ändrar aldrig förmånsvärdet själv.')">
            <div class="flex items-center gap-3">
                <label for="car_mileage_year" class="text-sm text-gray-600">{{ __('År') }}</label>
                <input id="car_mileage_year" type="number" min="2000" max="2100" class="w-28 rounded-lg border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model.live.debounce.500ms="year">
            </div>

            @if($summaries->isEmpty())
                <p class="mt-3 text-sm text-gray-600">{{ __('Inga av företagets bilar finns i körjournalen. Lägg till dem under Bokföring → Körjournal.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100 border-t border-gray-100">
                    @foreach($summaries as $summary)
                        @php($year = $summary['year'])
                        <li class="space-y-1 py-4 text-sm" wire:key="car-year-{{ $summary['car']->id }}">
                            <p class="font-medium text-gray-900">{{ $summary['car']->displayName() }} @if($summary['pool'])<span class="text-gray-600">· {{ __('ingen förmånsbil under året') }}</span>@endif</p>
                            <p class="text-gray-700">{{ __('I tjänsten: :distance', ['distance' => Distance::formatMil($year->businessHm)]) }} ·
                                @if($year->longBusinessMileageReached())
                                    <span class="font-medium text-green-800">{{ __('3 000 mil i tjänsten uppnått') }}</span>
                                @else
                                    {{ __(':left kvar till 3 000 mil', ['left' => $year->milToLongBusiness()]) }}
                                @endif
                            </p>
                            <p class="text-gray-700">{{ __('Privat: :distance vid :times tillfällen', ['distance' => Distance::formatMil($year->privateHm), 'times' => $year->privateOccasions]) }} · {{ $year->minorExtentText() }}</p>
                            @if($summary['pool'] && $summary['poolPrivate'])
                                <p class="rounded-lg bg-accent-50 p-2 text-accent-900">{{ __('Privat körning i en bil utan bilförmån kan vara en skattepliktig förmån.') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-500">{{ __('3 000 mil i tjänsten under ett kalenderår ger 75 procent av förmånsvärdet (inkomstskattelagen 61 kap. 9 §); markera det själv på bilen. Bilförmån i ringa omfattning kräver enligt Skatteverket både högst 100 mil och högst 10 tillfällen privat körning under året.') }}</p>

                <form wire:submit="propose" class="mt-6 space-y-3 border-t border-gray-100 pt-4">
                    <p class="text-sm font-medium text-gray-900">{{ __('Förslag till drivmedelsförmån för en månad') }}</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.field for="fuel_vehicle" :label="__('Bil')" error="fuel.vehicle_id">
                            <select id="fuel_vehicle" class="{{ $input }} mt-1" wire:model="fuel.vehicle_id">
                                <option value="">{{ __('Välj bil…') }}</option>
                                @foreach($cars as $car)<option value="{{ $car->id }}">{{ $car->displayName() }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="fuel_month" :label="__('Månad')" error="fuel.month">
                            <input id="fuel_month" type="month" class="{{ $input }} mt-1" wire:model="fuel.month">
                        </x-ui.field>
                        <x-ui.field for="fuel_cost" :label="__('Drivmedel som företaget betalat, kronor inklusive moms')" error="fuel.fuel_cost">
                            <input id="fuel_cost" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="fuel.fuel_cost">
                        </x-ui.field>
                    </div>
                    <x-button>{{ __('Räkna fram ett förslag') }}</x-button>
                    @if($proposal)
                        <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-800" data-fuel-proposal>
                            <p>{{ __('Privat körning :private av :total. Marknadsvärde :cost × :private / :total = :market.', ['private' => Distance::formatKm($proposal['private_hm']), 'total' => Distance::formatKm($proposal['total_hm']), 'cost' => $fuel['fuel_cost'], 'market' => Money::ofOre($proposal['market_value_ore'])->format()]) }}</p>
                            <p class="font-medium">{{ __('Föreslagen drivmedelsförmån: :benefit (marknadsvärdet × :factor).', ['benefit' => Money::ofOre($proposal['benefit_ore'])->format(), 'factor' => number_format($proposal['factor_bp'] / 10000, 1, ',', '')]) }}</p>
                            <p class="mt-1 text-gray-600">{{ __('Ett förslag, inget beslut. Vill du använda det, lägg in beloppet med lönearten Drivmedelsförmån i månadens lönekörning.') }}</p>
                        </div>
                    @endif
                </form>
            @endif
        </x-ui.card>
    @endif
</div>
