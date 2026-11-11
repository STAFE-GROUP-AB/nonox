{{-- The run the screen shows, polled every 15 seconds while visible (ADR-071 §13.4). --}}
<div wire:poll.15s.visible="checkRun" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white px-4 py-3 text-sm">
    <p class="text-gray-600">
        @if($run)
            {{ __('Beräknad :time (:trigger). Uppdateras av sig själv när något ändras.', ['time' => $run->created_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'), 'trigger' => mb_strtolower($run->trigger->label())]) }}
        @else
            {{ __('Ingen plan är beräknad ännu.') }}
        @endif
    </p>
    @if($canPlan)
        <x-secondary-button type="button" wire:click="recompute" wire:loading.attr="disabled">{{ __('Beräkna nu') }}</x-secondary-button>
    @endif
</div>
