@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $chip = 'inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-medium';
    $buckets = [
        ['key' => 'overdue', 'label' => __('Väntat avslut har passerat'), 'hint' => __('Öppna affärer vars datum redan har varit. Flytta datumet eller avsluta affären.')],
        ['key' => 'undated', 'label' => __('Utan väntat avslut'), 'hint' => __('Öppna affärer utan datum räknas inte in i någon månad.')],
        ['key' => 'later', 'label' => __('Senare'), 'hint' => __('Efter den sista månaden i tabellen.')],
    ];
    $previous = $monthStart->subMonthNoOverflow()->format('Y-m');
    $next = $monthStart->addMonthNoOverflow()->format('Y-m');
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Vems affärer') }}">
            <button type="button" wire:click="$set('who', '')" @class([$chip, 'bg-ink text-white' => $who === '', 'bg-white text-ink ring-1 ring-stone-200' => $who !== '']) aria-pressed="{{ $who === '' ? 'true' : 'false' }}">{{ __('Hela företaget') }}</button>
            <button type="button" wire:click="$set('who', 'mina')" @class([$chip, 'bg-ink text-white' => $who === 'mina', 'bg-white text-ink ring-1 ring-stone-200' => $who !== 'mina']) aria-pressed="{{ $who === 'mina' ? 'true' : 'false' }}">{{ __('Mina') }}</button>
        </div>
        <label class="ms-auto flex items-center gap-2 text-gray-600">
            <span>{{ __('Visa') }}</span>
            <select class="min-h-11 rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500" wire:model.live="horizon">
                @foreach(App\Modules\Crm\Livewire\ForecastPage::HORIZONS as $option)
                    <option value="{{ $option }}">{{ __(':months månader framåt', ['months' => $option]) }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <x-ui.card :title="__('Prognos per månad')" :description="__('Viktat värde är affärens värde gånger sannolikheten för steget den står i. Vunnet är det som faktiskt har vunnits den månaden. Alla belopp utan moms.')">
        <div class="-mx-6 overflow-x-auto sm:mx-0">
            <table class="min-w-full text-sm" data-testid="crm-forecast">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th scope="col" class="px-6 py-2 font-medium sm:px-0">{{ __('Månad') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ __('Viktat') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ __('Fullt värde') }}</th>
                        <th scope="col" class="px-6 py-2 text-right font-medium sm:px-0">{{ __('Vunnet') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($forecast['months'] as $row)
                        @php $isPast = $row['month'] < $currentMonth; @endphp
                        <tr wire:key="m-{{ $row['month'] }}" @class(['font-semibold' => $row['month'] === $currentMonth])>
                            <th scope="row" class="px-6 py-2 text-left font-medium text-gray-900 sm:px-0">{{ ucfirst(\Carbon\CarbonImmutable::parse($row['month'].'-01')->translatedFormat('F Y')) }}</th>
                            <td class="px-3 py-2 text-right">@if($isPast)<span class="text-gray-400">–</span>@else<x-ui.amount :money="$money($row['weighted'])" />@endif</td>
                            <td class="px-3 py-2 text-right text-gray-600">@if($isPast)<span class="text-gray-400">–</span>@else<x-ui.amount :money="$money($row['value'])" /> <span class="text-xs">({{ $row['count'] }})</span>@endif</td>
                            <td class="px-6 py-2 text-right sm:px-0">@if($row['month'] > $currentMonth)<span class="text-gray-400">–</span>@else<x-ui.amount :money="$money($row['won'])" /> <span class="text-xs text-gray-500">({{ $row['won_count'] }})</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <dl class="mt-6 grid gap-4 sm:grid-cols-3">
            @foreach($buckets as $bucket)
                @php $sum = $forecast[$bucket['key']]; @endphp
                <div @class(['rounded-2xl p-4', 'bg-accent-50 ring-1 ring-accent-200' => $bucket['key'] === 'overdue' && $sum['count'] > 0, 'bg-stone-50' => ! ($bucket['key'] === 'overdue' && $sum['count'] > 0)])>
                    <dt class="text-sm font-medium text-ink">{{ $bucket['label'] }} ({{ $sum['count'] }})</dt>
                    <dd class="mt-1 text-sm text-gray-700">{{ __('Viktat') }} <x-ui.amount :money="$money($sum['weighted'])" class="font-semibold" /> · {{ __('fullt') }} <x-ui.amount :money="$money($sum['value'])" /></dd>
                    <dd class="mt-1 text-xs text-gray-500">{{ $bucket['hint'] }}</dd>
                </div>
            @endforeach
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Mål för :month', ['month' => $monthStart->translatedFormat('F Y')])" :description="$canSet ? __('Möten räknas från klara möten, offerter från offerterna i fakturering och ordervärde från vunna affärer. Ingen behöver fylla i något.') : __('Dina mål för månaden, mätta direkt ur CRM och faktureringen.')">
        <x-slot:action>
            <span class="flex gap-3 text-sm">
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="$set('month', '{{ $previous }}')">{{ __('Föregående') }}</button>
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="$set('month', '{{ $next }}')">{{ __('Nästa') }}</button>
            </span>
        </x-slot:action>
        @if($goals === [])
            <p class="text-sm text-gray-600">{{ $canSet ? __('Inga mål för månaden ännu.') : __('Du har inga mål för månaden.') }}</p>
        @else
            @include('crm::livewire.partials.goal-progress', ['goals' => $goals, 'showNames' => $canSet])
        @endif

        @if($canSet)
            <form wire:submit="saveGoal" class="mt-6 grid gap-4 border-t border-gray-100 pt-6 sm:grid-cols-4">
                <x-ui.field for="goal_user" :label="__('Säljare')" error="user_id">
                    <select id="goal_user" class="{{ $input }} mt-1" wire:model="goal.user_id">
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="goal_metric" :label="__('Mål för')" error="metric">
                    <select id="goal_metric" class="{{ $input }} mt-1" wire:model.live="goal.metric">
                        @foreach($metrics as $metric)
                            <option value="{{ $metric->value }}">{{ $metric->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="goal_target" :label="($goal['metric'] ?? '') === 'ORDER_VALUE' ? __('Mål i kronor') : __('Antal')" error="target" :hint="__('Lämna tomt för att ta bort målet.')">
                    <input id="goal_target" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="goal.target">
                </x-ui.field>
                <div class="flex items-end">
                    <x-button wire:loading.attr="disabled" class="min-h-11">{{ __('Spara mål') }}</x-button>
                </div>
            </form>

            @if($goalRows->isNotEmpty())
                <ul class="mt-4 divide-y divide-gray-100 text-sm">
                    @foreach($goalRows as $row)
                        <li wire:key="goal-row-{{ $row->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <span>{{ $row->user?->name }} · {{ $row->metric->label() }}: <span class="font-medium tabular-nums">{{ $row->metric->isMoney() ? $money($row->target)->format() : $row->target }}</span></span>
                            <button type="button" class="min-h-11 text-gray-700 underline sm:min-h-0" wire:click="removeGoal({{ $row->id }})" wire:confirm="{{ __('Ta bort målet?') }}">{{ __('Ta bort') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-ui.card>
</div>
