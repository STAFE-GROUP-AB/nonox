@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $select = $input.' min-h-11 sm:min-h-0';
    $share = $total > 0 ? (int) floor($done * 100 / $total) : 0;
    $zone = 'Europe/Stockholm';
    $tel = fn (?string $number): ?string => $number ? 'tel:'.preg_replace('/[^\d+]/', '', $number) : null;
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-ink">{{ $list->name }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ collect([$list->assignee?->name ? __('Ringer: :name', ['name' => $list->assignee->name]) : null, $list->archived_at ? __('Arkiverad') : null])->filter()->implode(' · ') }}</p>
            </div>
            @if($canManage)
                <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="archive">{{ $list->archived_at ? __('Återställ listan') : __('Arkivera listan') }}</button>
            @endif
        </div>
        <div class="mt-4">
            <p class="text-sm font-medium text-gray-700">{{ __(':done av :total klara', ['done' => $done, 'total' => $total]) }}</p>
            <span class="mt-1 block h-2 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $share }}" aria-label="{{ __('Hur långt listan har kommit') }}"><span class="block h-2 rounded-full bg-accent-600" style="width: {{ $share }}%"></span></span>
        </div>
    </x-ui.card>

    @if($current === null || $account === null)
        <x-ui.card>
            <x-ui.empty-state :title="__('Listan är klar')">
                {{ __('Alla företag på listan är ringda eller överhoppade. Det som ska göras sedan finns under Mina aktiviteter.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2" data-testid="call-list-current">
                <p class="text-sm text-gray-600">{{ __('Nu: nummer :position', ['position' => $done + 1]) }}@if($current->attempts > 0) · {{ trans_choice('{1} ringd en gång förut|[2,*] ringd :count gånger förut', $current->attempts, ['count' => $current->attempts]) }}@endif</p>
                <h2 class="mt-1 text-xl font-bold tracking-tight text-ink"><a href="{{ route('crm.accounts.show', $account) }}" class="hover:text-accent-700">{{ $account->name }}</a></h2>
                <p class="mt-1 text-sm text-gray-600">{{ collect([$account->org_number, $account->city, $account->status->label()])->filter()->implode(' · ') }}</p>

                @if($register?->hasPhoneBlock())
                    <p class="mt-3 rounded-xl bg-accent-50 p-3 text-sm text-accent-800">{{ __('Företaget har spärrat telefonförsäljning i SCB:s företagsregister. Ring inte i säljsyfte.') }}</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-3">
                    @if($account->phone && ! $register?->hasPhoneBlock())
                        <a href="{{ $tel($account->phone) }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-ink px-5 text-sm font-semibold text-white hover:bg-black"><x-crm::icon name="phone" class="size-4" />{{ __('Ring :number', ['number' => $account->phone]) }}</a>
                    @elseif(! $account->phone)
                        <p class="text-sm text-gray-600">{{ __('Företaget har inget telefonnummer. Lägg till ett på kundkortet eller ring en kontaktperson.') }}</p>
                    @endif
                </div>

                @if($account->people->isNotEmpty())
                    <ul class="mt-4 space-y-2">
                        @foreach($account->people as $person)
                            <li wire:key="person-{{ $person->id }}" class="flex flex-wrap items-center gap-3 text-sm">
                                <span class="font-medium text-ink">{{ $person->name }}</span>
                                <span class="text-gray-600">{{ $person->link->role->label() }}@if($person->title) · {{ $person->title }}@endif</span>
                                @if($person->doNotContact())
                                    <x-ui.badge tone="accent">{{ __('Kontakta inte') }}</x-ui.badge>
                                @elseif($person->callNumber())
                                    <a href="{{ $tel($person->callNumber()) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ $person->callNumber() }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($canWork)
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <x-ui.field for="call-person" :label="__('Vem pratade du med?')" error="person_id">
                            <select id="call-person" class="{{ $select }} mt-1" wire:model="personId">
                                <option value="">{{ __('Ingen särskild') }}</option>
                                @foreach($account->people as $person)
                                    @unless($person->doNotContact())
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endunless
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="call-note" :label="__('Anteckning')" error="note">
                            <input id="call-note" type="text" class="{{ $input }} mt-1" wire:model="note">
                        </x-ui.field>
                        <x-ui.field for="call-next-type" :label="__('Nästa steg')" error="next_type">
                            <select id="call-next-type" class="{{ $select }} mt-1" wire:model.live="nextType">
                                <option value="">{{ __('Inget nästa steg') }}</option>
                                @foreach($nextTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($nextType !== '')
                            <x-ui.field for="call-next-at" :label="__('När')" error="next_at">
                                <input id="call-next-at" type="datetime-local" class="{{ $input }} mt-1" wire:model="nextAt">
                            </x-ui.field>
                            <x-ui.field for="call-next-subject" :label="__('Rubrik')" error="next_subject" class="sm:col-span-2">
                                <input id="call-next-subject" type="text" class="{{ $input }} mt-1" wire:model="nextSubject" placeholder="{{ __('Till exempel: skicka offert') }}">
                            </x-ui.field>
                        @endif
                    </div>

                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Hur gick samtalet? Ett tryck loggar samtalet och tar fram nästa företag.') }}</legend>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach($outcomes as $outcome)
                                <button type="button" wire:click="call('{{ $outcome->value }}')" wire:loading.attr="disabled" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-white px-3 text-sm font-semibold text-ink ring-1 ring-stone-300 hover:bg-stone-50 focus-visible:ring-2 focus-visible:ring-accent-500">{{ $outcome->label() }}</button>
                            @endforeach
                        </div>
                        <x-input-error for="outcome" class="mt-2" />
                        <x-input-error for="item" class="mt-2" />
                        <x-input-error for="person_id" class="mt-2" />
                    </fieldset>
                    <button type="button" class="mt-4 min-h-11 text-sm font-medium text-gray-600 underline" wire:click="skip">{{ __('Hoppa över utan att ringa') }}</button>
                @endif
            </x-ui.card>

            <aside class="space-y-6" aria-label="{{ __('Om företaget') }}">
                <x-ui.card :title="__('Senast')">
                    @if($recent->isEmpty())
                        <p class="text-sm text-gray-600">{{ __('Ingen kontakt loggad ännu.') }}</p>
                    @else
                        <ul class="space-y-3 text-sm">
                            @foreach($recent as $activity)
                                <li wire:key="recent-{{ $activity->id }}">
                                    <span class="block font-medium text-ink">{{ $activity->subject }}</span>
                                    <span class="block text-gray-600">{{ $activity->done_at?->setTimezone($zone)->translatedFormat('j M Y H:i') }}@if($activity->person) · {{ $activity->person->name }}@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
                <x-crm::registry-facts :company="$register" />
            </aside>
        </div>
    @endif

    <x-ui.card :title="__('Hela listan')">
        <ol class="divide-y divide-gray-100 text-sm" data-testid="call-list-items">
            @foreach($items as $item)
                <li wire:key="item-{{ $item->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <a href="{{ route('crm.accounts.show', $item->account_id) }}" class="font-medium text-ink hover:text-accent-700">{{ $item->account?->name }}</a>
                    <span class="text-gray-600">
                        @if($item->status === App\Modules\Crm\Models\CallListItem::PENDING)
                            {{ $item->is($current) ? __('Nu') : __('Väntar') }}@if($item->outcome) · {{ $item->outcome->label() }}@endif
                        @elseif($item->status === App\Modules\Crm\Models\CallListItem::SKIPPED)
                            {{ __('Överhoppad') }}
                        @else
                            {{ $item->outcome?->label() ?? __('Klar') }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    </x-ui.card>
</div>
