@php
    use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
    use App\Modules\Payroll\Domain\Benefits\CarBenefitValue;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $kr = fn (int $ore): string => Money::ofOre($ore)->format();
@endphp
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-base font-bold text-ink">{{ __('Bilar och förmånsvärden') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('Värdet per år räknas med prisbasbeloppet och statslåneräntan för året. Varje månad som den anställda har bilen någon dag tas en tolftedel med som skattepliktig förmån.') }}</p>
            </div>
            <x-ui.field for="cb-year" :label="__('Inkomstår')">
                <input id="cb-year" type="number" min="2021" max="2100" wire:model.live.debounce.500ms="year" class="{{ $input }} mt-1 w-28 tabular-nums">
            </x-ui.field>
        </div>

        @if($cars->isEmpty())
            <x-ui.empty-state :title="__('Inga förmånsbilar')">{{ __('Registrera en bil nedan när en anställd får använda en av företagets bilar privat.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Bil') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Period') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Förmånsvärde per år') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Per månad') }}</th>
                            <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums">
                        @foreach($cars as $car)
                            @php($value = $values[$car->id] ?? null)
                            <tr wire:key="car-{{ $car->id }}">
                                <td class="py-2 pr-4">{{ $car->employee?->first_name }} {{ $car->employee?->last_name }}</td>
                                <td class="py-2 pr-4">{{ $car->carLabel() }}</td>
                                <td class="py-2 pr-4">{{ $car->valid_from->toDateString() }} – {{ $car->valid_to?->toDateString() ?? __('tills vidare') }}</td>
                                @if($value instanceof CarBenefitValue)
                                    <td class="py-2 pr-4 text-right">{{ $kr($value->yearlyOre) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $kr($value->monthlyOre) }}</td>
                                @else
                                    <td colspan="2" class="py-2 pr-4 text-sm text-red-800">{{ $value }}</td>
                                @endif
                                <td class="py-2 text-right whitespace-nowrap">
                                    <button type="button" wire:click="$set('shown', {{ $car->id }})" class="text-sm font-semibold text-accent-700 hover:underline">{{ __('Visa beräkning') }}</button>
                                    @if($canManage)
                                        <button type="button" wire:click="edit({{ $car->id }})" class="ml-3 text-sm font-semibold text-accent-700 hover:underline">{{ __('Ändra') }}</button>
                                        <button type="button" wire:click="delete({{ $car->id }})" wire:confirm="{{ __('Ta bort förmånsbilen? Det går bara när bilen inte har funnits med i en attesterad lön.') }}" class="ml-3 text-sm font-semibold text-red-800 hover:underline">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @php($shownCar = $shown === null ? null : $cars->firstWhere('id', $shown))
    @if($shownCar !== null && ($values[$shownCar->id] ?? null) instanceof CarBenefitValue)
        @php($e = $values[$shownCar->id]->explanation)
        <x-ui.card>
            <h2 class="text-base font-bold text-ink">{{ __('Beräkning för :car, :year', ['car' => $shownCar->carLabel(), 'year' => $e['year']]) }}</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 tabular-nums">
                <dt class="text-gray-600">{{ __('Nybilspris med extrautrustning') }}</dt><dd class="text-right">{{ $kr($e['new_car_price_ore'] + $e['extra_equipment_ore']) }}</dd>
                @if($e['old_model'])
                    <dt class="text-gray-600">{{ __('Bilmodellen är sex år eller äldre: lägst fyra prisbasbelopp') }}</dt><dd class="text-right">{{ $kr($e['old_model_floor_ore']) }}</dd>
                @endif
                @if($e['reduction_ore'] > 0)
                    <dt class="text-gray-600">{{ __('Nedsättning för miljöbil') }}</dt><dd class="text-right">−{{ $kr($e['reduction_ore']) }}</dd>
                @endif
                <dt class="text-gray-600">{{ __('Nybilspris i beräkningen') }}</dt><dd class="text-right">{{ $kr($e['reduced_price_ore']) }}</dd>
                <dt class="text-gray-600">{{ __('0,29 prisbasbelopp') }}</dt><dd class="text-right">{{ $kr($e['parts']['prisbasbelopp_ore']) }}</dd>
                <dt class="text-gray-600">{{ __('Ränterelaterat belopp, :rate % av nybilspriset', ['rate' => str_replace('.', ',', $e['interest_rate'])]) }}</dt><dd class="text-right">{{ $kr($e['parts']['interest_ore']) }}</dd>
                <dt class="text-gray-600">{{ __('Prisrelaterat belopp, 13 % av nybilspriset') }}</dt><dd class="text-right">{{ $kr($e['parts']['price_ore']) }}</dd>
                <dt class="text-gray-600">{{ __('Fordonsskatt') }}</dt><dd class="text-right">{{ $kr($e['parts']['vehicle_tax_ore']) }}</dd>
                @if($e['long_business_mileage'])
                    <dt class="text-gray-600">{{ __('Fullt värde') }}</dt><dd class="text-right">{{ $kr($e['full_ore']) }}</dd>
                    <dt class="text-gray-600">{{ __('Minst 3 000 mil i tjänsten: 75 %') }}</dt><dd class="text-right">{{ $kr($e['yearly_ore']) }}</dd>
                @endif
                <dt class="font-semibold">{{ __('Förmånsvärde per år') }}</dt><dd class="text-right font-semibold">{{ $kr($e['yearly_ore']) }}</dd>
                <dt class="font-semibold">{{ __('Per månad med bilen') }}</dt><dd class="text-right font-semibold">{{ $kr($e['monthly_ore']) }}</dd>
            </dl>
            @php($own = (int) (($shownCar->car_data ?? [])['own_payment_monthly_ore'] ?? 0))
            @if($own > 0)
                <p class="mt-3 text-sm text-gray-600">{{ __('Den anställda betalar :amount per månad genom nettolöneavdrag. Det dras från nettolönen och minskar förmånsvärdet lika mycket.', ['amount' => $kr($own)]) }}</p>
            @endif
        </x-ui.card>
    @endif

    @if($canManage)
        <x-ui.card>
            <h2 class="text-base font-bold text-ink">{{ $editing === null ? __('Registrera förmånsbil') : __('Ändra förmånsbil') }}</h2>
            <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field for="cb-employee" :label="__('Anställd')" error="employee_id">
                    <select id="cb-employee" wire:model="form.employee_id" class="{{ $input }} mt-1">
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="cb-make" :label="__('Märke och modell')" error="make_model">
                    <input id="cb-make" wire:model="form.make_model" maxlength="120" class="{{ $input }} mt-1">
                </x-ui.field>
                <x-ui.field for="cb-reg" :label="__('Registreringsnummer')" error="registration_number">
                    <input id="cb-reg" wire:model="form.registration_number" maxlength="10" class="{{ $input }} mt-1 uppercase">
                </x-ui.field>
                <x-ui.field for="cb-model-year" :label="__('Årsmodell')" error="model_year">
                    <input id="cb-model-year" type="number" wire:model="form.model_year" class="{{ $input }} mt-1 tabular-nums">
                </x-ui.field>
                <x-ui.field for="cb-taxable" :label="__('Skattepliktig i Sverige från')" :hint="__('Dagen bilen första gången blev skattepliktig enligt vägtrafikskattelagen, oftast dagen den togs i trafik.')" error="first_taxable_on">
                    <input id="cb-taxable" type="date" wire:model="form.first_taxable_on" class="{{ $input }} mt-1">
                </x-ui.field>
                <x-ui.field for="cb-fuel" :label="__('Drivmedel')" error="fuel">
                    <select id="cb-fuel" wire:model="form.fuel" class="{{ $input }} mt-1">
                        @foreach(CarBenefitCar::FUELS as $fuel)
                            <option value="{{ $fuel }}">{{ CarBenefitCar::fuelLabel($fuel) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="cb-price" :label="__('Nybilspris (kr)')" :hint="__('Bilmodellens pris när den kom ut på den svenska marknaden, enligt Skatteverkets lista över nybilspriser.')" error="new_car_price">
                    <input id="cb-price" inputmode="decimal" wire:model="form.new_car_price" class="{{ $input }} mt-1 tabular-nums" placeholder="400 000">
                </x-ui.field>
                <x-ui.field for="cb-extra" :label="__('Extrautrustning (kr)')" :hint="__('Utrustning som ingår i förmånen men inte i nybilspriset, till exempel vinterdäck. Inte alkolås.')" error="extra_equipment">
                    <input id="cb-extra" inputmode="decimal" wire:model="form.extra_equipment" class="{{ $input }} mt-1 tabular-nums">
                </x-ui.field>
                <x-ui.field for="cb-tax" :label="__('Fordonsskatt per år (kr)')" error="vehicle_tax">
                    <input id="cb-tax" inputmode="decimal" wire:model="form.vehicle_tax" class="{{ $input }} mt-1 tabular-nums">
                </x-ui.field>
                <x-ui.field for="cb-own" :label="__('Egen betalning per månad (kr)')" :hint="__('Det den anställda betalar för bilen genom avdrag från nettolönen.')" error="own_payment_monthly">
                    <input id="cb-own" inputmode="decimal" wire:model="form.own_payment_monthly" class="{{ $input }} mt-1 tabular-nums">
                </x-ui.field>
                <x-ui.field for="cb-from" :label="__('Första dag med bilen')" error="valid_from">
                    <input id="cb-from" type="date" wire:model="form.valid_from" class="{{ $input }} mt-1">
                </x-ui.field>
                <x-ui.field for="cb-to" :label="__('Sista dag med bilen')" :hint="__('Lämna tomt så länge den anställda har bilen.')" error="valid_to">
                    <input id="cb-to" type="date" wire:model="form.valid_to" class="{{ $input }} mt-1">
                </x-ui.field>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label for="cb-long" class="inline-flex items-start gap-2 text-sm text-gray-700">
                        <input id="cb-long" type="checkbox" wire:model="form.long_business_mileage" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                        <span>{{ __('Den anställda kör minst 3 000 mil i tjänsten med bilen under året (förmånsvärdet blir 75 %). Om det inte blir så ska redovisningen rättas till fullt värde.') }}</span>
                    </label>
                </div>
                <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    @if($editing !== null)
                        <button type="button" wire:click="cancel" class="text-sm font-semibold text-gray-700 hover:underline">{{ __('Avbryt') }}</button>
                    @endif
                    <p class="text-sm text-gray-600">{{ __('Drivmedel som företaget betalar för privat körning räknas inte här: lägg in det med lönearten Drivmedelsförmån.') }}</p>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
