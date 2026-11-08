<div class="space-y-3">
    @forelse($workOrders as $workOrder)
        <a href="{{ route('workOrders.field', $workOrder->id) }}" class="block rounded-xl border border-stone-200 bg-white p-4 shadow-xs active:bg-stone-50" wire:key="mine-{{ $workOrder->id }}">
            <p class="text-lg font-semibold text-gray-900">{{ $workOrder->title }}</p>
            <p class="text-sm text-gray-600">{{ __('Arbetsorder :number', ['number' => $workOrder->number]) }} · {{ $workOrder->customer?->name }}</p>
            @if($workOrder->site_address_line1)
                <p class="text-sm text-gray-600">{{ $workOrder->site_address_line1 }}@if($workOrder->site_city), {{ $workOrder->site_city }}@endif</p>
            @endif
            <p class="mt-2 text-sm font-medium text-accent-700">{{ isset($today[$workOrder->id]) ? __('Idag: :hours tim', ['hours' => rtrim(rtrim(number_format(((int) $today[$workOrder->id]) / 60, 2, ',', ''), '0'), ',')]) : __('Ingen tid idag') }}</p>
        </a>
    @empty
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga jobb just nu')">{{ __('När kontoret tilldelar dig en planerad eller pågående arbetsorder syns den här.') }}</x-ui.empty-state>
        </x-ui.card>
    @endforelse
</div>
