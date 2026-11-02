@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    <x-ui.card :title="__('Avdelningar')" :description="__('Kök, servering, bar, kassa: passen får avdelningens färg i schemat.')">
        @if($canManage && $editing === null)
            <x-slot:cta><x-button type="button" wire:click="createArea">{{ __('Ny avdelning') }}</x-button></x-slot:cta>
        @endif

        @if($editing !== null)
            <form wire:submit="saveArea" class="mb-6 grid gap-5 rounded-2xl bg-stone-50 p-5 sm:grid-cols-2">
                <x-ui.field for="area-name" :label="__('Namn')" error="form.name">
                    <input id="area-name" wire:model="form.name" maxlength="100" class="{{ $input }}" required>
                </x-ui.field>
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">{{ __('Färg') }}</legend>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @foreach($colours as $colour)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model="form.colour" value="{{ $colour }}" class="peer sr-only">
                                <span class="block size-8 rounded-full ring-2 ring-transparent ring-offset-2 peer-checked:ring-ink peer-focus-visible:ring-accent-500" style="background: {{ $colour }}"></span>
                                <span class="sr-only">{{ $colour }}</span>
                            </label>
                        @endforeach
                        <input type="color" wire:model="form.colour" class="h-8 w-12 cursor-pointer rounded border-gray-300" aria-label="{{ __('Egen färg') }}">
                    </div>
                    <x-input-error for="form.colour" class="mt-2" />
                </fieldset>
                @if($locations->isNotEmpty())
                    <x-ui.field for="area-location" :label="__('Lokal i personalliggaren')" :hint="__('Valfritt. Gör att schemat kan filtreras per lokal.')" error="form.location_id">
                        <select id="area-location" wire:model="form.location_id" class="{{ $input }}">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($locations as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="area-sort" :label="__('Ordning')" error="form.sort">
                    <input id="area-sort" type="number" min="0" max="999" wire:model="form.sort" class="{{ $input }} w-24 tabular-nums">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editing', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif

        <ul class="divide-y divide-stone-200">
            @forelse($areas as $area)
                <li wire:key="area-{{ $area->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <span class="flex items-center gap-3">
                        <span class="size-4 rounded-full" style="background: {{ $area->colour }}"></span>
                        <span @class(['font-medium', 'text-ink' => ! $area->archived_at, 'text-gray-500 line-through' => $area->archived_at])>{{ $area->name }}</span>
                        @if($area->location_id && $locations->has($area->location_id))<span class="text-sm text-gray-500">{{ $locations->get($area->location_id) }}</span>@endif
                    </span>
                    @if($canManage)
                        <span class="flex gap-3 text-sm font-semibold">
                            <button type="button" wire:click="editArea({{ $area->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                            <button type="button" wire:click="archiveArea({{ $area->id }})" class="text-gray-700 underline">{{ $area->archived_at ? __('Återställ') : __('Arkivera') }}</button>
                        </span>
                    @endif
                </li>
            @empty
                <li class="py-3"><x-ui.empty-state :title="__('Inga avdelningar ännu')">{{ __('Lägg till de delar av verksamheten ni planerar pass i.') }}</x-ui.empty-state></li>
            @endforelse
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Kompetenser')" :description="__('Det ett pass kan kräva, till exempel kassa, kock eller öppnare. Filtrera schemat på kompetens och se vem som har den.')">
        @if($canManage)
            <form wire:submit="addSkill" class="mb-5 flex flex-wrap items-end gap-3">
                <x-ui.field for="skill-name" :label="__('Ny kompetens')" error="skillName">
                    <input id="skill-name" wire:model="skillName" maxlength="100" class="{{ $input }}" required>
                </x-ui.field>
                <x-button type="submit">{{ __('Lägg till') }}</x-button>
            </form>
        @endif

        @if($skills->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga kompetenser ännu.') }}</p>
        @else
            <ul class="mb-6 flex flex-wrap gap-2">
                @foreach($skills as $skill)
                    <li wire:key="skill-{{ $skill->id }}" class="flex items-center gap-2 rounded-full bg-stone-100 px-3 py-1 text-sm">
                        @if($renaming === $skill->id)
                            <form wire:submit="saveSkillName" class="flex items-center gap-2">
                                <input wire:model="renameTo" maxlength="100" class="w-32 rounded border-gray-300 py-0.5 text-sm" aria-label="{{ __('Nytt namn') }}">
                                <button type="submit" class="font-semibold text-accent-700">{{ __('Spara') }}</button>
                            </form>
                        @else
                            <span class="font-medium text-ink">{{ $skill->name }}</span>
                            @if($canManage)
                                <button type="button" wire:click="renameSkill({{ $skill->id }})" class="text-gray-600 underline">{{ __('Byt namn') }}</button>
                                <button type="button" wire:click="deleteSkill({{ $skill->id }})" wire:confirm="{{ __('Ta bort kompetensen? Den tas bort från personal och pass.') }}" class="text-red-800 underline">{{ __('Ta bort') }}</button>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pe-4 font-semibold">{{ __('Person') }}</th>
                            @foreach($skills as $skill)
                                <th class="px-2 py-2 text-center font-semibold">{{ $skill->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($employees as $employee)
                            <tr wire:key="employee-{{ $employee->id }}">
                                <td class="py-2 pe-4 font-medium text-ink">{{ $employee->first_name }} {{ $employee->last_name }}</td>
                                @foreach($skills as $skill)
                                    <td class="px-2 py-2 text-center">
                                        <input type="checkbox" @checked($employeeSkills->has($employee->id.'|'.$skill->id)) @disabled(! $canManage)
                                            wire:click="toggleSkill({{ $employee->id }}, {{ $skill->id }})"
                                            class="rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                                            aria-label="{{ __(':skill för :name', ['skill' => $skill->name, 'name' => $employee->first_name.' '.$employee->last_name]) }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
