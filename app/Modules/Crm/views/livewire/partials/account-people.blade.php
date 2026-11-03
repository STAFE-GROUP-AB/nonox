{{-- The account's contact people with their role, Kontakta inte and the person's page: the Personer tab. Needs $people (decision maker first). --}}
        @if($people->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga kontaktpersoner ännu.') }}</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($people as $person)
                    <li wire:key="person-{{ $person->id }}" class="py-3">
                        <span class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium text-gray-900"><a href="{{ route('crm.people.show', $person) }}" class="hover:text-accent-700">{{ $person->name }}</a>@if($person->doNotContact()) <x-ui.badge tone="accent" class="ms-1">{{ __('Kontakta inte') }}</x-ui.badge>@endif</span>
                            @if($canManage)
                                <select class="rounded-lg border-gray-300 py-1 text-xs focus:border-accent-500 focus:ring-accent-500" aria-label="{{ __('Roll för :name', ['name' => $person->name]) }}" wire:change="changeRole({{ $person->id }}, $event.target.value)">
                                    @foreach($roles as $role)
                                        <option value="{{ $role->value }}" @selected($person->link->role === $role)>{{ $role->label() }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="text-xs text-gray-500">{{ $person->link->role->label() }}</span>
                            @endif
                        </span>
                        @if($person->title)<span class="block text-sm text-gray-600">{{ $person->title }}</span>@endif
                        <span class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            @if($person->callNumber())<a href="tel:{{ preg_replace('/[^\d+]/', '', (string) $person->callNumber()) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 sm:min-h-0">{{ $person->callNumber() }}</a>@endif
                            @if($person->email)<a href="mailto:{{ $person->email }}" class="inline-flex min-h-11 items-center break-all font-medium text-accent-600 sm:min-h-0">{{ $person->email }}</a>@endif
                        </span>
                        @if($canManage)
                            <span class="mt-1 flex flex-wrap gap-x-4 text-sm">
                                @unless($person->doNotContact())<button type="button" class="min-h-11 text-gray-700 underline sm:min-h-0" wire:click="startActivity('CALL', {{ $person->id }})">{{ __('Logga samtal') }}</button>@endunless
                                <button type="button" class="min-h-11 text-gray-700 underline sm:min-h-0" wire:click="startPerson({{ $person->id }})">{{ __('Ändra') }}</button>
                                <button type="button" class="min-h-11 text-gray-700 underline sm:min-h-0" wire:click="unlink({{ $person->id }})" wire:confirm="{{ __('Ta bort :name från kunden? Personen finns kvar under Personer.', ['name' => $person->name]) }}">{{ __('Ta bort från kunden') }}</button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if($canManage)
            <div class="mt-4 flex flex-wrap gap-4 text-sm">
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="startPerson">{{ __('Ny person') }}</button>
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="startLink">{{ __('Koppla befintlig') }}</button>
            </div>
        @endif
