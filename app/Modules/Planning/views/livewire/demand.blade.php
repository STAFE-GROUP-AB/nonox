@php
    use App\Modules\Planning\Domain\Demand\DayDemand;
    use App\Modules\Planning\Domain\WorkingTimeChecks;
    use App\Modules\Planning\Models\StaffingRule;
    use App\Modules\Planning\Support\Demand\DemandText;
    use App\Support\Money\Money;
    use Carbon\CarbonImmutable;

    $locale = app()->getLocale();
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $money = fn (?int $ore): string => $ore === null ? '–' : Money::ofOre($ore)->format();
    $weekdays = [1 => __('Måndag'), 2 => __('Tisdag'), 3 => __('Onsdag'), 4 => __('Torsdag'), 5 => __('Fredag'), 6 => __('Lördag'), 7 => __('Söndag')];
    $statusClass = [DayDemand::UNDER => 'bg-red-50 text-red-800', DayDemand::OVER => 'bg-amber-50 text-amber-900', DayDemand::OK => 'bg-emerald-50 text-emerald-800', DayDemand::NONE => 'text-gray-500'];
    $scopeName = $scopeArea?->name ?? __('Hela företaget');
    $percentSum = array_sum(array_map(fn ($value) => (float) str_replace(',', '.', (string) $value), $percents));
@endphp

