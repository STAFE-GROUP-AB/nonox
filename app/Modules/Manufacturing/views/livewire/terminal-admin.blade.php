@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card :title="__('Det här är produktionstid')">
        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700">
            <li>{{ __('Tiden på verkstadsterminalen är tid på en tillverkningsorder. Det är inte närvaro, och den ersätter inte personalliggaren.') }}</li>
            <li>{{ __('Den är inte lönetid och går inte till lönen. Terminalen och lönen är inte kopplade.') }}</li>
            <li>{{ __('Var och en ser bara sina egna rapporter på surfplattan. Rapporterna används för efterkalkylen per produkt och arbetssteg. Det finns ingen vy per person, men i en liten verkstad kan siffrorna för ett arbetssteg ändå gå att koppla till en person.') }}</li>
            <li>{{ __('Namnet på den som rapporterat sparas 24 månader. Därefter tas det bort ur rapporten, och minuterna finns kvar som kostnad på ordern.') }}</li>
        </ul>
    </x-ui.card>

    @if($moduleOn)
        <x-ui.card :title="__('Verkstadsterminalen')" :description="$enabled ? __('Påslagen. Kopplade surfplattor kan rapportera tid.') : __('Avstängd. Slå på den för att koppla surfplattor och lägga till personer.')">
            @if($enabled)
                <x-secondary-button type="button" wire:click="enable(false)" wire:confirm="{{ __('Stänga av verkstadsterminalen? Surfplattorna kan inte rapportera tid förrän du slår på den igen. Inget raderas.') }}">{{ __('Stäng av') }}</x-secondary-button>
            @else
                <label class="mb-4 flex items-start gap-3 text-sm text-gray-700"><x-checkbox wire:model="informed" /> <span>{{ __('Personalen är informerad om vad terminalen sparar och varför, och jag har bedömt om förhandlingsskyldighet enligt medbestämmandelagen gäller innan terminalen tas i bruk.') }}</span></label>
                <x-button type="button" wire:click="enable(true)">{{ __('Slå på verkstadsterminalen') }}</x-button>
            @endif
        </x-ui.card>
    @endif

    @if($enabled)
        @if($pairing)
            <x-ui.card :title="__('Koppla :name', ['name' => $pairing['device']])" :description="__('Öppna länken i surfplattans webbläsare, eller skanna QR-koden med den. Koden gäller till :time och kan bara användas en gång.', ['time' => $pairing['expires']])">
                <div class="flex flex-wrap items-center gap-6">
                    <div class="size-48 shrink-0 [&_svg]:size-full" aria-hidden="true">{!! $pairingQr !!}</div>
                    <div class="space-y-2">
                        <p class="text-3xl font-bold tracking-widest tabular-nums text-ink">{{ $pairing['code'] }}</p>
                        <p class="break-all text-sm"><a href="{{ $pairing['url'] }}" target="_blank" rel="noopener" class="font-semibold text-accent-700 underline">{{ $pairing['url'] }}</a></p>
                        <p class="text-sm text-gray-600">{{ __('Lägg gärna till sidan på surfplattans hemskärm. Den är då en egen app i helskärm.') }}</p>
                    </div>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Surfplattor')">
            <form wire:submit="registerDevice" class="mb-6 flex flex-wrap items-end gap-3">
                <x-ui.field for="device-name" :label="__('Namn på surfplattan')" error="deviceName">
                    <input id="device-name" wire:model="deviceName" maxlength="100" class="{{ $input }} w-64" placeholder="{{ __('Snickeriet') }}" required>
                </x-ui.field>
                <x-button type="submit">{{ __('Lägg till surfplatta') }}</x-button>
            </form>

            @if($devices->isEmpty())
                <x-ui.empty-state :title="__('Inga surfplattor ännu')">{{ __('Lägg till en surfplatta, skriv in koden den får på plattan, och välj sedan vilka som ska synas på den.') }}</x-ui.empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach($devices as $device)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="device-{{ $device->id }}">
                            <div>
                                <span class="font-semibold text-ink">{{ $device->name }}</span>
                                @if($device->revoked_at)
                                    <x-ui.badge>{{ __('Spärrad') }}</x-ui.badge>
                                @elseif($device->paired_at)
                                    <x-ui.badge>{{ __('Kopplad') }}</x-ui.badge>
                                @else
                                    <x-ui.badge>{{ __('Väntar på koppling') }}</x-ui.badge>
                                @endif
                                @if($device->last_seen_at)
                                    <span class="ms-2 text-sm text-gray-500">{{ __('Senast sedd :time', ['time' => $device->last_seen_at->format('Y-m-d H:i')]) }}</span>
                                @endif
                            </div>
                            <div class="whitespace-nowrap">
                                @if(! $device->paired_at && ! $device->revoked_at)
                                    <button type="button" wire:click="renewCode({{ $device->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Ny kopplingskod') }}</button>
                                @endif
                                @if(! $device->revoked_at)
                                    <button type="button" wire:click="revokeDevice({{ $device->id }})" wire:confirm="{{ __('Spärra surfplattan? Den kan inte längre rapportera tid och måste kopplas på nytt.') }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Spärra') }}</button>
                                @endif
                                <button type="button" wire:click="removeDevice({{ $device->id }})" wire:confirm="{{ __('Ta bort surfplattan från listan? Tid den rapporterat finns kvar.') }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if($settingCodeFor)
            <x-ui.card :title="__('Ny kod')" :description="__('Fyra siffror som personen väljer. Koden sparas aldrig; bara ett kontrollvärde. En ny kod häver också en spärr efter för många felaktiga försök.')">
                <form wire:submit="saveCode" class="flex flex-wrap items-end gap-3">
                    <x-ui.field for="new-code" :label="__('Kod')" error="code">
                        <input id="new-code" type="password" wire:model="newCode" class="{{ $input }} w-32 tabular-nums" inputmode="numeric" maxlength="4" autocomplete="new-password" required>
                    </x-ui.field>
                    <x-button type="submit">{{ __('Spara koden') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('settingCodeFor', null)">{{ __('Avbryt') }}</x-secondary-button>
                </form>
            </x-ui.card>
        @endif

        @if($settingPhotoFor)
            <x-ui.card :title="__('Foto på surfplattan')" :description="__('Frivilligt. Fotot visas bara på verkstadsterminalen, så att personen hittar sig själv snabbare.')">
                <form wire:submit="savePhoto" class="space-y-4">
                    <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="block text-sm">
                    @error('photo')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                    <label class="flex items-start gap-3"><x-checkbox wire:model="consent" /> <span>{{ __('Personen har samtyckt till att fotot visas på verkstadsterminalen och vet att samtycket kan återkallas.') }}</span></label>
                    <div class="flex gap-3">
                        <x-button type="submit">{{ __('Spara fotot') }}</x-button>
                        <x-secondary-button type="button" wire:click="$set('settingPhotoFor', null)">{{ __('Avbryt') }}</x-secondary-button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif

    <x-ui.card :title="__('Personer på surfplattan')" :description="__('Bara namn, kod och ett frivilligt foto. Inget personnummer. De här personerna behöver inget konto i NONOX.')">
            @if($enabled)
            <form wire:submit="addPerson" class="mb-6 flex flex-wrap items-end gap-3">
                <x-ui.field for="person-name" :label="__('Namn')" error="name">
                    <input id="person-name" wire:model="personName" maxlength="100" class="{{ $input }} w-64" required>
                </x-ui.field>
                <x-ui.field for="person-code" :label="__('Kod (fyra siffror)')" error="code">
                    <input id="person-code" type="password" wire:model="personCode" class="{{ $input }} w-32 tabular-nums" inputmode="numeric" maxlength="4" autocomplete="new-password">
                </x-ui.field>
                <x-button type="submit">{{ __('Lägg till person') }}</x-button>
            </form>
            @endif

            @if($people->isEmpty())
                <x-ui.empty-state :title="__('Inga personer ännu')">{{ __('Lägg till dem som ska rapportera tid. Den som saknar kod syns inte på surfplattan.') }}</x-ui.empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach($people as $person)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="person-{{ $person->id }}">
                            <div>
                                <span class="font-semibold text-ink">{{ $person->name }}</span>
                                @if($person->archived_at)<x-ui.badge>{{ __('Arkiverad') }}</x-ui.badge>@endif
                                @if($person->isBlocked())<x-ui.badge>{{ __('Spärrad tills ny kod väljs') }}</x-ui.badge>@elseif($person->isLocked())<x-ui.badge>{{ __('Spärrad en stund') }}</x-ui.badge>@endif
                                <span class="ms-2 text-sm text-gray-500">{{ $person->hasCode() ? __('Kod vald') : __('Ingen kod') }}{{ $person->photo_path ? ' · '.__('foto') : '' }}</span>
                            </div>
                            <div class="whitespace-nowrap">
                                @if(! $person->archived_at)
                                    @if($enabled)
                                        <button type="button" wire:click="startCode({{ $person->id }})" class="text-sm font-semibold text-accent-700 underline">{{ $person->hasCode() ? __('Ny kod') : __('Välj kod') }}</button>
                                        <button type="button" wire:click="startPhoto({{ $person->id }})" class="ms-3 text-sm font-semibold text-accent-700 underline">{{ __('Foto') }}</button>
                                    @endif
                                    @if($person->photo_path)
                                        <button type="button" wire:click="removePhoto({{ $person->id }})" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Ta bort foto') }}</button>
                                    @endif
                                    <button type="button" wire:click="archivePerson({{ $person->id }}, true)" wire:confirm="{{ __('Arkivera personen? Personen syns inte längre på surfplattan. Rapporterad tid finns kvar.') }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Arkivera') }}</button>
                                @else
                                    <button type="button" wire:click="archivePerson({{ $person->id }}, false)" class="text-sm font-semibold text-accent-700 underline">{{ __('Visa på surfplattan igen') }}</button>
                                @endif
                                <a href="{{ route('privacy.export.workshopPerson', $person->id) }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Ladda ner registerutdrag (JSON)') }}</a>
                                <button type="button" wire:click="deletePerson({{ $person->id }})" wire:confirm="{{ __('Ta bort personen? Den som har rapporterat tid arkiveras i stället, så att tiden finns kvar.') }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

    <x-ui.card :title="__('Information till personalen')" :description="__('Visa eller skriv ut den här texten för dem som rapporterar tid. Det är företaget som är ansvarigt för uppgifterna.')">
        <p class="text-sm text-gray-700">{{ __('Vad sparas? När du rapporterar tid på surfplattan sparas ditt namn, vilken order och vilket arbetssteg, dagen, minuterna och vilken surfplatta det gjordes på. Det används för att räkna ut vad ordern kostade och för efterkalkylen per produkt och arbetssteg. Det är inte närvaro och inte lönetid. Ditt namn tas bort ur rapporten efter 24 månader. Du kan be arbetsgivaren om ett utdrag med dina uppgifter, och ett eventuellt foto kan du när som helst be att få borttaget.') }}</p>
    </x-ui.card>

    @if($flags->isNotEmpty())
        <x-ui.card :title="__('Markeringar av väntande arbetssteg')" :description="__('Orsaken är fri text och kan ha fått med ett namn. Ta bort orsaken när den inte behövs; tiderna finns kvar. Orsaker som är över ett år gamla tas bort automatiskt.')">
            <ul class="divide-y divide-stone-100">
                @foreach($flags as $flag)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm" wire:key="flag-{{ $flag->id }}">
                        <div>
                            <span class="font-semibold text-ink">{{ $flag->order?->reference() }}</span>
                            <span class="text-gray-600"> · {{ $flag->kind->label() }} · {{ $flag->raised_at->format('Y-m-d') }}</span>
                            <p class="text-gray-700">{{ $flag->reason }}</p>
                        </div>
                        @if($flag->reason !== \App\Modules\Manufacturing\Actions\Workshop\WaitingFlags::ERASED)
                            <button type="button" wire:click="eraseReason({{ $flag->id }})" wire:confirm="{{ __('Ta bort orsaken? Markeringen och tiderna finns kvar.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort orsaken') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
