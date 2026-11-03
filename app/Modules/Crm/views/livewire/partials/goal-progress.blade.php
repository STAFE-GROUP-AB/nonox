{{-- Goal progress rows (GoalProgress): one row per seller and metric, with a bar. --}}
@php $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore); @endphp
<ul class="space-y-4" data-testid="crm-goals">
    @foreach($goals as $progress)
        @php
            $share = $progress['target'] > 0 ? min(1, $progress['actual'] / $progress['target']) : 1;
            $reached = $progress['actual'] >= $progress['target'];
        @endphp
        <li wire:key="goal-{{ $progress['user_id'] }}-{{ $progress['metric']->value }}">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                <span class="font-medium text-gray-900">{{ $progress['metric']->label() }}@if($showNames) · {{ $progress['name'] }}@endif</span>
                <span class="tabular-nums text-gray-700">
                    @if($progress['metric']->isMoney())
                        <x-ui.amount :money="$money($progress['actual'])" /> {{ __('av') }} <x-ui.amount :money="$money($progress['target'])" />
                    @else
                        {{ __(':actual av :target', ['actual' => $progress['actual'], 'target' => $progress['target']]) }}
                    @endif
                </span>
            </span>
            <span class="mt-1.5 block h-2.5 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($share * 100) }}" aria-label="{{ $progress['metric']->label() }}">
                <span @class(['block h-full rounded-full', 'bg-green-600' => $reached, 'bg-accent-500' => ! $reached]) style="width: {{ round($share * 100, 1) }}%"></span>
            </span>
            @if($progress['invoiced'] !== null)
                <span class="mt-1 block text-xs text-gray-500">{{ __('Fakturerat på kopplade affärer') }}: <x-ui.amount :money="$money($progress['invoiced'])" /></span>
            @endif
        </li>
    @endforeach
</ul>
