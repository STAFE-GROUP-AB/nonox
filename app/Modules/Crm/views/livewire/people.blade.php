@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    @if($panel === 'person')
        @include('crm::livewire.partials.person-form', ['withRole' => false])
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage && $panel === '')
                <x-button type="button" wire:click="startPerson">{{ __('Ny person') }}</x-button>
            @endif
            <input type="search" class="{{ $input }} w-full sm:max-w-sm" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på namn, titel eller e-post') }}" aria-label="{{ __('Sök person') }}">
            <div class="flex gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
                <button type="button" wire:click="$set('show', '')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => ! $pruning, 'bg-white text-ink ring-1 ring-stone-200' => $pruning]) aria-pressed="{{ $pruning ? 'false' : 'true' }}">{{ __('Alla') }}</button>
                <button type="button" wire:click="$set('show', 'gallras')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $pruning, 'bg-white text-ink ring-1 ring-stone-200' => ! $pruning]) aria-pressed="{{ $pruning ? 'true' : 'false' }}">{{ __('Gallras snart') }}</button>
                @if($canManage)
                    <button type="button" wire:click="$set('show', 'sparrlista')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $show === 'sparrlista', 'bg-white text-ink ring-1 ring-stone-200' => $show !== 'sparrlista']) aria-pressed="{{ $show === 'sparrlista' ? 'true' : 'false' }}">{{ __('Spärrlista') }}</button>
                @endif
            </div>
        </div>

        @if($pruning)
            <p class="mt-4 text-sm text-gray-600">{{ __('Personer och prospekt utan aktivitet på :months månader raderas enligt företagets gallringsregel. Här är de som raderas inom 30 dagar. Logga en aktivitet för att behålla en kontakt.', ['months' => $months]) }} <a href="{{ route('manual.article', ['crm', 'gdpr-i-crm']) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Om gallringen') }}</a></p>
            @if($prospects->isNotEmpty())
                <h3 class="mt-4 text-sm font-semibold text-ink">{{ __('Prospekt') }}</h3>
                <ul class="mt-2 divide-y divide-gray-100">
                    @foreach($prospects as $prospect)
                        <li wire:key="prospect-{{ $prospect->id }}" class="py-2"><a href="{{ route('crm.accounts.show', $prospect) }}" class="inline-flex min-h-11 items-center font-medium text-gray-900 hover:text-accent-700 sm:min-h-0">{{ $prospect->name }}</a></li>
                    @endforeach
                </ul>
                <h3 class="mt-4 text-sm font-semibold text-ink">{{ __('Personer') }}</h3>
            @endif
        @endif

        @if($show === 'sparrlista')
            <p class="mt-4 text-sm text-gray-600">{{ __('E-postadresser och telefonnummer till personer som har invänt mot att bli kontaktade. Raden finns kvar när personen raderas, så att invändningen gäller om personen läggs till igen. Bara en kontrollsumma av e-postadressen eller telefonnumret finns kvar, aldrig själva uppgiften. Behåll en rad om invändningen fortfarande gäller.') }}</p>
            @if($suppressions->isEmpty())
                <x-ui.empty-state class="mt-6" :title="__('Spärrlistan är tom')">{{ __('Ingen som har invänt finns på listan.') }}</x-ui.empty-state>
            @else
                <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-suppressions">
                    @foreach($suppressions as $entry)
                        <li wire:key="suppression-{{ $entry->id }}" class="flex flex-wrap items-center gap-3 py-3">
                            <span class="min-w-0 flex-1 basis-52 text-sm">
                                <span class="block font-medium text-gray-900">{{ $entry->kind === 'EMAIL' ? __('E-postadress') : __('Telefonnummer') }}@if($entry->reason === App\Modules\Crm\Support\SuppressionList::ERASURE) · {{ __('raderad på begäran') }}@endif</span>
                                <span @class(['block', 'font-medium text-accent-700' => $entry->updated_at < $reviewBefore, 'text-gray-600' => $entry->updated_at >= $reviewBefore])>{{ __('Senast granskad :date', ['date' => $entry->updated_at?->format('Y-m-d')]) }}@if($entry->updated_at < $reviewBefore) · {{ __('ska granskas') }}@endif</span>
                            </span>
                            <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="keepSuppression({{ $entry->id }})">{{ __('Behåll') }}</button>
                            <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="removeSuppression({{ $entry->id }})" wire:confirm="{{ __('Ta bort raden från spärrlistan? Läggs personen till igen märks den inte längre Kontakta inte.') }}">{{ __('Ta bort') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif($people->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$pruning ? __('Ingen gallras de närmaste 30 dagarna') : ($search === '' ? __('Inga personer ännu') : __('Ingen person matchar sökningen'))">
                {{ $pruning ? __('Alla kontakter har haft aktivitet nyligen.') : __('Lägg till kontaktpersoner här eller på kundens kort, med roll och rättslig grund.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-people">
                @foreach($people as $person)
                    <li wire:key="person-{{ $person->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3">
                        <span class="min-w-0 flex-1 basis-52">
                            <span class="block font-medium text-gray-900"><a href="{{ route('crm.people.show', $person) }}" class="hover:text-accent-700">{{ $person->name }}</a>@if(! $person->is_active) <span class="ms-1 text-xs font-normal text-gray-500">({{ __('inaktiv') }})</span>@endif @if($person->doNotContact())<x-ui.badge tone="accent" class="ms-1">{{ __('Kontakta inte') }}</x-ui.badge>@endif</span>
                            <span class="block text-sm text-gray-600">
                                {{ collect([$person->title, $person->legal_basis->label()])->filter()->implode(' · ') }}
                            </span>
                            @if($person->accounts->isNotEmpty())
                                <span class="block text-sm">
                                    @foreach($person->accounts as $account)
                                        <a href="{{ route('crm.accounts.show', $account) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $account->name }}</a> <span class="text-gray-500">({{ $account->link->role->label() }})</span>@if(! $loop->last), @endif
                                    @endforeach
                                </span>
                            @endif
                        </span>
                        <span class="flex flex-wrap gap-x-4 text-sm">
                            @if($person->callNumber())<a href="tel:{{ preg_replace('/[^\d+]/', '', (string) $person->callNumber()) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 sm:min-h-0">{{ $person->callNumber() }}</a>@endif
                            @if($person->email)<a href="mailto:{{ $person->email }}" class="inline-flex min-h-11 items-center break-all font-medium text-accent-600 sm:min-h-0">{{ $person->email }}</a>@endif
                            @if($canManage)
                                <button type="button" class="min-h-11 text-gray-700 underline sm:min-h-0" wire:click="startPerson({{ $person->id }})">{{ __('Ändra') }}</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $people->links() }}</div>
        @endif
    </x-ui.card>
</div>
