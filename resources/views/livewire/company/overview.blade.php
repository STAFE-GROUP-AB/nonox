@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
@endphp
<div class="space-y-6">
    @include('livewire.company.overview-tabs')
    <div class="flex flex-wrap items-end gap-4">
        <x-ui.field for="company_search" :label="__('Sök företag')" class="min-w-64 flex-1">
            <input id="company_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.300ms="search" placeholder="{{ __('Namn eller organisationsnummer') }}">
        </x-ui.field>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="showAll">
            {{ __('Visa även företag där inget väntar') }}
        </label>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="mine">
            {{ __('Bara de jag är ansvarig för') }}
        </label>
    </div>

    <p class="text-sm text-gray-600">{{ __(':waiting av :total företag har något som väntar.', ['waiting' => $waiting, 'total' => $total]) }}</p>

    @if($rows === [])
        <x-ui.card>
            <x-ui.empty-state :title="$search === '' ? __('Inget väntar') : __('Inget företag matchar sökningen')">
                {{ $search === '' ? __('Alla dina företag är à jour. Kryssa i rutan ovan för att se dem ändå.') : __('Sök på en del av namnet eller organisationsnumret.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <ul class="space-y-3">
            @foreach($rows as $row)
                @php($company = $row['company'])
                <li wire:key="company-{{ $company->id }}">
                    <x-ui.card>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">
                                    {{ $company->name }}
                                    @if($company->id === $currentId)
                                        <x-ui.badge tone="accent" class="ms-2">{{ __('Du arbetar här nu') }}</x-ui.badge>
                                    @endif
                                </p>
                                <p class="text-sm text-gray-600 tabular-nums">
                                    {{ $company->org_number }} · {{ $roleLabels[$row['role']] ?? $row['role'] }}
                                    @if($row['last_posted']) · {{ __('Senast bokfört :date', ['date' => $row['last_posted']]) }}@endif
                                </p>
                            </div>
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @method('PUT')
                                @csrf
                                <input type="hidden" name="team_id" value="{{ $company->id }}">
                                <input type="hidden" name="till" value="dashboard">
                                <button type="submit" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna företaget') }}</button>
                            </form>
                        </div>
                        @if($row['can_assign'] || $row['responsible'])
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                                <span>{{ __('Ansvarig') }}:</span>
                                @if($row['can_assign'])
                                    <select class="rounded-lg border-gray-300 py-1 text-sm focus:border-accent-500 focus:ring-accent-500" aria-label="{{ __('Ansvarig för :company', ['company' => $company->name]) }}" wire:change="assign({{ $company->id }}, $event.target.value)">
                                        <option value="">{{ __('Ingen vald') }}</option>
                                        @foreach($row['consultants'] as $id => $name)
                                            <option value="{{ $id }}" @selected(($row['responsible']['id'] ?? null) === $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="font-medium text-gray-900">{{ $row['responsible']['name'] }}</span>
                                @endif
                            </div>
                        @endif
                        @if($row['can_note'])
                            @if($editingNote === $company->id)
                                <form wire:submit="saveNote" class="mt-3 space-y-2">
                                    <label for="client_note_{{ $company->id }}" class="block text-sm font-medium text-gray-700">{{ __('Byråns anteckning (syns inte för kunden)') }}</label>
                                    <textarea id="client_note_{{ $company->id }}" rows="3" maxlength="2000" class="{{ $input }}" wire:model="note"></textarea>
                                    <x-input-error for="note" />
                                    <div class="flex items-center gap-4">
                                        <x-button>{{ __('Spara anteckningen') }}</x-button>
                                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('editingNote', null)">{{ __('Avbryt') }}</button>
                                    </div>
                                </form>
                            @else
                                <div class="mt-2 flex flex-wrap items-start gap-2 text-sm">
                                    @if($row['note'])
                                        <p class="min-w-0 flex-1 whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2 text-gray-700">{{ $row['note'] }}</p>
                                    @endif
                                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="editNote({{ $company->id }})">{{ $row['note'] ? __('Ändra anteckning') : __('Lägg till anteckning') }}</button>
                                </div>
                            @endif
                        @endif
                        @if($row['tasks'] === [])
                            <p class="mt-3 text-sm text-gray-600">{{ __('Inget väntar.') }}</p>
                        @else
                            <ul class="mt-3 flex flex-wrap gap-2">
                                @foreach($row['tasks'] as $task)
                                    <li wire:key="task-{{ $company->id }}-{{ $loop->index }}">
                                        <form method="POST" action="{{ route('current-team.update') }}">
                                            @method('PUT')
                                            @csrf
                                            <input type="hidden" name="team_id" value="{{ $company->id }}">
                                            <input type="hidden" name="till" value="{{ $task['destination'] }}">
                                            <button type="submit" @class([
                                                'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm hover:border-gray-400',
                                                'border-accent-200 bg-accent-50 text-accent-800' => $task['urgent'],
                                                'border-gray-200 bg-white text-gray-800' => ! $task['urgent'],
                                            ])>
                                                <span class="font-semibold tabular-nums">{{ $task['count'] }}</span>
                                                <span>{{ $task['label'] }}</span>
                                                @if($task['due'])
                                                    <span @class(['tabular-nums', 'font-semibold text-accent-800' => $task['overdue'], 'text-gray-600' => ! $task['overdue']])>· {{ $task['overdue'] ? __('försenad, sista dag var :date', ['date' => $task['due']]) : __('sista dag :date', ['date' => $task['due']]) }}</span>
                                                @endif
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui.card>
                </li>
            @endforeach
        </ul>
    @endif
</div>
