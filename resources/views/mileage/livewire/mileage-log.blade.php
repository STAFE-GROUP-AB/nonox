@php
    use App\Domain\Mileage\Distance;
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div class="space-y-6">
    <x-ui.card :title="__('Bilar')" :description="__('Företagets bilar och, för dig själv, din egen bil. En bil arkiveras i stället för att tas bort, så att resorna i den finns kvar.')">
        @if($vehicles->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga bilar ännu. Lägg till företagets bilar nedan, eller din egen bil när du lägger in en resa.') }}</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($vehicles as $car)
                    @php($reading = $readingRows->get($car->id))
                    <li class="space-y-2 py-3 text-sm" wire:key="vehicle-{{ $car->id }}">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="min-w-0 flex-1 basis-48">
                                <span class="block font-medium text-gray-900">{{ $car->displayName() }}</span>
                                <span class="block text-gray-600">{{ $car->kind->label() }}@if($car->fuel) · {{ App\Modules\Payroll\Domain\Benefits\CarBenefitCar::fuelLabel($car->fuel) }}@endif @if($car->is_business_asset) · {{ __('tillgång i firman') }}@endif</span>
                            </span>
                            <a href="{{ route('mileage.index', ['bil' => $car->id, 'manad' => $month]) }}" class="font-medium text-accent-700 underline" wire:navigate>{{ __('Visa körjournalen') }}</a>
                            @if($manager || $car->user_id === auth()->id())
                                <button type="button" class="text-gray-600 underline" wire:click="archiveVehicle({{ $car->id }})" wire:confirm="{{ __('Arkivera bilen? Den går inte att välja för nya resor, men resorna finns kvar.') }}">{{ __('Arkivera') }}</button>
                            @endif
                        </div>
                        @if($manager || $car->user_id === auth()->id())
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="text-gray-600">{{ __('Mätarställning vid årets början :year, km', ['year' => $year]) }}
                                    <input type="number" min="0" class="{{ $input }} mt-1 w-36" wire:model="readings.{{ $car->id }}.start" @disabled($reading?->closed_at)>
                                </label>
                                <label class="text-gray-600">{{ __('vid årets slut, km') }}
                                    <input type="number" min="0" class="{{ $input }} mt-1 w-36" wire:model="readings.{{ $car->id }}.end" @disabled($reading?->closed_at)>
                                </label>
                                @if($reading?->closed_at)
                                    <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-800">{{ __('Året är stängt') }}</span>
                                @else
                                    <x-secondary-button type="button" wire:click="saveReading({{ $car->id }})">{{ __('Spara') }}</x-secondary-button>
                                    <button type="button" class="text-gray-700 underline" wire:click="closeYear({{ $car->id }})" wire:confirm="{{ __('Stänga året i körjournalen för bilen? Mätarställningarna kan sedan inte ändras.') }}">{{ __('Stäng året') }}</button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mt-3 flex items-center gap-3 text-sm">
            <label for="mileage_year" class="text-gray-600">{{ __('År för mätarställningar') }}</label>
            <input id="mileage_year" type="number" min="2000" max="2100" class="w-28 rounded-lg border-gray-300 text-sm shadow-xs" wire:model.live.debounce.500ms="year">
        </div>

        @if($manager)
            <form wire:submit="saveCompanyCar" class="mt-4 grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-4" aria-label="{{ __('Lägg till företagets bil') }}">
                <x-ui.field for="company_car_label" :label="__('Märke och modell')" error="label">
                    <input id="company_car_label" type="text" class="{{ $input }} mt-1" wire:model="companyCar.label">
                </x-ui.field>
                <x-ui.field for="company_car_registration" :label="__('Registreringsnummer')" error="registration_number">
                    <input id="company_car_registration" type="text" class="{{ $input }} mt-1" wire:model="companyCar.registration_number">
                </x-ui.field>
                <x-ui.field for="company_car_fuel" :label="__('Drivmedel')" error="fuel">
                    <select id="company_car_fuel" class="{{ $input }} mt-1" wire:model="companyCar.fuel">
                        @foreach($fuels as $fuel)<option value="{{ $fuel }}">{{ App\Modules\Payroll\Domain\Benefits\CarBenefitCar::fuelLabel($fuel) }}</option>@endforeach
                    </select>
                </x-ui.field>
                <div class="flex items-end"><x-button>{{ __('Lägg till företagets bil') }}</x-button></div>
                <p class="text-xs text-gray-500 sm:col-span-4">{{ __('Är bilen någons förmånsbil under Lön → Förmånsbilar, kopplas den dit med registreringsnumret. Annars räknas den som en bil utan bilförmån.') }}</p>
            </form>
        @endif
    </x-ui.card>

    <div class="flex items-center gap-3">
        <label for="log_month" class="text-sm font-medium text-gray-700">{{ __('Månad') }}</label>
        <input id="log_month" type="month" class="rounded-lg border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model.live="month">
    </div>

    @if($selected)
        <x-ui.card :title="__('Körjournal för :car, :month', ['car' => $selected->displayName(), 'month' => $monthName])">
            @if($carLog->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inga resor med bilen i :month.', ['month' => $monthName]) }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-1 pr-3 font-medium">{{ __('Datum') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ __('Förare') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ __('Typ') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ __('Från – till') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ __('Ärende') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ __('Mätare') }}</th>
                                <th class="py-1 text-right font-medium">{{ __('Sträcka') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($carLog as $row)
                                <tr wire:key="log-{{ $row->id }}">
                                    <td class="py-1.5 pr-3 tabular-nums">{{ $row->trip_date->toDateString() }}</td>
                                    <td class="py-1.5 pr-3">{{ $drivers[$row->driver_user_id] ?? '' }}</td>
                                    <td class="py-1.5 pr-3">{{ $row->kind->label() }}</td>
                                    <td class="py-1.5 pr-3">{{ $row->route() }}</td>
                                    <td class="py-1.5 pr-3">{{ $row->purpose }}</td>
                                    <td class="py-1.5 pr-3 tabular-nums">@if($row->odometer_start_km !== null){{ $row->odometer_start_km }}–{{ $row->odometer_end_km }}@endif</td>
                                    <td class="py-1.5 text-right tabular-nums">{{ $row->distanceLabel() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-sm text-gray-700">{{ __('Summa: :distance', ['distance' => Distance::formatKm((int) $carLog->sum('distance_hm'))]) }}</p>
            @endif
        </x-ui.card>
    @endif

    @if($canLog)
        <x-ui.card :title="__('Mina resor')">
            <div class="flex justify-end">
                @unless($tripOpen)
                    <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:border-gray-300" wire:click="startTrip">{{ __('Ny resa') }}</button>
                @endunless
            </div>
            @if($tripOpen)
                @include('mileage.partials.trip-form', ['allowOwnCar' => true])
            @endif
            @if($carOpen && $owner)
                <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="car.is_business_asset"> {{ __('Bilen är en tillgång i firman') }}</label>
            @endif
            @include('mileage.partials.trip-list', ['empty' => __('Inga resor i :month.', ['month' => $monthName])])
        </x-ui.card>
    @endif

    @if($owner)
        <x-ui.card :title="__('Bokför bilresor i egen bil')" :description="__('För resor i firman med din egen bil får du dra av 25 kronor per mil (inkomstskattelagen 16 kap. 27 §). Inte för en bil som är en tillgång i firman.')">
            @if($unchecked)
                <div class="mb-4 rounded-lg bg-accent-50 p-3 text-sm text-accent-900">
                    <p>{{ __('Beloppet per mil för :year är :amount enligt :source. Jämför med källan innan du bokför.', ['year' => $unchecked->valid_from->year, 'amount' => App\Support\Money\Money::ofOre((int) ($unchecked->value['ore'] ?? 0))->format(), 'source' => $unchecked->source_url]) }}</p>
                    <button type="button" class="mt-2 font-medium underline" wire:click="verifyRate({{ $unchecked->id }})" wire:confirm="{{ __('Markera beloppet som kontrollerat mot källan?') }}">{{ __('Jag har jämfört beloppet med källan') }}</button>
                </div>
            @endif
            @if($bookable->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inga tjänsteresor i egen bil att bokföra för :month.', ['month' => $monthName]) }}</p>
            @else
                <form wire:submit="bookMonth" class="space-y-3">
                    <p class="text-sm text-gray-700">{{ __(':count resor, :distance, i :month.', ['count' => $bookable->count(), 'distance' => Distance::formatMil((int) $bookable->sum('distance_hm')), 'month' => $monthName]) }}</p>
                    <x-ui.field for="cost_account" :label="__('Kostnadskonto')" error="account">
                        <select id="cost_account" class="{{ $input }} mt-1 max-w-md" wire:model="costAccount">
                            @foreach($accounts as $account)<option value="{{ $account->number }}">{{ $account->number }} {{ $account->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <p class="text-xs text-gray-500">{{ __('Utkastet debiterar kontot du väljer och krediterar 2018 Övriga egna insättningar, utan moms, med körjournalen som underlag. Du granskar och bokför det själv. Vilket konto som passar bäst är inte färdigutrett; fråga gärna din redovisningskonsult. Du kan också låta bli att bokföra och i stället göra avdraget i NE-bilagan.') }}</p>
                    <x-button wire:confirm="{{ __('Skapa ett verifikationsutkast för :month? Resorna låses tills utkastet är bokfört eller borttaget.', ['month' => $monthName]) }}">{{ __('Bokför :month', ['month' => $monthName]) }}</x-button>
                </form>
            @endif
        </x-ui.card>
    @endif

    <p class="text-xs text-gray-500">{{ __('Körjournalen används för att betala milersättning, bokföra avdraget för egen bil och som underlag för förmånsbilar. Den används aldrig för att följa upp hur någon arbetar, och NONOX läser aldrig någons position.') }}</p>
</div>
