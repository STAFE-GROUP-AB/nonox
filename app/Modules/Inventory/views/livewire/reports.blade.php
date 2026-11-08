@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
    $money = fn (?int $ore) => App\Support\Money\Money::ofOre((int) $ore);
    $tabs = $seesPrices ? ['lagervarde' => __('Lagervärde'), 'rorelser' => __('Rörelser'), 'bokslut' => __('Bokslut')] : ['rorelser' => __('Rörelser')];
@endphp
<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Rapporter') }}">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('report', '{{ $key }}')" @class(['min-h-11 rounded-full px-4 py-2 text-sm font-semibold sm:min-h-0', 'bg-ink text-white' => $report === $key, 'bg-white text-gray-700 ring-1 ring-stone-200 hover:ring-stone-300' => $report !== $key]) @if($report === $key) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if($report === 'lagervarde' && isset($valuation))
        <x-ui.card :title="__('Lagervärde')" :description="__('Antal och värde per artikel och plats på dagen, enligt värderingsmetoden. Lägsta värdets princip används där ett nettoförsäljningsvärde är angivet på artikeln.')">
            <div class="mb-4 flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Dag') }}</span>
                    <input type="date" wire:model.live="date" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Metod') }}</span>
                    <select wire:model.live="method" class="{{ $input }}">
                        @foreach($methods as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @include('inventory::livewire.partials.valuation-table', ['valuation' => $valuation])
        </x-ui.card>
    @endif

    @if($report === 'rorelser' && isset($movements))
        <x-ui.card :title="__('Rörelser')" :description="__('Allt som har kommit in i och gått ut ur lagret. Rörelser ändras aldrig; ett fel rättas med en ny rörelse.')">
            <div class="mb-4 flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Från') }}</span>
                    <input type="date" wire:model.live="from" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Till') }}</span>
                    <input type="date" wire:model.live="to" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Typ') }}</span>
                    <select wire:model.live="type" class="{{ $input }}">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($types as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @if($movements->isEmpty())
                <x-ui.empty-state :title="__('Inga rörelser i perioden')">{{ __('Välj en längre period eller en annan typ.') }}</x-ui.empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Plats') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                                @if($seesPrices)<th class="py-2 pr-4 text-right font-medium">{{ __('Pris/st') }}</th>@endif
                                <th class="py-2 font-medium">{{ __('Av, anteckning') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($movements as $movement)
                                <tr wire:key="report-movement-{{ $movement->id }}">
                                    <td class="py-2 pr-4 tabular-nums whitespace-nowrap">{{ $movement->occurred_on->toDateString() }}</td>
                                    <td class="py-2 pr-4">{{ $movement->type->label() }}</td>
                                    <td class="py-2 pr-4"><span class="tabular-nums">{{ $movement->article?->number }}</span> {{ $movement->article?->name }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $movement->location?->full_code }}</td>
                                    <td @class(['py-2 pr-4 text-right tabular-nums whitespace-nowrap', 'text-accent-700' => $movement->quantity_milli < 0])>{{ $movement->quantity_milli > 0 ? '+' : '' }}{{ $q($movement->quantity_milli) }}</td>
                                    @if($seesPrices)<td class="py-2 pr-4 text-right">@if($movement->unit_cost_ore !== null)<x-ui.amount :money="$money($movement->unit_cost_ore)" />@endif</td>@endif
                                    <td class="py-2 text-gray-600">{{ $movement->creator?->name }} {{ $movement->note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $movements->links() }}</div>
            @endif
        </x-ui.card>
    @endif

    @if($report === 'bokslut' && $seesPrices)
        <x-ui.card :title="__('Varulagret vid bokslut')" :description="__('Underlaget för varulagret på räkenskapsårets sista dag. Inventera först, så att lagret stämmer med hyllorna.')">
            @if(! isset($fiscalYear) || $fiscalYear === null)
                <x-ui.empty-state :title="__('Inget räkenskapsår')">{{ __('Lägg upp ett räkenskapsår under Bokföring.') }}</x-ui.empty-state>
            @else
                <div class="mb-4 max-w-xs">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Räkenskapsår') }}</span>
                        <select wire:model.live="year" class="{{ $input }}">
                            @foreach($years as $option)
                                <option value="{{ $option->id }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                @if($planError)
                    <p class="mb-4 rounded-2xl bg-accent-50 p-4 text-sm text-accent-800" role="alert">{{ $planError }}</p>
                @endif
                <dl class="mb-6 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-stone-50 p-4">
                        <dt class="text-sm text-gray-500">{{ __('Lagervärde :date', ['date' => $fiscalYear->end_date->toDateString()]) }}</dt>
                        <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($plan['value_ore'] ?? $closing->valueOre)" /></dd>
                        <dd class="text-xs text-gray-500">{{ $closing->method->label() }}@if($plan['rule_97'] ?? false), {{ __('97-procentsregeln') }}@endif</dd>
                    </div>
                    <div class="rounded-2xl bg-stone-50 p-4">
                        <dt class="text-sm text-gray-500">{{ __('Bokfört på :account', ['account' => implode(', ', array_column($plan['groups'] ?? [], 'stock_account')) ?: $settings['stock_account']]) }}</dt>
                        <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($bookValue)" /></dd>
                    </div>
                    <div class="rounded-2xl bg-stone-50 p-4">
                        <dt class="text-sm text-gray-500">{{ __('Lagerförändring att bokföra') }}</dt>
                        <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($plan['change_ore'] ?? 0)" /></dd>
                        @foreach($plan['groups'] ?? [] as $group)
                            <dd class="text-xs text-gray-500">{{ __(':stock mot :change', ['stock' => $group['stock_account'], 'change' => $group['change_account']]) }}: {{ $money($group['change_ore'])->format() }}</dd>
                        @endforeach
                    </div>
                </dl>

                @if($canBook)
                    <div class="mb-6 flex flex-wrap items-center gap-4">
                        @if($resultBooked)
                            <p class="text-sm text-gray-700">{{ __('Årets resultat är redan bokat. Lagerförändringen bokförs före resultatet.') }}</p>
                        @elseif($planError === null)
                            <x-button type="button" wire:click="book" wire:confirm="{{ __('Bokför lagerförändringen på årets sista dag? Verifikationen kan bara rättas med en rättelseverifikation.') }}" wire:loading.attr="disabled">{{ __('Spara lagervärdet och bokför förändringen') }}</x-button>
                        @endif
                    </div>
                @endif

                @if($saved->isNotEmpty())
                    <h3 class="mb-2 text-sm font-semibold text-ink">{{ __('Sparade värderingar') }}</h3>
                    <ul class="mb-6 divide-y divide-stone-200 text-sm">
                        @foreach($saved as $entry)
                            <li class="flex flex-wrap justify-between gap-3 py-2" wire:key="valuation-{{ $entry->id }}">
                                <span>{{ $entry->created_at->toDateString() }} · {{ App\Modules\Inventory\Domain\ValuationMethod::tryFrom($entry->method)?->label() }}</span>
                                <span class="tabular-nums"><x-ui.amount :money="$money($entry->value_ore)" /> · {{ __('bokfört :change', ['change' => $money($entry->booked_change_ore)->format()]) }} @if($entry->voucher)· {{ $entry->voucher->reference() }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @include('inventory::livewire.partials.valuation-table', ['valuation' => $closing])
            @endif
        </x-ui.card>
    @endif
</div>
