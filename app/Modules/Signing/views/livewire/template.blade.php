@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<form wire:submit="save" class="space-y-6" data-testid="signing-template-form">
    <x-ui.card :title="__('Mallen')">
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-ui.field for="template-name" :label="__('Mallens namn')" error="name">
                <input id="template-name" type="text" class="{{ $input }} mt-1" wire:model="name" maxlength="120" autocomplete="off" required>
            </x-ui.field>
            @if($templateId === null)
                <x-ui.field for="template-type" :label="__('Mallen är för')" error="document_type">
                    <select id="template-type" class="{{ $input }} mt-1" wire:change="setType($event.target.value)">
                        <option value="QUOTE" @selected($type === 'QUOTE')>{{ __('Offerter') }}</option>
                        <option value="AGREEMENT" @selected($type === 'AGREEMENT')>{{ __('Avtal') }}</option>
                    </select>
                </x-ui.field>
            @else
                <p class="self-end text-sm text-gray-700">
                    @if($type === 'DOCUMENT')
                        {{ __('Eget dokument: :file', ['file' => $fileName]) }}
                    @else
                        {{ $type === 'QUOTE' ? __('För offerter') : __('För avtal') }}
                    @endif
                </p>
            @endif
        </div>
        @if($templateId !== null && $type === 'DOCUMENT')
            <p class="mt-3 text-sm text-gray-600">{{ __('PDF-filen ändras aldrig. Behöver du en annan fil, spara en ny mall och arkivera den här. Fälten ändras i guiden.') }}
                <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="editFields">{{ __('Ändra fält') }}</button>
            </p>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Roller')" :description="__('Undertecknare med samma steg signerar samtidigt. Nästa steg får sin länk först när alla i steget före har signerat. Högst :count roller.', ['count' => $maxRoles])">
        <x-input-error for="roles" class="mt-2" />
        <ol class="mt-3 space-y-3">
            @foreach($roles as $index => $role)
                <li wire:key="template-role-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_6rem_1fr_auto] sm:items-end">
                    <x-ui.field for="template-role-label-{{ $index }}" :label="__('Roll')" error="roles.{{ $index }}.label">
                        <input id="template-role-label-{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="roles.{{ $index }}.label" maxlength="60" autocomplete="off" placeholder="{{ __('Till exempel Kund') }}" required>
                    </x-ui.field>
                    <x-ui.field for="template-role-step-{{ $index }}" :label="__('Steg')" error="roles.{{ $index }}.step">
                        <select id="template-role-step-{{ $index }}" class="{{ $input }} mt-1" wire:model="roles.{{ $index }}.step">
                            @for($number = 1; $number <= $maxStep; $number++)
                                <option value="{{ $number }}">{{ $number }}</option>
                            @endfor
                        </select>
                    </x-ui.field>
                    <x-ui.field for="template-role-check-{{ $index }}" :label="__('Kontroll av undertecknaren')" error="roles.{{ $index }}.verification">
                        <select id="template-role-check-{{ $index }}" class="{{ $input }} mt-1" wire:model="roles.{{ $index }}.verification">
                            @foreach($checks as $check)
                                <option value="{{ $check->value }}">{{ $check->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if(count($roles) > 1)
                        <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeRole({{ $index }})">{{ __('Ta bort') }}</button>
                    @endif
                    <label class="flex items-start gap-3 text-sm sm:col-span-4">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="roles.{{ $index }}.internal">
                        <span>{{ __('Företagets egen roll: en medlem föreslås när mallen används') }}</span>
                    </label>
                </li>
            @endforeach
        </ol>
        @if(count($roles) < $maxRoles)
            <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="addRole">{{ __('Lägg till roll') }}</button>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Inställningar')" :description="__('Det som fylls i när mallen används. Du kan ändra allt innan du skickar.')">
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <x-ui.field for="template-days" :label="__('Dagar att signera (frivilligt)')" error="deadline_days" :hint="__('Mellan :min och :max dagar. Annars standard.', ['min' => $minDays, 'max' => $maxDays])">
                <input id="template-days" type="number" min="{{ $minDays }}" max="{{ $maxDays }}" class="{{ $input }} mt-1" wire:model="deadlineDays">
            </x-ui.field>
            <x-ui.field for="template-language" :label="__('Språk i kopian efter signering')" error="language">
                <select id="template-language" class="{{ $input }} mt-1" wire:model="language">
                    <option value="sv">{{ __('Svenska') }}</option>
                    <option value="en">{{ __('Engelska') }}</option>
                </select>
            </x-ui.field>
            @if($autoReminders)
                <x-ui.field for="template-reminder-days" :label="__('Påminn')" error="reminder_days">
                    <select id="template-reminder-days" class="{{ $input }} mt-1" wire:model="reminderDays">
                        @foreach($intervals as $days)
                            <option value="{{ $days }}">{{ $days === 1 ? __('Varje dag') : __('Var :days:e dag', ['days' => $days]) }}</option>
                        @endforeach
                        <option value="single">{{ __('En gång, två dagar före sista dag') }}</option>
                    </select>
                </x-ui.field>
            @endif
        </div>
        <label class="mt-4 flex items-start gap-3 text-sm">
            <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model.live="autoReminders">
            <span>{{ __('Påminn de som ska signera automatiskt') }}</span>
        </label>
        @if($type === 'DOCUMENT')
            <label class="mt-3 flex items-start gap-3 text-sm">
                <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300 text-accent-600" wire:model="confidential">
                <span>{{ __('Gör signeringar från mallen konfidentiella') }}</span>
            </label>
        @endif

        <fieldset class="mt-6">
            <legend class="text-sm font-semibold text-gray-900">{{ __('Kopiemottagare') }}</legend>
            <p class="mt-1 text-sm text-gray-600">{{ __('Får dokumentet och signeringsbevisen när alla har signerat. Högst :count.', ['count' => $maxCopies]) }}</p>
            <x-input-error for="copies" class="mt-2" />
            @if($copies !== [])
                <ul class="mt-3 space-y-3">
                    @foreach($copies as $index => $copy)
                        <li wire:key="template-copy-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end">
                            <x-ui.field for="template-copy-email-{{ $index }}" :label="__('E-post')" error="copies.{{ $index }}.email">
                                <input id="template-copy-email-{{ $index }}" type="email" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.email" autocomplete="off" required>
                            </x-ui.field>
                            <x-ui.field for="template-copy-name-{{ $index }}" :label="__('Namn (frivilligt)')" error="copies.{{ $index }}.name">
                                <input id="template-copy-name-{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.name" maxlength="120" autocomplete="off">
                            </x-ui.field>
                            <x-ui.field for="template-copy-language-{{ $index }}" :label="__('Språk i mejlet')" error="copies.{{ $index }}.language">
                                <select id="template-copy-language-{{ $index }}" class="{{ $input }} mt-1" wire:model="copies.{{ $index }}.language">
                                    <option value="sv">{{ __('Svenska') }}</option>
                                    <option value="en">{{ __('Engelska') }}</option>
                                </select>
                            </x-ui.field>
                            <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeCopy({{ $index }})">{{ __('Ta bort') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if(count($copies) < $maxCopies)
                <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="addCopy">{{ __('Lägg till kopiemottagare') }}</button>
            @endif
        </fieldset>
    </x-ui.card>

    <div class="flex flex-wrap items-center gap-4">
        <x-button>{{ __('Spara mallen') }}</x-button>
        <a href="{{ route('signing.templates') }}" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0">{{ __('Avbryt') }}</a>
    </div>
</form>
