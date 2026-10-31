@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<div class="space-y-8">
    <x-ui.card :title="__('Föreslå tidrapport')" :description="__('Timmar per dag räknas från avslutade arbetspass för personal som är kopplad till en anställd i lönen. Pass utan utcheckning tas inte med; rätta dem först.')">
        <form wire:submit="propose" class="flex flex-wrap items-end gap-4">
            <x-ui.field for="tp-from" :label="__('Från')"><input id="tp-from" type="date" wire:model="from" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="tp-to" :label="__('Till')"><input id="tp-to" type="date" wire:model="to" class="{{ $input }}"></x-ui.field>
            <x-button type="submit">{{ __('Föreslå') }}</x-button>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Förslag')">
        @if($proposals->isEmpty())
            <x-ui.empty-state :title="__('Inga förslag ännu')">{{ __('Välj en period ovan.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($proposals as $proposal)
                    <li class="flex flex-wrap items-center justify-between gap-4 py-3" wire:key="proposal-{{ $proposal->id }}">
                        <div>
                            <p class="font-semibold text-ink">{{ $proposal->employee?->first_name }} {{ $proposal->employee?->last_name }}</p>
                            <p class="text-sm text-gray-600">{{ __(':from–:to: :hours timmar på :days dagar', ['from' => $proposal->period_start->toDateString(), 'to' => $proposal->period_end->toDateString(), 'hours' => number_format($proposal->totalHours(), 2, ',', ' '), 'days' => count($proposal->days)]) }}</p>
                        </div>
                        @if($proposal->status === 'PROPOSED')
                            <div class="flex gap-3">
                                <x-button type="button" wire:click="approve({{ $proposal->id }})" wire:confirm="{{ __('Godkänn och skriv tidrapporterna till lönen?') }}">{{ __('Godkänn') }}</x-button>
                                <x-secondary-button type="button" wire:click="reject({{ $proposal->id }})">{{ __('Avslå') }}</x-secondary-button>
                            </div>
                        @else
                            <x-ui.badge :tone="$proposal->status === 'APPROVED' ? 'success' : 'neutral'">{{ $proposal->status === 'APPROVED' ? __('Godkänt') : __('Avslaget') }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
