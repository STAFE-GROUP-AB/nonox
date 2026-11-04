{{--
    The activity form (log, plan, change, the next step) and the completion
    form with the deal's required next step (WorksActivities). Shared by the
    account card (person select from $personOptions) and the person page
    (account select from $accountOptions). Needs $input, $types, $outcomes
    and $members.
--}}
    @if(in_array($panel, ['activity', 'next'], true))
        <x-ui.card :title="$panel === 'next' ? __('Nästa steg') : ($editingActivityId ? __('Ändra aktivitet') : App\Modules\Crm\Domain\ActivityType::from($activity['type'])->label())" :description="$panel === 'next' ? __('Vad ska hända härnäst med kunden? Planera det nu, eller hoppa över.') : null">
            <form wire:submit="saveActivity" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="activity_type" :label="__('Typ')" error="type">
                    <select id="activity_type" class="{{ $input }} mt-1" wire:model.live="activity.type">
                        @foreach($types as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($accountOptions !== null)
                    <x-ui.field for="activity_account" :label="__('Företag')" error="account_id">
                        <select id="activity_account" class="{{ $input }} mt-1" wire:model="activity.account_id">
                            @foreach($accountOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @else
                    <x-ui.field for="activity_person" :label="__('Person')" error="person_id">
                        <select id="activity_person" class="{{ $input }} mt-1" wire:model="activity.person_id">
                            <option value="">{{ __('Ingen särskild') }}</option>
                            @foreach($personOptions as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="activity_subject" :label="__('Rubrik')" error="subject" class="sm:col-span-2">
                    <input id="activity_subject" type="text" class="{{ $input }} mt-1" wire:model="activity.subject" placeholder="{{ App\Modules\Crm\Domain\ActivityType::from($activity['type'])->label() }}">
                </x-ui.field>
                <x-ui.field for="activity_body" :label="__('Anteckning')" error="body" class="sm:col-span-2">
                    <textarea id="activity_body" rows="3" class="{{ $input }} mt-1" wire:model="activity.body"></textarea>
                </x-ui.field>
                @if($activity['type'] !== 'NOTE')
                    <label class="flex min-h-11 items-center gap-3 text-sm text-gray-800 sm:col-span-2">
                        <input type="checkbox" class="size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model.live="activity.done">
                        <span>{{ __('Redan gjort') }}</span>
                    </label>
                    @if($activity['type'] === 'CALL' && $activity['done'])
                        <x-ui.field for="activity_outcome" :label="__('Hur gick samtalet?')" error="call_outcome">
                            <select id="activity_outcome" class="{{ $input }} mt-1" wire:model="activity.call_outcome">
                                @foreach($outcomes as $outcome)
                                    <option value="{{ $outcome->value }}">{{ $outcome->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    @if(! $activity['done'])
                        <x-ui.field for="activity_due" :label="__('När')" error="due_at">
                            <input id="activity_due" type="datetime-local" class="{{ $input }} mt-1" wire:model="activity.due_at">
                        </x-ui.field>
                        {{-- Stage 6 (ADR-049): the length in a calendar, a meeting's place and its invitation. --}}
                        <x-ui.field for="activity_duration" :label="__('Längd i kalendern')" error="duration_minutes">
                            <select id="activity_duration" class="{{ $input }} mt-1" wire:model="activity.duration_minutes">
                                <option value="">{{ $activity['type'] === 'MEETING' ? __('Standard (1 timme)') : __('Standard (15 min)') }}</option>
                                @foreach([15 => __('15 min'), 30 => __('30 min'), 45 => __('45 min'), 60 => __('1 timme'), 90 => __('1,5 timmar'), 120 => __('2 timmar'), 180 => __('3 timmar'), 240 => __('4 timmar')] as $minutes => $label)
                                    <option value="{{ $minutes }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($activity['type'] === 'MEETING')
                            <x-ui.field for="activity_location" :label="__('Plats')" error="location">
                                <input id="activity_location" type="text" class="{{ $input }} mt-1" wire:model="activity.location" placeholder="{{ __('Adress eller länk till videomöte') }}">
                            </x-ui.field>
                            <label class="flex items-start gap-3 text-sm text-gray-800 sm:col-span-2">
                                <input type="checkbox" class="mt-0.5 size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="activity.invite" aria-describedby="activity_invite_hint">
                                <span>
                                    {{ __('Skicka inbjudan till personen') }}
                                    <span id="activity_invite_hint" class="block text-gray-600">{{ __('Ett mejl med mötet som kalenderfil går till mötets person, med dig som avsändare och mottagare av svaret. Rubrik, tid och plats följer med, inte anteckningen. Går inte till någon som är markerad Kontakta inte.') }}</span>
                                </span>
                            </label>
                        @endif
                    @endif
                    <x-ui.field for="activity_assigned" :label="__('Vem gör det')" error="assigned_to">
                        <select id="activity_assigned" class="{{ $input }} mt-1" wire:model="activity.assigned_to">
                            @foreach($members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    @if($panel === 'next')
                        <button type="button" class="min-h-11 text-sm font-medium text-gray-700 underline" wire:click="skipNextStep">{{ __('Hoppa över') }}</button>
                    @else
                        <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'complete')
        <x-ui.card :title="__('Klarmarkera')">
            <form wire:submit="complete" class="grid gap-4 sm:grid-cols-2">
                @if($completion['call_outcome'] !== '')
                    <x-ui.field for="completion_outcome" :label="__('Hur gick samtalet?')" error="call_outcome">
                        <select id="completion_outcome" class="{{ $input }} mt-1" wire:model="completion.call_outcome">
                            @foreach($outcomes as $outcome)
                                <option value="{{ $outcome->value }}">{{ $outcome->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="completion_note" :label="__('Hur gick det? (valfritt)')" error="note" class="sm:col-span-2">
                    <textarea id="completion_note" rows="2" class="{{ $input }} mt-1" wire:model="completion.note"></textarea>
                </x-ui.field>
                @if($dealStepRequired)
                    {{-- The deal's last open step: the next one is planned in the same go (ADR-043 stage 2). --}}
                    <fieldset class="grid gap-4 rounded-2xl bg-stone-50 p-4 sm:col-span-2 sm:grid-cols-3">
                        <legend class="px-1 text-sm font-semibold text-ink">{{ __('Nästa steg i affären') }}</legend>
                        <p class="text-sm text-gray-600 sm:col-span-3">{{ __('Det här är affärens enda planerade steg. Planera nästa, eller avsluta affären som vunnen eller förlorad.') }}</p>
                        <x-ui.field for="completion_next_type" :label="__('Typ')" :error="$errors->has('next.type') ? 'next.type' : 'type'">
                            <select id="completion_next_type" class="{{ $input }} mt-1" wire:model="activity.type">
                                @foreach($types as $type)
                                    @continue(! $type->canBePlanned())
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="completion_next_subject" :label="__('Vad')" error="subject">
                            <input id="completion_next_subject" type="text" class="{{ $input }} mt-1" wire:model="activity.subject">
                        </x-ui.field>
                        <x-ui.field for="completion_next_due" :label="__('När')" error="due_at">
                            <input id="completion_next_due" type="datetime-local" class="{{ $input }} mt-1" wire:model="activity.due_at">
                        </x-ui.field>
                        @error('next')<p class="text-sm text-accent-700 sm:col-span-3">{{ $message }}</p>@enderror
                        @error('person_id')<p class="text-sm text-accent-700 sm:col-span-3">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ $dealStepRequired ? __('Klar och planera nästa') : __('Klar') }}</x-button>
                    @if($dealStepRequired && $nextDealId)
                        <a href="{{ route('crm.deals.show', $nextDealId) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Avsluta affären') }}</a>
                    @endif
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif
