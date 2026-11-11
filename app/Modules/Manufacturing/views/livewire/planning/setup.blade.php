@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
@php($weekdays = [1 => __('Måndag'), 2 => __('Tisdag'), 3 => __('Onsdag'), 4 => __('Torsdag'), 5 => __('Fredag'), 6 => __('Lördag'), 7 => __('Söndag')])
@php($skillNames = $skills->pluck('name', 'id'))
<div class="space-y-4">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Inställningar för planering') }}">
        @foreach(['stationer' => __('Arbetsstationer'), 'personal' => __('Personer och kompetenser'), 'arbetstid' => __('Arbetstid och horisont')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-stone-200 text-ink' => $tab === $key, 'text-gray-600 hover:text-gray-900' => $tab !== $key]) aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if($tab === 'stationer')
        @if($editingStation)
            <x-ui.card :title="$stationId ? __('Ändra arbetsstation') : __('Ny arbetsstation')" :description="__('Ett arbetssteg som en arbetsstation utför planeras bara när både en person och stationen är lediga. Stationen är öppen när någon arbetar vid den, utom när den står still.')">
                <form wire:submit="saveStation" class="grid gap-4 sm:grid-cols-6">
                    <x-ui.field for="station-name" :label="__('Namn')" error="station.name" class="sm:col-span-3">
                        <input id="station-name" wire:model="station.name" maxlength="100" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-ui.field for="station-code" :label="__('Kod')" error="station.code" class="sm:col-span-3" :hint="__('Frivilligt, till exempel LB1.')">
                        <input id="station-code" wire:model="station.code" maxlength="20" class="{{ $input }}">
                    </x-ui.field>
                    <fieldset class="sm:col-span-6">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Arbetssteg som utförs här') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-4">
                            @foreach($operations as $operation)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $operation->id }}" wire:model="station.operation_ids" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500"> {{ $operation->name }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="flex gap-3 sm:col-span-6">
                        <x-button type="submit">{{ __('Spara arbetsstationen') }}</x-button>
                        <x-secondary-button type="button" wire:click="$set('editingStation', false)">{{ __('Avbryt') }}</x-secondary-button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Arbetsstationer')">
            @if($canManage && ! $editingStation)
                <x-slot:cta><x-button type="button" wire:click="newStation">{{ __('Ny arbetsstation') }}</x-button></x-slot:cta>
            @endif
            @if($workstations->isEmpty())
                <x-ui.empty-state :title="__('Inga arbetsstationer ännu')">{{ __('Lägg upp det som bara finns en av och som arbetet måste turas om vid, till exempel en lackbox, en kantlistmaskin eller en CNC.') }}</x-ui.empty-state>
            @else
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach($workstations as $workstation)
                        <li wire:key="station-{{ $workstation->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>
                                <span class="font-semibold text-ink">{{ $workstation->name }}</span>
                                @if($workstation->code)<span class="text-gray-500">({{ $workstation->code }})</span>@endif
                                · {{ $workstation->operations->pluck('name')->join(', ') ?: __('inga arbetssteg') }}
                                @if($workstation->archived_at)<x-ui.badge>{{ __('Arkiverad') }}</x-ui.badge>@endif
                            </span>
                            @if($canManage)
                                <span class="space-x-3">
                                    <button type="button" wire:click="editStation({{ $workstation->id }})" class="font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                                    <button type="button" wire:click="archiveStation({{ $workstation->id }}, {{ $workstation->archived_at ? 'false' : 'true' }})" class="underline">{{ $workstation->archived_at ? __('Använd igen') : __('Arkivera') }}</button>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if($canPlan && $workstations->whereNull('archived_at')->isNotEmpty())
            <x-ui.card :title="__('Stopp i arbetsstation')" :description="__('Ett haveri eller underhåll. Planen räknas om och avvikelsen visar vilka leveranser som flyttas.')">
                <form wire:submit="saveDowntime" class="grid gap-4 sm:grid-cols-8">
                    <x-ui.field for="down-station" :label="__('Arbetsstation')" error="downtime.workstation_id" class="sm:col-span-2">
                        <select id="down-station" wire:model="downtime.workstation_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj') }}</option>
                            @foreach($workstations->whereNull('archived_at') as $workstation)
                                <option value="{{ $workstation->id }}">{{ $workstation->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="down-start" :label="__('Från')" error="downtime.starts_at" class="sm:col-span-2">
                        <input id="down-start" type="datetime-local" wire:model="downtime.starts_at" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-ui.field for="down-end" :label="__('Väntas klar')" error="downtime.ends_at" class="sm:col-span-2" :hint="__('Tomt betyder tills vidare.')">
                        <input id="down-end" type="datetime-local" wire:model="downtime.ends_at" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="down-reason" :label="__('Orsak')" class="sm:col-span-2">
                        <select id="down-reason" wire:model="downtime.reason" class="{{ $input }}">
                            @foreach($reasons as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <div class="sm:col-span-8"><x-button type="submit">{{ __('Registrera stopp') }}</x-button></div>
                </form>
                @if($downtimes->isNotEmpty())
                    <ul class="mt-4 divide-y divide-stone-100 text-sm">
                        @foreach($downtimes as $down)
                            <li wire:key="down-{{ $down->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>{{ $down->workstation->name }}: {{ $down->reason->label() }}, {{ $down->starts_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') }} – {{ $down->ends_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') ?? __('tills vidare') }}</span>
                                <button type="button" wire:click="endDowntime({{ $down->id }})" class="font-semibold text-accent-700 underline">{{ __('I drift igen nu') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif
    @elseif($tab === 'personal')
        <x-ui.card :title="__('Kompetenser')" :description="__('Ett arbetssteg kan kräva en kompetens; då planeras det bara på personer som har den. Välj kompetens per arbetssteg under Arbetssteg.')">
            @if($canManage)
                <form wire:submit="saveSkill" class="mb-4 flex flex-wrap items-end gap-3">
                    <x-ui.field for="skill-name" :label="$skillId ? __('Byt namn på kompetens') : __('Ny kompetens')" error="skill.name">
                        <input id="skill-name" wire:model="skillName" maxlength="100" class="{{ $input }} w-64" placeholder="{{ __('Till exempel Lackering') }}">
                    </x-ui.field>
                    <x-secondary-button type="submit">{{ __('Spara') }}</x-secondary-button>
                </form>
            @endif
            <ul class="flex flex-wrap gap-2 text-sm">
                @forelse($skills as $skill)
                    <li wire:key="skill-{{ $skill->id }}" class="rounded-full bg-stone-100 px-3 py-1">
                        {{ $skill->name }} <span class="text-gray-500">({{ $skill->employees_count }})</span>
                        @if($canManage)
                            <button type="button" wire:click="editSkill({{ $skill->id }})" class="ml-1 underline">{{ __('Ändra') }}</button>
                            <button type="button" wire:click="deleteSkill({{ $skill->id }})" wire:confirm="{{ __('Ta bort kompetensen? Arbetssteg som krävde den kan då planeras på vem som helst.') }}" class="ml-1 underline">{{ __('Ta bort') }}</button>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-600">{{ __('Inga kompetenser ännu.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Personer i verkstaden')" :description="$planningOn ? __('Planering är påslaget: arbetstiden hämtas från publicerade pass. Här väljer du kompetenser.') : __('En person utan egen arbetsvecka följer företagets arbetstider. En person utan kompetenser och utan arbetsvecka planeras inte.')">
            @if($employees->isEmpty())
                <x-ui.empty-state :title="__('Inga anställda ännu')">{{ __('Personerna i verkstaden är företagets anställda. Lägg upp dem i Lön, sedan väljer du deras kompetenser här.') }}</x-ui.empty-state>
            @else
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach($employees as $employee)
                        <li wire:key="person-{{ $employee->id }}" class="py-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    <span class="font-semibold text-ink">{{ trim($employee->first_name.' '.$employee->last_name) }}</span>
                                    · {{ $employeeSkills->get($employee->id, collect())->map(fn ($row) => $skillNames->get($row->skill_id))->filter()->join(', ') ?: __('inga kompetenser') }}
                                    @if(! $planningOn) · {{ in_array($employee->id, $ownWeek) ? __('egen arbetsvecka') : __('företagets arbetstider') }}@endif
                                </span>
                                @if($canManage && $personId !== $employee->id)
                                    <button type="button" wire:click="editPerson({{ $employee->id }})" class="font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                                @endif
                            </div>
                            @if($personId === $employee->id)
                                <form wire:submit="savePerson" class="mt-3 space-y-4 rounded-lg bg-stone-50 p-3">
                                    <fieldset>
                                        <legend class="text-sm font-medium text-gray-700">{{ __('Kompetenser') }}</legend>
                                        <div class="mt-2 flex flex-wrap gap-4">
                                            @foreach($skills as $skill)
                                                <label class="flex items-center gap-2"><input type="checkbox" value="{{ $skill->id }}" wire:model="person.skill_ids" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500"> {{ $skill->name }}</label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                    @if(! $planningOn)
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="person.own_week" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500"> {{ __('Egen arbetsvecka, i stället för företagets arbetstider') }}</label>
                                        @if($person['own_week'])
                                            <x-input-error for="person.days" />
                                            <div class="overflow-x-auto">
                                                <table class="text-sm">
                                                    <thead><tr class="text-left text-gray-500"><th class="pr-3 font-medium">{{ __('Dag') }}</th><th class="pr-3 font-medium">{{ __('Start') }}</th><th class="pr-3 font-medium">{{ __('Slut') }}</th><th class="pr-3 font-medium">{{ __('Rast från') }}</th><th class="font-medium">{{ __('Rast, minuter') }}</th></tr></thead>
                                                    <tbody>
                                                        @foreach($weekdays as $weekday => $label)
                                                            <tr>
                                                                <td class="pr-3">{{ $label }}</td>
                                                                <td class="pr-3"><input type="time" aria-label="{{ __('Start :day', ['day' => $label]) }}" wire:model="person.days.{{ $weekday }}.start" class="{{ $input }}"></td>
                                                                <td class="pr-3"><input type="time" aria-label="{{ __('Slut :day', ['day' => $label]) }}" wire:model="person.days.{{ $weekday }}.end" class="{{ $input }}"></td>
                                                                <td class="pr-3"><input type="time" aria-label="{{ __('Rast från :day', ['day' => $label]) }}" wire:model="person.days.{{ $weekday }}.break_start" class="{{ $input }}"></td>
                                                                <td><input inputmode="numeric" aria-label="{{ __('Rast, minuter :day', ['day' => $label]) }}" wire:model="person.days.{{ $weekday }}.break_minutes" class="{{ $input }} w-20 text-right"></td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            <p class="text-xs text-gray-500">{{ __('Lämna starttiden tom på en ledig dag. Helgdagar räknas bort av sig själva.') }}</p>
                                        @endif
                                    @endif
                                    <div class="flex gap-3">
                                        <x-button type="submit">{{ __('Spara') }}</x-button>
                                        <x-secondary-button type="button" wire:click="cancelPerson">{{ __('Avbryt') }}</x-secondary-button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @else
        <x-ui.card :title="__('Arbetstid och horisont')" :description="__('Företagets arbetstider gäller personer utan egen arbetsvecka, och avgör vilka dagar som är leveransdagar.')">
            <form wire:submit="saveSettings" class="grid gap-4 sm:grid-cols-6">
                <fieldset class="sm:col-span-6">
                    <legend class="text-sm font-medium text-gray-700">{{ __('Arbetsdagar') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-4">
                        @foreach($weekdays as $weekday => $label)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $weekday }}" wire:model="settings.working_days" @disabled(! $canManage) class="rounded-sm border-stone-300 text-ink focus:ring-accent-500"> {{ $label }}</label>
                        @endforeach
                    </div>
                    <x-input-error for="settings.working_days" />
                </fieldset>
                <x-ui.field for="set-start" :label="__('Dagen börjar')" error="settings.day_start" class="sm:col-span-2"><input id="set-start" type="time" wire:model="settings.day_start" @disabled(! $canManage) class="{{ $input }}"></x-ui.field>
                <x-ui.field for="set-end" :label="__('Dagen slutar')" class="sm:col-span-2"><input id="set-end" type="time" wire:model="settings.day_end" @disabled(! $canManage) class="{{ $input }}"></x-ui.field>
                <x-ui.field for="set-break" :label="__('Rast från')" class="sm:col-span-1"><input id="set-break" type="time" wire:model="settings.break_start" @disabled(! $canManage) class="{{ $input }}"></x-ui.field>
                <x-ui.field for="set-break-min" :label="__('Rast, minuter')" error="settings.break_minutes" class="sm:col-span-1"><input id="set-break-min" inputmode="numeric" wire:model="settings.break_minutes" @disabled(! $canManage) class="{{ $input }} text-right"></x-ui.field>
                <x-ui.field for="set-horizon" :label="__('Horisont, veckor')" error="settings.horizon_weeks" class="sm:col-span-2" :hint="__('Hur långt fram planen räknar, högst 52 veckor.')"><input id="set-horizon" inputmode="numeric" wire:model="settings.horizon_weeks" @disabled(! $canManage) class="{{ $input }} text-right"></x-ui.field>
                <x-ui.field for="set-buffer" :label="__('Leveransbuffert, arbetsdagar')" error="settings.buffer_days" class="sm:col-span-2" :hint="__('Läggs till efter att arbetet är klart, till exempel för packning och frakt.')"><input id="set-buffer" inputmode="numeric" wire:model="settings.buffer_days" @disabled(! $canManage) class="{{ $input }} text-right"></x-ui.field>
                <x-ui.field for="set-frozen" :label="__('Fryst fönster, arbetsdagar')" error="settings.frozen_days" class="sm:col-span-2" :hint="__('Arbete som redan ligger inom fönstret flyttas inte när planen räknas om.')"><input id="set-frozen" inputmode="numeric" wire:model="settings.frozen_days" @disabled(! $canManage) class="{{ $input }} text-right"></x-ui.field>
                <x-ui.field for="set-lead" :label="__('Ledtid för material, dagar')" error="settings.lead_time_days" class="sm:col-span-2" :hint="__('För material som inte är beställt, när leverantören saknar historik. Annars används leverantörens mediantid från beställning till leverans.')"><input id="set-lead" inputmode="numeric" wire:model="settings.lead_time_days" @disabled(! $canManage) class="{{ $input }} text-right"></x-ui.field>
                @if($canManage)
                    <div class="sm:col-span-6"><x-button type="submit">{{ __('Spara') }}</x-button></div>
                @endif
            </form>
        </x-ui.card>
    @endif
</div>
