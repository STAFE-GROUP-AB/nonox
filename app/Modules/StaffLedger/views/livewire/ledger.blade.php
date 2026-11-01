@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<div class="space-y-8">
    <x-ui.card>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="l-from" :label="__('Från')"><input id="l-from" type="date" wire:model.live="from" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="l-to" :label="__('Till')"><input id="l-to" type="date" wire:model.live="to" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="l-location" :label="__('Lokal')">
                <select id="l-location" wire:model.live="location" class="{{ $input }}">
                    <option value="">{{ __('Alla lokaler') }}</option>
                    @foreach($locations as $place)
                        <option value="{{ $place->id }}">{{ $place->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="l-search" :label="__('Sök namn')"><input id="l-search" type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}"></x-ui.field>
        </div>
        @if($canManage)
            <div class="mt-5"><x-secondary-button type="button" wire:click="openManual">{{ __('Registrera manuellt') }}</x-secondary-button></div>
        @endif
    </x-ui.card>

    @if($manual)
        <x-ui.card :title="__('Manuell registrering')" :description="__('Till exempel en glömd utcheckning. Registreringen visas alltid som manuell, med ditt namn och orsaken.')">
            <form wire:submit="saveManual" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="m-person" :label="__('Person')">
                    <select id="m-person" wire:model="manualForm.person_id" class="{{ $input }}" required>
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($people as $person)
                            <option value="{{ $person->id }}">{{ $person->first_name }} {{ $person->last_name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="m-location" :label="__('Lokal')">
                    <select id="m-location" wire:model="manualForm.location_id" class="{{ $input }}" required>
                        @foreach($locations as $place)
                            <option value="{{ $place->id }}">{{ $place->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="m-direction" :label="__('In eller ut')">
                    <select id="m-direction" wire:model="manualForm.direction" class="{{ $input }}">
                        <option value="IN">{{ __('Incheckning') }}</option>
                        <option value="OUT">{{ __('Utcheckning') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="m-at" :label="__('Tid')"><input id="m-at" type="datetime-local" wire:model="manualForm.at" class="{{ $input }}" required></x-ui.field>
                <x-ui.field for="m-reason" :label="__('Orsak')" class="sm:col-span-2"><input id="m-reason" wire:model="manualForm.reason" class="{{ $input }}" required placeholder="{{ __('Glömde checka ut vid stängning') }}"></x-ui.field>
                <div class="flex gap-3 sm:col-span-2">
                    <x-button type="submit" wire:confirm="{{ __('Registreringen sparas i liggaren och kan inte tas bort, bara rättas. Fortsätta?') }}">{{ __('Spara i liggaren') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('manual', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($correcting)
        <x-ui.card :title="__('Rätta post :id', ['id' => $correcting])" :description="__('Rättelsen blir en ny post. Den ursprungliga ändras aldrig och visas alltid.')">
            <form wire:submit="saveCorrection" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="c-kind" :label="__('Vad är fel?')">
                    <select id="c-kind" wire:model.live="correctionForm.kind" class="{{ $input }}">
                        <option value="TIME">{{ __('Tiden är fel') }}</option>
                        <option value="VOID">{{ __('Registreringen skulle inte ha gjorts (fel person, dubbeltryck)') }}</option>
                    </select>
                </x-ui.field>
                @if($correctionForm['kind'] === 'TIME')
                    <x-ui.field for="c-at" :label="__('Rätt tid')"><input id="c-at" type="datetime-local" wire:model="correctionForm.at" class="{{ $input }}" required></x-ui.field>
                @endif
                <x-ui.field for="c-reason" :label="__('Orsak')" class="sm:col-span-2"><input id="c-reason" wire:model="correctionForm.reason" class="{{ $input }}" required></x-ui.field>
                <div class="flex gap-3 sm:col-span-2">
                    <x-button type="submit" wire:confirm="{{ __('Rättelsen sparas i liggaren och kan inte tas bort. Fortsätta?') }}">{{ __('Spara rättelsen') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('correcting', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($entries->isEmpty())
            <x-ui.empty-state :title="__('Inga registreringar')">{{ __('Inga in- eller utcheckningar under perioden.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-gray-600">
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Tid') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Händelse') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Namn') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">
                                {{ __('Person- eller samordningsnummer') }}
                                @if($canReveal)
                                    <button type="button" wire:click="{{ $sensitive ? 'hidePersonalNumbers' : 'revealPersonalNumbers' }}" class="ms-2 text-accent-700 underline">{{ $sensitive ? __('Dölj') : __('Visa') }}</button>
                                @endif
                            </th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Lokal') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Sätt') }}</th>
                            <th class="py-2 text-start font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($entries as $entry)
                            @php($voided = ! array_key_exists($entry->id, $times))
                            <tr wire:key="entry-{{ $entry->id }}" @class(['text-gray-400 line-through' => $voided])>
                                <td class="py-2 pe-4 tabular-nums">{{ $entry->occurred_at->format('Y-m-d H:i') }}</td>
                                <td class="py-2 pe-4">{{ $entry->effectiveDirection()?->label() }}@if($entry->type->value === 'MANUAL') · {{ __('manuell') }}@endif</td>
                                <td class="py-2 pe-4 font-medium">{{ $entry->person_name }}</td>
                                <td class="py-2 pe-4 tabular-nums">{{ $sensitive ? $entry->formattedPersonalNumber() : $entry->maskedPersonalNumber() }}</td>
                                <td class="py-2 pe-4">{{ $entry->location?->name }}</td>
                                <td class="py-2 pe-4">
                                    {{ $entry->method->label() }}
                                    @foreach($entry->flags as $flag)
                                        <x-ui.badge class="ms-1">{{ App\Modules\StaffLedger\Domain\EntryFlag::from($flag)->label() }}</x-ui.badge>
                                    @endforeach
                                    @if($entry->reason)<span class="block text-xs text-gray-600 no-underline">{{ __('Orsak: :reason (:name)', ['reason' => $entry->reason, 'name' => $entry->user?->name]) }}</span>@endif
                                </td>
                                <td class="py-2">
                                    @if($canManage && ! $voided)
                                        <button type="button" wire:click="openCorrection({{ $entry->id }})" class="font-semibold text-accent-700 underline">{{ __('Rätta') }}</button>
                                    @endif
                                </td>
                            </tr>
                            @foreach($corrections->get($entry->id, collect()) as $correction)
                                <tr wire:key="correction-{{ $correction->id }}" class="bg-accent-50/50">
                                    <td class="py-2 pe-4 ps-4 tabular-nums">{{ $correction->correction?->value === 'TIME' ? $correction->occurred_at->format('Y-m-d H:i') : '' }}</td>
                                    <td class="py-2 pe-4" colspan="5">
                                        {{ __('Rättelse (post :id): :kind. Orsak: :reason. Av :name :time.', ['id' => $correction->id, 'kind' => $correction->correction?->label(), 'reason' => $correction->reason, 'name' => $correction->user?->name, 'time' => $correction->received_at->format('Y-m-d H:i')]) }}
                                    </td>
                                    <td></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
