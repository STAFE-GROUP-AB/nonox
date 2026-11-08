{{-- The person's own trips (ADR-072), with change, remove and correct where allowed. --}}
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
@if($trips->isEmpty())
    <p class="mt-3 text-sm text-gray-600">{{ $empty }}</p>
@else
    <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-100">
        @foreach($trips as $item)
            <li class="py-3 text-sm" wire:key="trip-{{ $item->id }}">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="min-w-0 flex-1 basis-48">
                        <span class="block font-medium text-gray-900">{{ $item->trip_date->toDateString() }} · {{ $item->kind->label() }} · <span class="tabular-nums">{{ $item->distanceLabel() }}</span></span>
                        <span class="block text-gray-600">{{ $item->vehicle->displayName() }}@if($item->route() !== '') · {{ $item->route() }}@endif @if($item->purpose) · {{ $item->purpose }}@endif</span>
                        @if($item->corrects_trip_id)<span class="block text-accent-800">{{ __('Rättelse: :reason', ['reason' => $item->correction_reason]) }}</span>@endif
                    </span>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">{{ App\Livewire\Concerns\LogsTrips::statusLabel($item) }}</span>
                    @if(App\Livewire\Concerns\LogsTrips::tripEditable($item))
                        <button type="button" class="text-gray-600 underline" wire:click="editTrip('{{ $item->id }}')">{{ __('Ändra') }}</button>
                        <button type="button" class="text-gray-600 underline" wire:click="deleteTrip('{{ $item->id }}')" wire:confirm="{{ __('Ta bort resan? Det går inte att ångra.') }}">{{ __('Ta bort') }}</button>
                    @elseif($item->status->isSettled() && ! $item->corrects_trip_id)
                        <button type="button" class="text-gray-600 underline" wire:click="startCorrection('{{ $item->id }}')">{{ __('Rätta') }}</button>
                    @endif
                </div>
                @if($correcting === $item->id)
                    <form wire:submit="correctTrip" class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3">
                        <x-ui.field for="correction_reason" :label="__('Varför ska resan rättas?')" error="reason" class="min-w-64 flex-1">
                            <input id="correction_reason" type="text" class="{{ $input }} mt-1" wire:model="correctionReason">
                        </x-ui.field>
                        <x-button wire:confirm="{{ __('Rätta resan? En rättelse med lika lång sträcka dras av nästa gång. Den betalda resan ändras inte.') }}">{{ __('Rätta resan') }}</x-button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
@endif
