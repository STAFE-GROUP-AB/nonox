@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $zone = 'Europe/Stockholm';
    $number = $person->callNumber();
    $tel = $number ? 'tel:'.preg_replace('/[^\d+]/', '', $number) : null;
    $city = $primary?->city;
    // Why calling, mailing and planning are not offered, if they are not.
    $readOnly = ! $canUpdate ? __('Du kan inte ändra i CRM.') : null;
    $contactOff = $contactBlock ?? $noAccount ?? $readOnly;
    // After an objection notes may still be written; under a restriction nothing new (stage 1).
    $noteOff = $noAccount ?? $readOnly ?? ($restricted ? $contactBlock : null);
    $mailOff = $contactBlock ?? ($person->email ? null : __('Personen har ingen e-postadress.'));
    $callOff = $contactBlock ?? ($tel ? null : __('Personen har inget telefonnummer.'));
@endphp

<div class="space-y-6">
    @if($contactBlock)
        <div class="flex items-start gap-3 rounded-2xl bg-accent-50 px-4 py-3 text-sm text-accent-900 ring-1 ring-accent-200" role="status" data-testid="crm-do-not-contact">
            <x-crm::icon name="block" class="mt-0.5 size-5 flex-none" />
            <p>
                <span class="font-semibold">{{ __('Kontakta inte') }}:</span>
                {{ $restricted
                    ? __('Behandlingen av personens uppgifter är begränsad. Uppgifterna får bara sparas och rättas: inga samtal, mejl eller möten. En planerad aktivitet kan fortfarande klarmarkeras.')
                    : __('Personen har bett att inte bli kontaktad. Du kan skriva anteckningar, men inte ringa, mejla eller planera möten.') }}
            </p>
        </div>
    @endif

    <x-crm::header :name="$person->name" shape="round" :kicker="collect([$person->title, $primary?->name])->filter()->implode(' · ') ?: __('Kontaktperson')">
        <x-slot:subtitle>
            @foreach($accounts as $account)
                <a href="{{ route('crm.accounts.show', $account) }}" class="inline-flex items-center gap-1.5 font-medium text-accent-600 hover:text-accent-700"><x-crm::icon name="building" class="size-4" />{{ $account->name }}</a>
            @endforeach
            @if($city)<span class="inline-flex items-center gap-1.5"><x-crm::icon name="pin" class="size-4 text-gray-500" />{{ $city }}</span>@endif
            @if($number && ! $contactBlock)<a href="{{ $tel }}" class="inline-flex items-center gap-1.5 font-medium text-ink tabular-nums hover:text-accent-700"><x-crm::icon name="phone" class="size-4 text-gray-500" />{{ $number }}</a>@endif
        </x-slot:subtitle>
        <x-slot:badges>
            @foreach($accounts as $account)
                <x-ui.badge tone="neutral">{{ $account->people->first()?->link->role->label() }}@if($accounts->count() > 1) · {{ $account->name }}@endif</x-ui.badge>
            @endforeach
            @if($person->doNotContact())<x-ui.badge tone="accent">{{ __('Kontakta inte') }}</x-ui.badge>@endif
            @if($person->marketing_opt_out_at)<x-ui.badge>{{ __('Inga utskick') }}</x-ui.badge>@endif
            @if($person->email_bounced_at)<x-ui.badge>{{ __('E-postadressen studsar') }}</x-ui.badge>@endif
            @if(! $person->is_active)<x-ui.badge>{{ __('Inaktiv') }}</x-ui.badge>@endif
        </x-slot:badges>
        {{-- Also under a restriction: the data may be corrected (GDPR art. 16 and 18). --}}
        @if($canUpdate)
            <x-slot:aside>
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startPerson({{ $person->id }})">{{ __('Ändra uppgifter') }}</button>
            </x-slot:aside>
        @endif
        <x-slot:actions>
            <x-crm::quick-action icon="phone" :label="__('Ring')" :href="$callOff ? null : $tel" :disabled="$callOff" primary />
            <x-crm::quick-action icon="mail" :label="__('Mejla')" :href="$mailOff ? null : 'mailto:'.$person->email" :disabled="$mailOff" />
            <x-crm::quick-action icon="CALL" :label="__('Logga samtal')" :disabled="$contactOff" wire:click="startActivity('CALL')" />
            <x-crm::quick-action icon="NOTE" :label="__('Anteckning')" :disabled="$noteOff" wire:click="startActivity('NOTE')" />
            <x-crm::quick-action icon="MEETING" :label="__('Planera möte')" :disabled="$contactOff" wire:click="startActivity('MEETING')" />
            <x-crm::quick-action icon="TASK" :label="__('Uppgift')" :disabled="$contactOff" wire:click="startActivity('TASK')" />
            <x-crm::quick-action icon="EMAIL" :label="__('Mejla med mall')" :disabled="$contactOff ?? $mailOff" wire:click="startPersonMail" />
        </x-slot:actions>
        <x-slot:actionsNote>{{ collect([$contactOff, $callOff, $mailOff, $noteOff])->filter()->unique()->implode(' ') }}</x-slot:actionsNote>
        <x-slot:facts>
            <x-crm::fact :label="__('Titel')">{{ $person->title ?: '–' }}</x-crm::fact>
            <x-crm::fact :label="__('Företag')" wide>
                @forelse($accounts as $account)
                    <span class="block"><a href="{{ route('crm.accounts.show', $account) }}" class="text-accent-600 hover:text-accent-700">{{ $account->name }}</a> <span class="font-normal text-gray-500">· {{ $account->people->first()?->link->role->label() }}</span></span>
                @empty
                    –
                @endforelse
            </x-crm::fact>
            <x-crm::fact :label="__('E-post')" wide>
                @if($person->email && ! $contactBlock)<a href="mailto:{{ $person->email }}" class="break-all text-accent-600 hover:text-accent-700">{{ $person->email }}</a>@else{{ $person->email ?: '–' }}@endif
            </x-crm::fact>
            <x-crm::fact :label="__('Telefon / mobil')">
                <span class="tabular-nums">{{ collect([$person->phone, $person->mobile])->filter()->implode(' · ') ?: '–' }}</span>
            </x-crm::fact>
            <x-crm::fact :label="__('Ansvarig')">{{ $primary?->owner?->name ?? '–' }}</x-crm::fact>
            <x-crm::fact :label="__('Senaste kontakt')">{{ $lastContact ? \Carbon\CarbonImmutable::parse($lastContact)->setTimezone($zone)->translatedFormat('j M Y') : '–' }}</x-crm::fact>
            <x-crm::fact :label="__('Skapad')">{{ $person->created_at?->setTimezone($zone)->translatedFormat('j M Y') }}</x-crm::fact>
            <x-crm::fact :label="__('Rättslig grund')">
                {{ $person->legal_basis->label() }}
                @if($person->consent_at)<span class="block font-normal text-gray-500">{{ __('Samtycke :date', ['date' => $person->consent_at->translatedFormat('j M Y')]) }}</span>@endif
                @if($person->informed_at)<span class="block font-normal text-gray-500">{{ __('Informerad :date', ['date' => $person->informed_at->translatedFormat('j M Y')]) }}</span>@endif
            </x-crm::fact>
        </x-slot:facts>
    </x-crm::header>

    {{-- The open panel. --}}
    @include('crm::livewire.partials.activity-panels', ['personOptions' => collect(), 'accountOptions' => $activityAccounts])
    @if($panel === 'mall')
        @include('crm::livewire.partials.template-mail', ['privatePerson' => $primary?->kind === App\Modules\Crm\Domain\AccountKind::Person])
    @endif
    @if($panel === 'person')
        @include('crm::livewire.partials.person-form', ['withRole' => false])
    @elseif($panel === 'kontakta')
        <x-ui.card :title="__('Märk Kontakta inte')" :description="__('Personen kontaktas inte från NONOX: inga samtal, mejl eller möten. Vid en invändning sparas e-post och telefon på spärrlistan om personen raderas.')">
            <form wire:submit="saveDoNotContact" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="dnc_reason" :label="__('Varför')" error="do_not_contact_reason">
                    <select id="dnc_reason" class="{{ $input }} mt-1" wire:model="doNotContactReason">
                        @foreach($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Märk Kontakta inte') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Where the business stands: the deal's pipeline, or the company's status. --}}
    <section class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6" aria-labelledby="crm-path-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500">{{ $currentDeal ? __('Affär') : __('Kundens status') }}</p>
                <h2 id="crm-path-title" class="mt-0.5 text-lg font-bold tracking-tight text-ink">
                    @if($currentDeal)
                        <a href="{{ route('crm.deals.show', $currentDeal) }}" class="hover:text-accent-700">{{ $currentDeal->title }}</a>
                    @else
                        {{ $primary?->name ?? __('Inget företag') }}
                    @endif
                </h2>
                @if($currentDeal)
                    @php $nextDue = $currentDeal->next_due_at ? \Carbon\CarbonImmutable::parse($currentDeal->next_due_at) : null; @endphp
                    <p class="mt-1 flex flex-wrap gap-x-3 text-sm text-gray-600">
                        <x-ui.amount :money="$money($currentDeal->value_ore)" class="font-semibold text-ink" />
                        @if($currentDeal->expected_close_date)<span>{{ __('Väntat avslut :date', ['date' => $currentDeal->expected_close_date->translatedFormat('j M Y')]) }}</span>@endif
                        <span @class(['font-medium text-accent-700' => ! $nextDue || $nextDue->lessThan($now)])>{{ $nextDue ? __('Nästa steg :date', ['date' => $nextDue->setTimezone($zone)->translatedFormat('j M')]) : __('Nästa steg saknas') }}</span>
                    </p>
                @endif
            </div>
            @if($openDeals->count() > 1)
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <span>{{ __('Visa affär') }}</span>
                    <select class="min-h-11 rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500 sm:min-h-9" wire:model.live="dealId">
                        @foreach($openDeals as $deal)
                            <option value="{{ $deal->id }}">{{ $deal->title }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
        <x-crm::stepper class="mt-4" :steps="$steps" :label="$currentDeal ? __('Steg i säljtratten') : __('Kundens status')" />
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="min-w-0 rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6 lg:col-span-2">
            <x-crm::tabs :tabs="$tabs" :active="$tab" model="tab" id="crm-person" :label="__('Historik')">
                @if($tab === 'affarer')
                    <x-crm::deal-list :deals="$deals" :now="$now" :empty="__('Personen är inte kontakt på någon affär ännu.')" />
                @elseif($entries->isEmpty())
                    <x-ui.empty-state :title="__('Inget här ännu')">{{ __('Samtal, möten, anteckningar och uppgifter om personen samlas här.') }}</x-ui.empty-state>
                @else
                    <ol class="space-y-3" data-testid="crm-person-timeline">
                        @foreach($entries as $entry)
                            <li wire:key="entry-{{ $entry->kind }}-{{ $entry->ref }}">
                                <x-crm::activity-card :entry="$entry" :can-manage="$canUpdate" :can-edit="$canManage" :now="$now" show-account :show-person="false" />
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-4">{{ $entries->links() }}</div>
                @endif
            </x-crm::tabs>
        </section>

        <aside class="space-y-6" aria-label="{{ __('Om personen') }}">
            <x-ui.card :title="__('Kommande')">
                @if($upcoming->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Inget planerat.') }}</p>
                @else
                    <ul class="space-y-3">
                        @foreach($upcoming as $step)
                            @php $late = $step->isOverdue($now); @endphp
                            <li wire:key="upcoming-{{ $step->id }}" class="flex gap-3">
                                <span class="inline-flex size-9 flex-none items-center justify-center rounded-xl bg-stone-100 text-ink ring-1 ring-stone-200"><x-crm::icon :name="$step->type->value" class="size-4" /></span>
                                <span class="min-w-0 text-sm">
                                    <span class="block font-medium text-ink">{{ $step->subject }}</span>
                                    <span @class(['block', 'font-medium text-accent-700' => $late, 'text-gray-600' => ! $late])>{{ $step->due_at?->setTimezone($zone)->translatedFormat('j M H:i') ?? __('Utan datum') }}@if($late) · {{ __('försenad') }}@endif</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Affärer')">
                <x-crm::deal-list :deals="$deals" :now="$now" :empty="__('Personen är inte kontakt på någon affär ännu.')" />
            </x-ui.card>

            @if($primary)
                <x-crm::figures :figures="$figures" :limited="$limitedLedger" :is-customer="$primary->customer_id !== null" compact :title="__('Kunden i siffror')">
                    <x-slot:action><a href="{{ route('crm.accounts.show', $primary) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ $primary->name }}</a></x-slot:action>
                </x-crm::figures>
            @endif

            <x-ui.card :title="__('Personuppgifter')">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-500">{{ __('Rättslig grund') }}</dt><dd class="text-right font-medium text-ink">{{ $person->legal_basis->label() }}</dd></div>
                    @if($person->consent_at)<div class="flex items-baseline justify-between gap-3"><dt class="text-gray-500">{{ __('Samtycke') }}</dt><dd class="font-medium text-ink">{{ $person->consent_at->translatedFormat('j M Y') }}</dd></div>@endif
                    <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-500">{{ __('Informerad') }}</dt><dd class="font-medium text-ink">{{ $person->informed_at?->translatedFormat('j M Y') ?? __('Inte angivet') }}</dd></div>
                    <div class="flex items-baseline justify-between gap-3"><dt class="text-gray-500">{{ __('Kontakta inte') }}</dt><dd @class(['font-medium', 'text-accent-700' => $person->doNotContact(), 'text-ink' => ! $person->doNotContact()])>{{ $person->do_not_contact_reason?->label() ?? __('Nej') }}</dd></div>
                </dl>
                @if($person->legal_basis_note)
                    <p class="mt-3 text-sm text-gray-600">{{ $person->legal_basis_note }}</p>
                @endif
                @if($canUpdate)
                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <a href="{{ route('privacy.export.crmPerson', $person) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Registerutdrag (JSON)') }}</a>
                        @if($person->do_not_contact_reason === App\Modules\Crm\Domain\DoNotContact::Restriction)
                            <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="saveDoNotContact(false, true)" wire:confirm="{{ __('Häva begränsningen? Gör det bara när skälet för begränsningen inte längre finns, och informera personen innan (GDPR artikel 18.3).') }}">{{ __('Häv begränsningen') }}</button>
                        @elseif($person->doNotContact())
                            <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="saveDoNotContact(false)" wire:confirm="{{ __('Ta bort markeringen Kontakta inte? Gör det bara om personen själv har ändrat sig.') }}">{{ __('Ta bort Kontakta inte') }}</button>
                        @else
                            <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startDoNotContact">{{ __('Märk Kontakta inte') }}</button>
                        @endif
                    </div>
                @endif
            </x-ui.card>
        </aside>
    </div>

    <x-crm::action-bar>
        <x-crm::quick-action icon="phone" :label="__('Ring')" :href="$callOff ? null : $tel" :disabled="$callOff" primary />
        <x-crm::quick-action icon="mail" :label="__('Mejla')" :href="$mailOff ? null : 'mailto:'.$person->email" :disabled="$mailOff" />
        <x-crm::quick-action icon="CALL" :label="__('Logga')" :disabled="$contactOff" wire:click="startActivity('CALL')" />
        <x-crm::quick-action icon="MEETING" :label="__('Planera')" :disabled="$contactOff" wire:click="startActivity('MEETING')" />
    </x-crm::action-bar>
</div>
