@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<div class="space-y-8">
    @if($canManage && $editing === null)
        <div class="flex flex-wrap items-center gap-4">
            <x-button type="button" wire:click="create">{{ __('Lägg till person') }}</x-button>
            <label class="flex items-center gap-2 text-sm"><x-checkbox wire:model.live="showArchived" /> {{ __('Visa arkiverade') }}</label>
        </div>
    @endif

    @if($editing !== null)
        <x-ui.card :title="$editing === 0 ? __('Ny person') : __('Ändra person')">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
                <x-ui.field for="p-first" :label="__('Förnamn')" error="form.first_name">
                    <input id="p-first" wire:model="form.first_name" class="{{ $input }}" required autocomplete="off">
                </x-ui.field>
                <x-ui.field for="p-last" :label="__('Efternamn')" error="form.last_name">
                    <input id="p-last" wire:model="form.last_name" class="{{ $input }}" required autocomplete="off">
                </x-ui.field>
                <x-ui.field for="p-number" :label="__('Person- eller samordningsnummer')" :hint="$editing ? __('Lämna tomt för att behålla det sparade numret.') : __('Tolv siffror, ÅÅÅÅMMDD-NNNN.')" error="form.personal_number">
                    <input id="p-number" wire:model="form.personal_number" class="{{ $input }} tabular-nums" inputmode="numeric" autocomplete="off" @if(! $editing) required @endif>
                </x-ui.field>
                <x-ui.field for="p-email" :label="__('E-post (frivilligt)')" :hint="__('För påminnelse om glömd utcheckning.')" error="form.email">
                    <input id="p-email" type="email" wire:model="form.email" class="{{ $input }}" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="p-language" :label="__('Språk på kiosken')" error="form.language">
                    <select id="p-language" wire:model="form.language" class="{{ $input }}">
                        @foreach($languages as [$code, $label])
                            <option value="{{ $code }}" lang="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="p-user" :label="__('Inloggning (för mobil incheckning)')" error="form.user_id">
                    <select id="p-user" wire:model="form.user_id" class="{{ $input }}">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($members as $member)
                            <option value="{{ $member['id'] }}">{{ $member['name'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($employees->isNotEmpty())
                    <x-ui.field for="p-employee" :label="__('Anställd i lönen')" :hint="__('Behövs för att föreslå tidrapporter.')" error="form.employee_id">
                        <select id="p-employee" wire:model="form.employee_id" class="{{ $input }}">
                            <option value="">{{ __('Ingen koppling') }}</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->employee_number }} {{ $employee->first_name }} {{ $employee->last_name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <label class="flex items-center gap-3 sm:col-span-2"><x-checkbox wire:model="form.is_manager" /> <span>{{ __('Chef: kan öppna kontrollbesöksvyn på kiosken med sin kod') }}</span></label>
                <label class="flex items-center gap-3 sm:col-span-2"><x-checkbox wire:model.live="form.hired" /> <span>{{ __('Inhyrd personal (anställd av ett annat företag)') }}</span></label>
                @if($form['hired'] ?? false)
                    <x-ui.field for="p-employer" :label="__('Arbetsgivarens namn')" error="form.employer_name">
                        <input id="p-employer" wire:model="form.employer_name" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="p-employer-org" :label="__('Arbetsgivarens organisationsnummer')" error="form.employer_org_number">
                        <input id="p-employer-org" wire:model="form.employer_org_number" class="{{ $input }} tabular-nums" inputmode="numeric">
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editing', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($settingPin)
        <x-ui.card :title="__('Kod för kiosken')" :description="__('Fyra siffror som personen väljer. Koden sparas aldrig; bara ett kontrollvärde som kiosken kan pröva mot.')">
            <form wire:submit="savePin" class="flex flex-wrap items-end gap-3">
                <x-ui.field for="p-pin" :label="__('Kod')" error="pin">
                    <input id="p-pin" type="password" wire:model="pin" class="{{ $input }} w-32 tabular-nums" inputmode="numeric" maxlength="4" autocomplete="new-password" required>
                </x-ui.field>
                <x-button type="submit">{{ __('Spara koden') }}</x-button>
                <x-secondary-button type="button" wire:click="$set('settingPin', null)">{{ __('Avbryt') }}</x-secondary-button>
            </form>
        </x-ui.card>
    @endif

    @if($settingPhoto)
        <x-ui.card :title="__('Foto på kiosken')" :description="__('Frivilligt. Fotot visas bara på kiosken, så att personen hittar sitt namn snabbare.')">
            <form wire:submit="savePhoto" class="space-y-4">
                <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="block text-sm">
                <label class="flex items-start gap-3"><x-checkbox wire:model="consent" /> <span>{{ __('Personen har samtyckt till att fotot visas på kiosken och vet att samtycket kan återkallas.') }}</span></label>
                <div class="flex gap-3">
                    <x-button type="submit">{{ __('Spara fotot') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('settingPhoto', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($people->isEmpty())
            <x-ui.empty-state :title="__('Ingen personal ännu')">{{ __('Lägg till alla som arbetar i lokalen, även inhyrda och de som jobbar extra.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-start text-gray-600">
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Namn') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Person- eller samordningsnummer') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Arbetsgivare') }}</th>
                            <th class="py-2 pe-4 text-start font-medium">{{ __('Kiosk') }}</th>
                            <th class="py-2 text-start font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($people as $person)
                            <tr wire:key="person-{{ $person->id }}">
                                <td class="py-3 pe-4 font-medium text-ink">
                                    {{ $person->name() }}
                                    @if($person->is_manager) <x-ui.badge>{{ __('Chef') }}</x-ui.badge> @endif
                                    @if($person->archived_at) <x-ui.badge>{{ __('Arkiverad') }}</x-ui.badge> @endif
                                </td>
                                <td class="py-3 pe-4 tabular-nums">{{ $sensitive ? $person->formattedPersonalNumber() : $person->maskedPersonalNumber() }}</td>
                                <td class="py-3 pe-4">{{ $person->employer_name ? $person->employer_name.' ('.$person->employer_org_number.')' : __('Eget företag') }}</td>
                                <td class="py-3 pe-4">{{ $person->hasPin() ? __('Kod vald') : __('Ingen kod') }}{{ $person->photo_path ? ' · '.__('foto') : '' }}</td>
                                <td class="py-3">
                                    @if($canManage)
                                        <div class="flex flex-wrap gap-3 font-semibold">
                                            <button type="button" wire:click="edit({{ $person->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                                            <button type="button" wire:click="$set('settingPin', {{ $person->id }})" class="text-accent-700 underline">{{ $person->hasPin() ? __('Ny kod') : __('Välj kod') }}</button>
                                            <a href="{{ route('staff-ledger.people.card', $person->id) }}" target="_blank" class="text-accent-700 underline">{{ __('QR-kort') }}</a>
                                            <button type="button" wire:click="renewQr({{ $person->id }})" wire:confirm="{{ __('Skapa en ny QR-kod? Det gamla kortet slutar fungera.') }}" class="text-gray-700 underline">{{ __('Ny QR-kod') }}</button>
                                            @if($person->photo_path)
                                                <button type="button" wire:click="removePhoto({{ $person->id }})" class="text-gray-700 underline">{{ __('Ta bort foto') }}</button>
                                            @else
                                                <button type="button" wire:click="$set('settingPhoto', {{ $person->id }})" class="text-gray-700 underline">{{ __('Lägg till foto') }}</button>
                                            @endif
                                            <button type="button" wire:click="toggleArchive({{ $person->id }})" class="text-gray-700 underline">{{ $person->archived_at ? __('Återställ') : __('Arkivera') }}</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
