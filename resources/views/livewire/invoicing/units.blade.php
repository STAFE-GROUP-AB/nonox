@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra enhet') : __('Ny enhet')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="unit_code" :label="__('Enhet')" error="code" class="sm:col-span-2" :hint="$editingId ? __('Enheten kan inte döpas om.') : __('Som den skrivs, till exempel rulle eller bunt.')">
                    <input id="unit_code" type="text" class="{{ $input }} mt-1" wire:model="form.code" maxlength="10" @disabled($editingId !== null)>
                </x-ui.field>
                <x-ui.field for="unit_name" :label="__('Namn')" error="name" class="sm:col-span-4">
                    <input id="unit_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="60">
                </x-ui.field>
                <x-ui.field for="unit_dimension" :label="__('Vad enheten mäter')" error="dimension" class="sm:col-span-3" :hint="__('Välj Annat om ingen passar. Hur stor enheten är anges på varje artikel.')">
                    <select id="unit_dimension" class="{{ $input }} mt-1" wire:model="form.dimension" @disabled($editingId !== null)>
                        @foreach($dimensions as $dimension)
                            <option value="{{ $dimension->value }}">{{ $dimension->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="unit_rec20" :label="__('Peppol-kod')" error="rec20_code" class="sm:col-span-3" :hint="__('Koden som följer med på e-fakturor. Utan kod skickas enheten som ett.')">
                    <select id="unit_rec20" class="{{ $input }} mt-1" wire:model="form.rec20_code">
                        <option value="">{{ __('Ingen kod') }}</option>
                        @foreach($rec20 as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canManage && ! $showForm)
            <x-slot:cta>
                <x-button type="button" wire:click="create">{{ __('Ny enhet') }}</x-button>
            </x-slot:cta>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Enhet') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Namn') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Mäter') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Peppol-kod') }}</th>
                        <th scope="col"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($units as $unit)
                        <tr wire:key="unit-{{ $unit->id }}" @class(['text-gray-500' => $unit->isArchived()])>
                            <td class="py-2 pr-4 font-semibold tabular-nums">{{ $unit->code }}</td>
                            <td class="py-2 pr-4">{{ $unit->name }}@if($unit->isArchived()) <span class="text-xs">({{ __('arkiverad') }})</span>@endif</td>
                            <td class="py-2 pr-4">{{ $unit->dimension->label() }}</td>
                            <td class="py-2 pr-4 tabular-nums">{{ $unit->rec20_code ?? '–' }}</td>
                            <td class="py-2 text-right">
                                @if($canManage)
                                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $unit->id }})">{{ __('Ändra') }}</button>
                                    @if($unit->isArchived())
                                        <button type="button" class="ml-3 text-sm font-medium text-gray-700 underline" wire:click="restore({{ $unit->id }})">{{ __('Använd igen') }}</button>
                                    @else
                                        <button type="button" class="ml-3 text-sm font-medium text-gray-700 underline" wire:click="archive({{ $unit->id }})" wire:confirm="{{ __('Arkivera enheten? Artiklar som använder den behåller den, men den föreslås inte längre.') }}">{{ __('Arkivera') }}</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($archivedCount > 0)
            <p class="mt-4 text-sm">
                <button type="button" class="font-medium text-accent-700 underline" wire:click="$toggle('showArchived')">{{ $showArchived ? __('Dölj arkiverade enheter') : __('Visa arkiverade enheter (:count)', ['count' => $archivedCount]) }}</button>
            </p>
        @endif
    </x-ui.card>
</div>