<div class="space-y-6">
    {{-- Scope --}}
    <div class="flex flex-wrap items-end justify-between gap-3 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200">
        <label class="block text-sm">
            <span class="font-medium text-gray-700">{{ __('Gäller') }}</span>
            <select wire:model.live="area" class="{{ $input }} w-56">
                <option value="">{{ __('Hela företaget') }}</option>
                @foreach($areas as $one)
                    <option value="{{ $one->id }}">{{ $one->name }}</option>
                @endforeach
            </select>
        </label>
        <p class="max-w-2xl text-sm text-gray-600">
            @if($sales_scope === 'area')
                {{ __('Försäljningen är bokförd försäljning på avdelningens kostnadsställe.') }}
            @elseif($scopeArea)
                {{ __('Avdelningen har inget kostnadsställe, så försäljningen är hela företagets. Målen för avdelningen räknas mot den.') }}
            @else
                {{ __('Försäljningen är hela företagets bokförda försäljning.') }}
            @endif
        </p>
    </div>

    {{-- The next two weeks --}}
    <x-ui.card :title="__('Prognos de kommande två veckorna')" :description="__('Snittet av samma veckodag de senaste :weeks veckorna i bokföringen. Helgdagar och aftnar räknas inte med i snittet; en helgdag får samma helgdag förra året. Dagar utan försäljning hoppas över.', ['weeks' => $weeks])">
        <div class="-mx-2 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th scope="col" class="px-3 py-2">{{ __('Dag') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('Prognos') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('Så räknas den') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('Behov') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('Bemannat') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('Läge') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($days as $date => $day)
                        <tr wire:key="preview-{{ $date }}">
                            <th scope="row" class="whitespace-nowrap px-3 py-2 text-left font-medium text-ink">{{ ucfirst(CarbonImmutable::parse($date)->locale($locale)->isoFormat('ddd D MMM')) }}</th>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $money($day->forecast->amountOre) }}@if($day->forecast->isOverride()) <span class="text-xs font-semibold text-accent-700">{{ __('ändrad') }}</span>@endif</td>
                            <td class="px-3 py-2 text-gray-600">{{ DemandText::forecast($day->forecast) }} <span class="text-gray-500">{{ DemandText::shape($day->shape) }}.</span></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $day->needMinutes === null ? '–' : WorkingTimeChecks::hours($day->needMinutes) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ WorkingTimeChecks::hours($day->plannedMinutes) }}</td>
                            <td class="px-3 py-2">
                                @if($day->status === DayDemand::NONE)
                                    <span class="text-gray-500">{{ __('Inget mål') }}</span>
                                @else
                                    <span class="rounded-lg px-2 py-0.5 text-xs font-semibold {{ $statusClass[$day->status] }}">{{ DemandText::warning($day) ?? __('Bemannat enligt behovet') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">{{ __('Behovet är bemannade timmar. Bemannat är tiden personer har pass, utan att rasten dras av; öppna pass räknas inte. Varningarna stoppar ingenting.') }}</p>
    </x-ui.card>

    {{-- Staffing targets --}}
    <x-ui.card :title="__('Bemanningsmål för :scope', ['scope' => $scopeName])" :description="__('Hur mycket försäljning en bemannad timme ska bära, och hur många som minst ska vara på plats. Behovet en timme är det högsta av minsta bemanningen och försäljningen delad med målet, avrundat uppåt.')">
        @if($canManage && $editingRule === null)
            <x-slot:cta><x-button type="button" wire:click="createRule">{{ __('Nytt mål') }}</x-button></x-slot:cta>
        @endif

        @if($editingRule !== null)
            <form wire:submit="saveRule" class="mb-6 grid gap-5 rounded-2xl bg-stone-50 p-5 sm:grid-cols-3">
                <x-ui.field for="rule-weekday" :label="__('Veckodag')" error="rule.weekday">
                    <select id="rule-weekday" wire:model="rule.weekday" class="{{ $input }}">
                        <option value="">{{ __('Alla dagar') }}</option>
                        @foreach($weekdays as $number => $name)
                            <option value="{{ $number }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="rule-start" :label="__('Från')" error="rule.start">
                    <input id="rule-start" wire:model="rule.start" inputmode="numeric" placeholder="11:00" class="{{ $input }} tabular-nums">
                </x-ui.field>
                <x-ui.field for="rule-end" :label="__('Till')" :hint="__('24:00 är midnatt.')" error="rule.end">
                    <input id="rule-end" wire:model="rule.end" inputmode="numeric" placeholder="14:00" class="{{ $input }} tabular-nums">
                </x-ui.field>
                <x-ui.field for="rule-target" :label="__('Försäljning per bemannad timme')" :hint="__('I kronor utan moms. Lämna tomt om bara minsta bemanning gäller.')" error="rule.sales_per_staff_hour">
                    <input id="rule-target" wire:model="rule.sales_per_staff_hour" inputmode="decimal" placeholder="1 200" class="{{ $input }} tabular-nums">
                </x-ui.field>
                <x-ui.field for="rule-min" :label="__('Minsta bemanning')" :hint="__('Antal personer, till exempel två vid stängning.')" error="rule.min_staff">
                    <input id="rule-min" type="number" min="1" max="500" wire:model="rule.min_staff" class="{{ $input }} w-28 tabular-nums">
                </x-ui.field>
                <x-ui.field for="rule-from" :label="__('Gäller från')" error="rule.valid_from">
                    <input id="rule-from" type="date" wire:model="rule.valid_from" class="{{ $input }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-3">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancelRule">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif

        @if($rules->isEmpty())
            <x-ui.empty-state :title="__('Inga bemanningsmål ännu')">{{ __('Utan mål visas prognosen men inget behov. Lägg till ett mål för öppettiderna, till exempel 1 200 kr per bemannad timme och minst två personer.') }}</x-ui.empty-state>
        @else
            <div class="-mx-2 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th scope="col" class="px-3 py-2">{{ __('Veckodag') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('Tid') }}</th>
                            <th scope="col" class="px-3 py-2 text-right">{{ __('Försäljning per bemannad timme') }}</th>
                            <th scope="col" class="px-3 py-2 text-right">{{ __('Minsta bemanning') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('Gäller från') }}</th>
                            @if($canManage)<th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rules as $one)
                            <tr wire:key="rule-{{ $one->id }}">
                                <td class="px-3 py-2 font-medium text-ink">{{ $one->weekday === null ? __('Alla dagar') : $weekdays[$one->weekday] }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ StaffingRule::clock($one->start_minute) }}–{{ StaffingRule::clock($one->end_minute) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $money($one->sales_per_staff_hour_ore) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $one->min_staff ?? '–' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $one->valid_from->toDateString() }}</td>
                                @if($canManage)
                                    <td class="px-3 py-2 text-right">
                                        <span class="flex justify-end gap-3 text-sm font-semibold">
                                            <button type="button" wire:click="editRule({{ $one->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                                            <button type="button" wire:click="deleteRule({{ $one->id }})" class="text-gray-700 underline">{{ __('Ta bort') }}</button>
                                        </span>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Gäller flera mål samma timme används det med senast Gäller från, och ett mål för en viss veckodag före ett för alla dagar. Tider över midnatt läggs in som två mål.') }}</p>
        @endif
    </x-ui.card>

    {{-- Overrides --}}
    <x-ui.card :title="__('Ändra prognosen för en dag')" :description="__('När ni vet att en dag blir annorlunda, till exempel en konsert i närheten eller en stängd gata. Den ändrade siffran ersätter prognosen och visas som ändrad.')">
        @if($canManage)
            <form wire:submit="saveOverride" class="mb-5 grid gap-5 sm:grid-cols-4">
                <x-ui.field for="override-date" :label="__('Datum')" error="override.date">
                    <input id="override-date" type="date" wire:model="override.date" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="override-amount" :label="__('Försäljning')" :hint="__('I kronor utan moms.')" error="override.amount">
                    <input id="override-amount" wire:model="override.amount" inputmode="decimal" placeholder="45 000" class="{{ $input }} tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="override-reason" class="sm:col-span-2" :label="__('Anledning')" error="override.reason">
                    <input id="override-reason" wire:model="override.reason" maxlength="255" class="{{ $input }}" required>
                </x-ui.field>
                <div class="sm:col-span-4"><x-button type="submit">{{ __('Spara ändringen') }}</x-button></div>
            </form>
        @endif

        @if($overrides->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga ändrade dagar framåt.') }}</p>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($overrides as $one)
                    <li wire:key="override-{{ $one->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <span><span class="font-medium text-ink">{{ ucfirst($one->date->locale($locale)->isoFormat('ddd D MMM')) }}</span> · <span class="tabular-nums">{{ $money($one->amount_ore) }}</span> · <span class="text-gray-600">{{ $one->reason }}</span></span>
                        @if($canManage)
                            <button type="button" wire:click="deleteOverride({{ $one->id }})" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- Hour profile --}}
    <x-ui.card :title="__('Timprofil (egen uppskattning)')" :description="__('Hur dagens försäljning fördelar sig över timmarna, i procent. Används när ingen kassa ger fördelningen och visas alltid som en egen uppskattning. Utan timprofil och kassa visas behovet bara per dag.')">
        <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Veckodag') }}">
            @foreach($weekdays as $number => $name)
                <button type="button" wire:click="$set('profileDay', {{ $number }})" @class(['rounded-xl px-3 py-1.5 text-sm font-semibold ring-1', 'bg-ink text-white ring-ink' => $profileDay === $number, 'ring-stone-200 hover:bg-stone-50' => $profileDay !== $number]) aria-pressed="{{ $profileDay === $number ? 'true' : 'false' }}">
                    {{ $name }}@if(in_array($number, $profileDays, true)) <span aria-hidden="true">·</span><span class="sr-only">{{ __('har timprofil') }}</span>@endif
                </button>
            @endforeach
        </div>
        <form wire:submit="saveProfile">
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-6 lg:grid-cols-8">
                @for($hour = 0; $hour < 24; $hour++)
                    <label class="block text-xs" wire:key="hour-{{ $hour }}">
                        <span class="font-medium tabular-nums text-gray-600">{{ sprintf('%02d–%02d', $hour, $hour + 1) }}</span>
                        <span class="relative mt-1 block">
                            <input wire:model.live.debounce.400ms="percents.{{ $hour }}" inputmode="decimal" @disabled(! $canManage) class="block w-full rounded-lg border-gray-300 pe-6 text-right text-sm tabular-nums shadow-xs focus:border-accent-500 focus:ring-accent-500" aria-label="{{ __('Andel kl. :hour', ['hour' => sprintf('%02d', $hour)]) }}">
                            <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-gray-400">%</span>
                        </span>
                    </label>
                @endfor
            </div>
            <x-input-error for="percents" class="mt-2" />
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <p @class(['text-sm tabular-nums', 'font-semibold text-emerald-700' => abs($percentSum - 100) < 0.001, 'text-gray-600' => abs($percentSum - 100) >= 0.001])>{{ __('Summa: :sum %', ['sum' => str_replace('.', ',', (string) round($percentSum, 2))]) }}</p>
                @if($canManage)
                    <x-button type="submit">{{ __('Spara timprofilen') }}</x-button>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-500">{{ __('Tomma rutor är 0 %. Töm alla rutor och spara för att ta bort timprofilen för dagen.') }}</p>
        </form>
    </x-ui.card>

    {{-- Tills and weeks --}}
    <x-ui.card :title="__('Kassor och underlag')" :description="__('En kopplad kassa ger fördelningen över timmarna från kvittonas tid, utan moms och dricks. Dagens summa kommer alltid från bokföringen.')">
        @if($connections->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Ingen kassa är kopplad. Koppla en kassa under Kassakopplingar för att få fördelningen per timme från kvittona.') }}</p>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($connections as $connection)
                    <li wire:key="till-{{ $connection->id }}" class="flex flex-wrap items-end justify-between gap-3 py-3">
                        <span class="text-sm font-medium text-ink">{{ $connection->display_name ?: $connection->provider->label() }}</span>
                        <span class="flex items-end gap-3">
                            <label class="block text-sm">
                                <span class="sr-only">{{ __('Används för') }}</span>
                                <select wire:model="sources.{{ $connection->id }}" @disabled(! $canManage) class="{{ $input }} w-56">
                                    <option value="off">{{ __('Används inte') }}</option>
                                    <option value="company">{{ __('Hela företaget') }}</option>
                                    @foreach($areas as $one)
                                        <option value="{{ $one->id }}">{{ $one->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @if($canManage)
                                <x-secondary-button type="button" wire:click="saveSource({{ $connection->id }})">{{ __('Spara') }}</x-secondary-button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="saveWeeks" class="mt-6 flex flex-wrap items-end gap-3 border-t border-stone-200 pt-5">
            <x-ui.field for="forecast-weeks" :label="__('Antal veckor i snittet')" :hint="__('Mellan 1 och 26. Gäller hela företaget.')" error="weeks">
                <input id="forecast-weeks" type="number" min="1" max="26" wire:model="weeks" @disabled(! $canManage) class="{{ $input }} w-28 tabular-nums">
            </x-ui.field>
            @if($canManage)
                <x-secondary-button type="submit" class="mb-7">{{ __('Spara') }}</x-secondary-button>
            @endif
        </form>
    </x-ui.card>
</div>
