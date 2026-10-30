@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $labels = ['SICK' => __('Sjuk'), 'VAB' => __('Vård av barn'), 'VACATION' => __('Semester')];
@endphp
<div class="space-y-6">
    <x-ui.card :title="__('Väntar på svar')">
        @if($pending->isEmpty())
            <x-ui.empty-state :title="__('Inga obesvarade ansökningar')">{{ __('Sjukanmälningar och semesteransökningar från Min sida hamnar här.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($pending as $absence)
                    <li class="space-y-3 py-4" wire:key="pending-{{ $absence->id }}">
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900">{{ $absence->employee?->first_name }} {{ $absence->employee?->last_name }} · {{ $labels[$absence->type] ?? $absence->type }}</span>
                                <span class="block text-sm text-gray-600 tabular-nums">{{ $absence->date_from->toDateString() }} – {{ $absence->date_to->toDateString() }}</span>
                                @if($absence->note)<span class="block text-sm text-gray-700">”{{ $absence->note }}”</span>@endif
                            </span>
                            <label class="text-sm text-gray-600">{{ __('Timmar per dag') }}
                                <input type="text" inputmode="decimal" class="{{ $input }} mt-1 w-24" wire:model="hours.{{ $absence->id }}">
                            </label>
                            <x-button type="button" wire:click="approve({{ $absence->id }})" wire:loading.attr="disabled">{{ __('Godkänn') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('rejecting', {{ $absence->id }})">{{ __('Avslå') }}</button>
                        </div>
                        @if($rejecting === $absence->id)
                            <form wire:submit="reject" class="flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3">
                                <x-ui.field for="reject_reason" :label="__('Skäl, som den anställde ser')" error="reason" class="min-w-64 flex-1">
                                    <input id="reject_reason" type="text" class="{{ $input }} mt-1" wire:model="reason">
                                </x-ui.field>
                                <x-button>{{ __('Avslå ansökan') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('rejecting', null)">{{ __('Avbryt') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if($decided->isNotEmpty())
        <x-ui.card :title="__('Senast besvarade')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($decided as $absence)
                    <li class="flex flex-wrap items-center gap-4 py-2">
                        <span class="flex-1">{{ $absence->employee?->first_name }} {{ $absence->employee?->last_name }} · {{ $labels[$absence->type] ?? $absence->type }} · <span class="tabular-nums">{{ $absence->date_from->toDateString() }} – {{ $absence->date_to->toDateString() }}</span></span>
                        <span class="text-gray-600">{{ $absence->status === 'APPROVED' ? __('Godkänd') : __('Avslagen') }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
