@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $weekdays = [1 => __('Måndag'), 2 => __('Tisdag'), 3 => __('Onsdag'), 4 => __('Torsdag'), 5 => __('Fredag'), 6 => __('Lördag'), 7 => __('Söndag')];
@endphp
<div class="grid gap-8 lg:grid-cols-3">
    <div class="space-y-4">
        @if($canManage && $editing === null)
            <x-button type="button" wire:click="create">{{ __('Ny mall') }}</x-button>
        @endif

        @if($editing !== null)
            <x-ui.card :title="$editing === 0 ? __('Ny mall') : __('Ändra mallen')">
                <form wire:submit="save" class="space-y-4">
                    <x-ui.field for="template-name" :label="__('Namn')" error="form.name">
                        <input id="template-name" wire:model="form.name" maxlength="100" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-ui.field for="template-weeks" :label="__('Antal veckor')" :hint="__('1–8. Ett rullande schema har flera veckor.')" error="form.weeks">
                        <input id="template-weeks" type="number" min="1" max="8" wire:model="form.weeks" class="{{ $input }} w-24 tabular-nums" required>
                    </x-ui.field>
                    <x-ui.field for="template-notes" :label="__('Anteckningar')" error="form.notes">
                        <textarea id="template-notes" wire:model="form.notes" rows="3" maxlength="2000" class="{{ $input }}"></textarea>
                    </x-ui.field>
                    <div class="flex gap-3">
                        <x-button type="submit">{{ __('Spara') }}</x-button>
                        <x-secondary-button type="button" wire:click="$set('editing', null)">{{ __('Avbryt') }}</x-secondary-button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <ul class="divide-y divide-stone-200 overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
            @forelse($templates as $template)
                <li wire:key="template-{{ $template->id }}">
                    <button type="button" wire:click="select({{ $template->id }})" @class(['flex w-full items-center justify-between gap-3 px-5 py-4 text-left hover:bg-stone-50', 'bg-accent-50/50' => $current?->id === $template->id])>
                        <span class="font-semibold text-ink">{{ $template->name }}</span>
                        <span class="text-sm text-gray-500">{{ trans_choice('{1} 1 vecka|[2,*] :count veckor', $template->weeks, ['count' => $template->weeks]) }} · {{ trans_choice('{0} inga pass|{1} 1 pass|[2,*] :count pass', $template->shifts_count, ['count' => $template->shifts_count]) }}</span>
                    </button>
                </li>
            @empty
                <li class="p-5"><x-ui.empty-state :title="__('Inga mallar ännu')">{{ __('Skapa en mall här, eller spara en vecka i schemat som mall.') }}</x-ui.empty-state></li>
            @endforelse
        </ul>
    </div>

    <div class="space-y-6 lg:col-span-2">
        @if($current)
            <x-ui.card :title="$current->name" :description="$current->notes">
                @if($canManage)
                    <x-slot:action>
                        <span class="flex gap-3 text-sm font-semibold">
                            <button type="button" wire:click="edit({{ $current->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                            <button type="button" wire:click="delete({{ $current->id }})" wire:confirm="{{ __('Ta bort mallen? Pass som redan lagts ut på schemat finns kvar.') }}" class="text-red-800 underline">{{ __('Ta bort') }}</button>
                        </span>
                    </x-slot:action>
                @endif

                @forelse($current->shifts->groupBy('week_index') as $weekIndex => $weekShifts)
                    <h3 class="mt-2 text-sm font-bold text-ink">{{ __('Vecka :number', ['number' => $weekIndex + 1]) }}</h3>
                    <ul class="mt-1 mb-4 divide-y divide-stone-100">
                        @foreach($weekShifts as $row)
                            <li wire:key="template-shift-{{ $row->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                                <span class="w-24 text-gray-600">{{ $weekdays[$row->weekday] }}</span>
                                <span class="w-28 font-semibold tabular-nums text-ink">{{ $row->start }}–{{ $row->end }}</span>
                                <span class="min-w-0 flex-1 truncate text-gray-700">{{ collect([$row->employee_id ? $employees->get($row->employee_id) : __('Öppet pass'), $row->area_id ? $areas->get($row->area_id) : null, $row->skill_id ? $skills->get($row->skill_id) : null])->filter()->join(' · ') }}</span>
                                @if($canManage)
                                    <button type="button" wire:click="removeShift({{ $row->id }})" class="font-semibold text-red-800 underline">{{ __('Ta bort') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="text-sm text-gray-600">{{ __('Mallen har inga pass ännu.') }}</p>
                @endforelse

                @if($canManage)
                    <form wire:submit="addShift" class="mt-4 grid gap-4 rounded-2xl bg-stone-50 p-5 sm:grid-cols-4">
                        @if($current->weeks > 1)
                            <x-ui.field for="ts-week" :label="__('Vecka')" error="shift.week_index">
                                <select id="ts-week" wire:model="shift.week_index" class="{{ $input }}">
                                    @for($week = 0; $week < $current->weeks; $week++)
                                        <option value="{{ $week }}">{{ $week + 1 }}</option>
                                    @endfor
                                </select>
                            </x-ui.field>
                        @endif
                        <x-ui.field for="ts-day" :label="__('Veckodag')" error="shift.weekday">
                            <select id="ts-day" wire:model="shift.weekday" class="{{ $input }}">
                                @foreach($weekdays as $number => $name)
                                    <option value="{{ $number }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="ts-start" :label="__('Starttid')" error="shift.start">
                            <input id="ts-start" type="time" wire:model="shift.start" class="{{ $input }} tabular-nums" required>
                        </x-ui.field>
                        <x-ui.field for="ts-end" :label="__('Sluttid')" error="shift.end">
                            <input id="ts-end" type="time" wire:model="shift.end" class="{{ $input }} tabular-nums" required>
                        </x-ui.field>
                        <x-ui.field for="ts-break" :label="__('Rast (minuter)')" error="shift.break_minutes">
                            <input id="ts-break" type="number" min="0" max="720" step="5" wire:model="shift.break_minutes" class="{{ $input }} tabular-nums">
                        </x-ui.field>
                        <x-ui.field for="ts-person" :label="__('Person')" error="shift.employee_id">
                            <select id="ts-person" wire:model="shift.employee_id" class="{{ $input }}">
                                <option value="">{{ __('Öppet pass') }}</option>
                                @foreach($employees as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="ts-area" :label="__('Avdelning')" error="shift.area_id">
                            <select id="ts-area" wire:model="shift.area_id" class="{{ $input }}">
                                <option value="">{{ __('Ingen') }}</option>
                                @foreach($areas as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($skills->isNotEmpty())
                            <x-ui.field for="ts-skill" :label="__('Kompetens')" error="shift.skill_id">
                                <select id="ts-skill" wire:model="shift.skill_id" class="{{ $input }}">
                                    <option value="">{{ __('Ingen') }}</option>
                                    @foreach($skills as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                        <div class="flex items-end sm:col-span-4">
                            <x-button type="submit">{{ __('Lägg till pass') }}</x-button>
                        </div>
                    </form>
                @endif
            </x-ui.card>

            @if($canManage && $current->shifts->isNotEmpty())
                <x-ui.card :title="__('Lägg ut på schemat')" :description="__('Passen blir utkast. Pass som redan finns läggs inte ut igen.')">
                    <form wire:submit="applyTemplate" class="flex flex-wrap items-end gap-4">
                        <x-ui.field for="apply-week" :label="__('Från veckan som börjar')" error="apply.week">
                            <input id="apply-week" type="date" wire:model="apply.week" class="{{ $input }}" required>
                        </x-ui.field>
                        <x-ui.field for="apply-times" :label="__('Antal gånger i följd')" error="apply.times">
                            <input id="apply-times" type="number" min="1" max="8" wire:model="apply.times" class="{{ $input }} w-24 tabular-nums">
                        </x-ui.field>
                        <label class="flex items-center gap-3 pb-2"><x-checkbox wire:model="apply.people" /> <span>{{ __('Med personerna') }}</span></label>
                        <x-button type="submit">{{ __('Lägg ut') }}</x-button>
                    </form>
                </x-ui.card>
            @endif
        @else
            <x-ui.card>
                <x-ui.empty-state :title="__('Välj en mall')">{{ __('Välj en mall i listan för att se och ändra dess pass.') }}</x-ui.empty-state>
            </x-ui.card>
        @endif
    </div>
</div>
