@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $zone = 'Europe/Stockholm';
    $address = collect([$account->address_line1, trim(($account->postal_code ?? '').' '.($account->city ?? ''))])->filter()->implode(', ');
    $tel = $account->phone ? 'tel:'.preg_replace('/[^\d+]/', '', $account->phone) : null;
    $readOnly = $canManage ? null : __('Du kan inte ändra i CRM.');
    // The decision maker first, then by name.
    $people = $account->people->sortBy(fn ($person) => ($person->link->role === App\Modules\Crm\Domain\PersonRole::DecisionMaker ? '0' : '1').$person->name)->values();
    $customerSince = $firstInvoice ?? $account->customer?->created_at?->toDateString();
@endphp

<div class="space-y-6">
    <x-crm::header :name="$account->name" :shape="$account->kind === App\Modules\Crm\Domain\AccountKind::Person ? 'round' : 'square'" :kicker="collect([$account->kind->label(), $account->customer ? __('Kund :number', ['number' => $account->customer->number]) : null, $account->org_number])->filter()->implode(' · ')">
        <x-slot:subtitle>
            @if($account->city)<span class="inline-flex items-center gap-1.5"><x-crm::icon name="pin" class="size-4 text-gray-500" />{{ $account->city }}</span>@endif
            @if($account->phone)<a href="{{ $tel }}" class="inline-flex items-center gap-1.5 font-medium text-ink tabular-nums hover:text-accent-700"><x-crm::icon name="phone" class="size-4 text-gray-500" />{{ $account->phone }}</a>@endif
            @if($account->email)<a href="mailto:{{ $account->email }}" class="inline-flex items-center gap-1.5 break-all font-medium text-ink hover:text-accent-700"><x-crm::icon name="mail" class="size-4 text-gray-500" />{{ $account->email }}</a>@endif
            @if($account->website)<a href="{{ str_starts_with($account->website, 'http') ? $account->website : 'https://'.$account->website }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-medium text-ink hover:text-accent-700"><x-crm::icon name="globe" class="size-4 text-gray-500" />{{ $account->website }}</a>@endif
            @if($address !== '')<a href="https://www.openstreetmap.org/search?query={{ urlencode($address) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 hover:text-accent-700">{{ __('Karta') }}: {{ $address }}</a>@endif
        </x-slot:subtitle>
        <x-slot:badges>
            <x-ui.badge :tone="$account->status->tone()">{{ $account->status->label() }}</x-ui.badge>
            @foreach($account->tags as $tag)
                <a href="{{ route('invoicing.customers', ['tagg' => $tag]) }}" class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-gray-700 ring-1 ring-stone-200 hover:bg-stone-200">{{ $tag }}</a>
            @endforeach
        </x-slot:badges>
        @if($canManage)
            <x-slot:aside>
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startStatus">{{ __('Ändra status') }}</button>
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startEdit">{{ __('Ändra uppgifter') }}</button>
            </x-slot:aside>
        @endif
        <x-slot:actions>
            <x-crm::quick-action icon="phone" :label="__('Ring')" :href="$tel" :disabled="$tel ? null : __('Kunden har inget telefonnummer.')" primary />
            <x-crm::quick-action icon="mail" :label="__('Mejla')" :href="$account->email ? 'mailto:'.$account->email : null" :disabled="$account->email ? null : __('Kunden har ingen e-postadress.')" />
            @if($canManage)
                <x-crm::quick-action icon="CALL" :label="__('Logga samtal')" wire:click="startActivity('CALL')" />
                <x-crm::quick-action icon="NOTE" :label="__('Anteckning')" wire:click="startActivity('NOTE')" />
                <x-crm::quick-action icon="MEETING" :label="__('Planera möte')" wire:click="startActivity('MEETING')" />
                <x-crm::quick-action icon="TASK" :label="__('Uppgift')" wire:click="startActivity('TASK')" />
            @endif
            @if($canDeal)
                <x-crm::quick-action icon="deal" :label="__('Ny affär')" :href="route('crm.deals', ['ny' => $account->id])" />
            @endif
            @if($canConvert)
                <x-crm::quick-action icon="person" :label="__('Gör till kund')" wire:click="convert" wire:confirm="{{ __('Göra :name till kund? Kunden får nästa kundnummer och kan faktureras.', ['name' => $account->name]) }}" />
            @endif
            @if($canSell)
                <x-crm::quick-action icon="quote" :label="__('Ny offert')" :href="route('invoicing.quotes.create', ['kund' => $account->customer_id])" />
                <x-crm::quick-action icon="order" :label="__('Ny order')" :href="route('invoicing.orders.create', ['kund' => $account->customer_id])" />
                <x-crm::quick-action icon="invoice" :label="__('Ny faktura')" :href="route('invoicing.invoices.create', ['kund' => $account->customer_id])" />
            @endif
        </x-slot:actions>
        <x-slot:actionsNote>{{ collect([$tel ? null : __('Kunden har inget telefonnummer.'), $account->email ? null : __('Kunden har ingen e-postadress.')])->filter()->implode(' ') }}</x-slot:actionsNote>
        <x-slot:facts>
            <x-crm::fact :label="__('Kundnummer')"><span class="tabular-nums">{{ $account->customer?->number ?? '–' }}</span></x-crm::fact>
            <x-crm::fact :label="__('Org.nr')"><span class="tabular-nums">{{ $account->org_number ?: '–' }}</span></x-crm::fact>
            <x-crm::fact :label="__('Ansvarig')">{{ $account->owner?->name ?? __('ingen') }}</x-crm::fact>
            <x-crm::fact :label="__('Källa')">{{ $account->source ?: '–' }}</x-crm::fact>
            <x-crm::fact :label="__('Bransch (SNI)')"><span class="tabular-nums">{{ $account->sni_code ?: '–' }}</span></x-crm::fact>
            <x-crm::fact :label="__('Koncern')">
                @if($account->parent)<a href="{{ route('crm.accounts.show', $account->parent) }}" class="text-accent-600 hover:text-accent-700">{{ __('Del av') }} {{ $account->parent->name }}</a>@else–@endif
            </x-crm::fact>
            <x-crm::fact :label="__('Kund sedan')">{{ $customerSince ? \Carbon\CarbonImmutable::parse($customerSince)->translatedFormat('j M Y') : '–' }}</x-crm::fact>
            <x-crm::fact :label="__('Senaste kontakt')">{{ $lastContact ? \Carbon\CarbonImmutable::parse($lastContact)->setTimezone($zone)->translatedFormat('j M Y') : '–' }}</x-crm::fact>
        </x-slot:facts>
    </x-crm::header>

    @if($account->status === App\Modules\Crm\Domain\AccountStatus::Lost)
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-2xl bg-stone-50 px-4 py-3 text-sm ring-1 ring-stone-200">
            @if($account->lost_reason)<span><span class="font-medium text-ink">{{ __('Förlorad') }}:</span> {{ $account->lost_reason }}</span>@endif
            <span>{{ $account->win_back_at ? __('Vinn tillbaka: :date', ['date' => $account->win_back_at->translatedFormat('j F Y')]) : __('Ingen påminnelse om att vinna tillbaka kunden.') }}</span>
            @if($canManage)
                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startWinBack">{{ __('Ändra datum') }}</button>
            @endif
        </div>
    @endif

    {{-- The open panel. --}}
    @include('crm::livewire.partials.activity-panels', ['personOptions' => $account->people, 'accountOptions' => null])
    @if($panel === 'winback')
        <x-ui.card :title="__('Vinn tillbaka')" :description="__('Den dagen får den ansvarige en uppgift att höra av sig till kunden igen.')">
            <form wire:submit="saveWinBack" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="winback_date" :label="__('Datum')" error="win_back_at" :hint="__('Lämna tomt om du inte vill ha någon påminnelse.')">
                    <input id="winback_date" type="date" class="{{ $input }} mt-1" wire:model="winBackAt">
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'status')
        <x-ui.card :title="__('Ändra status')" :description="__('En status du sätter själv gäller tills nästa faktura. Då tar reglerna över igen.')">
            <form wire:submit="saveStatus" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="status_new" :label="__('Status')" error="status">
                    <select id="status_new" class="{{ $input }} mt-1" wire:model.live="newStatus">
                        @foreach($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($newStatus === 'LOST')
                    <x-ui.field for="status_reason" :label="__('Varför förlorad?')" error="reason" class="sm:col-span-2">
                        <input id="status_reason" type="text" class="{{ $input }} mt-1" wire:model="lostReason" placeholder="{{ __('Till exempel: valde en annan leverantör, för dyrt') }}">
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'edit')
        <x-ui.card :title="__('Ändra uppgifter')" :description="$sharedLocked ? __('Namn, nummer och adress ändras i kundregistret av den som får ändra kunder. Här ändrar du det som hör till säljarbetet.') : ($account->customer ? __('Namn, nummer och adress sparas även i kundregistret, så de stämmer på nästa faktura.') : null)">
            <form wire:submit="saveEdit" class="grid gap-4 sm:grid-cols-2">
                @foreach(['name' => __('Namn'), 'org_number' => __('Organisationsnummer'), 'email' => __('E-post'), 'phone' => __('Telefon'), 'address_line1' => __('Adress'), 'postal_code' => __('Postnummer'), 'city' => __('Ort'), 'country' => __('Land (landskod)')] as $field => $label)
                    <x-ui.field :for="'edit_'.$field" :label="$label" :error="$field" @class(['sm:col-span-2' => $field === 'name'])>
                        <input id="edit_{{ $field }}" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" class="{{ $input }} mt-1 disabled:bg-stone-50 disabled:text-gray-500" wire:model="edit.{{ $field }}" @disabled($sharedLocked)>
                    </x-ui.field>
                @endforeach
                <x-ui.field for="edit_kind" :label="__('Typ')" error="kind">
                    <select id="edit_kind" class="{{ $input }} mt-1" wire:model="edit.kind">
                        @foreach($kinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="edit_owner" :label="__('Ansvarig')" error="owner_id">
                    <select id="edit_owner" class="{{ $input }} mt-1" wire:model="edit.owner_id">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="edit_parent" :label="__('Del av koncern (moderbolag)')" error="parent_id">
                    <select id="edit_parent" class="{{ $input }} mt-1" wire:model="edit.parent_id">
                        <option value="">{{ __('Inget') }}</option>
                        @foreach($parents as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="edit_sni" :label="__('SNI-kod (bransch)')" error="sni_code">
                    <input id="edit_sni" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="edit.sni_code">
                </x-ui.field>
                <x-ui.field for="edit_website" :label="__('Webbplats')" error="website">
                    <input id="edit_website" type="text" class="{{ $input }} mt-1" wire:model="edit.website">
                </x-ui.field>
                <x-ui.field for="edit_source" :label="__('Källa')" error="source">
                    <input id="edit_source" type="text" class="{{ $input }} mt-1" wire:model="edit.source">
                </x-ui.field>
                <x-ui.field for="edit_tags" :label="__('Taggar')" error="tags" :hint="__('Skilj taggarna med kommatecken.')" class="sm:col-span-2">
                    <input id="edit_tags" type="text" class="{{ $input }} mt-1" wire:model="edit.tags">
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                    @if(! $account->customer_id)
                        <button type="button" class="ms-auto min-h-11 text-sm text-accent-700 underline" wire:click="eraseProspect" wire:confirm="{{ __('Radera prospektet enligt GDPR? Prospektet och alla dess aktiviteter tas bort och kan inte återställas. Personerna finns kvar under Personer.') }}">{{ __('Radera enligt GDPR') }}</button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @elseif($panel === 'person')
        @include('crm::livewire.partials.person-form', ['withRole' => $editingPersonId === null])
    @elseif($panel === 'link')
        <x-ui.card :title="__('Koppla en person som redan finns')">
            <form wire:submit="link" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="link_person" :label="__('Person')" error="linkPersonId">
                    <select id="link_person" class="{{ $input }} mt-1" wire:model="linkPersonId">
                        <option value="">{{ __('Välj person') }}</option>
                        @foreach($otherPeople as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}@if($person->title) · {{ $person->title }}@endif</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="link_role" :label="__('Roll')" error="linkRole">
                    <select id="link_role" class="{{ $input }} mt-1" wire:model="linkRole">
                        @foreach($roles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Koppla') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Where the customer stands: the status path, or an open deal's pipeline. --}}
    <section class="rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6" aria-labelledby="crm-path-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500">{{ $stepperDeal ? __('Affär') : __('Kundens status') }}</p>
                <h2 id="crm-path-title" class="mt-0.5 text-lg font-bold tracking-tight text-ink">
                    @if($stepperDeal)
                        <a href="{{ route('crm.deals.show', $stepperDeal) }}" class="hover:text-accent-700">{{ $stepperDeal->title }}</a>
                    @else
                        {{ $account->status->label() }}
                    @endif
                </h2>
                @if($stepperDeal)
                    <p class="mt-1 flex flex-wrap gap-x-3 text-sm text-gray-600">
                        <x-ui.amount :money="$money($stepperDeal->value_ore)" class="font-semibold text-ink" />
                        @if($stepperDeal->expected_close_date)<span>{{ __('Väntat avslut :date', ['date' => $stepperDeal->expected_close_date->translatedFormat('j M Y')]) }}</span>@endif
                    </p>
                @endif
            </div>
            @if($openDeals->isNotEmpty())
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <span>{{ __('Visa') }}</span>
                    <select class="min-h-11 max-w-[14rem] rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500 sm:min-h-9" wire:model.live="path">
                        <option value="">{{ __('Kundens status') }}</option>
                        @foreach($openDeals as $deal)
                            <option value="{{ $deal->id }}">{{ __('Affär') }}: {{ $deal->title }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
        <x-crm::stepper class="mt-4" :steps="$steps" :label="$stepperDeal ? __('Steg i säljtratten') : __('Kundens status')" />
    </section>

    {{-- Key figures from the ledger, first-class. --}}
    <x-crm::figures :figures="$figures" :limited="$limitedLedger" :is-customer="$account->customer !== null" />

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="min-w-0 rounded-3xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6 lg:col-span-2">
            <x-crm::tabs :tabs="$tabs" :active="$tab" model="tab" id="crm-account" :label="__('Historik')">
                @if($tab === 'affarer')
                    <x-crm::deal-list :deals="$deals" :now="$now" :empty="__('Inga affärer ännu. En affär följer en försäljning genom säljtratten, med värde och väntat avslut.')" />
                @elseif($tab === 'personer')
                    @include('crm::livewire.partials.account-people')
                @elseif($timeline === null || $timeline->isEmpty())
                    <x-ui.empty-state :title="__('Inget här ännu')">{{ __('Inget har hänt ännu. Aktiviteter, offerter, order, fakturor, betalningar och påminnelser visas här.') }}</x-ui.empty-state>
                @else
                    <ol class="space-y-3" data-testid="crm-timeline">
                        @foreach($timeline as $entry)
                            <li wire:key="t-{{ $entry->kind }}-{{ $entry->ref }}-{{ $loop->index }}">
                                <x-crm::activity-card :entry="$entry" :can-manage="$canManage" :can-see-invoices="$canSeeInvoices" :limited-ledger="$limitedLedger" :now="$now" />
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-4">{{ $timeline->links() }}</div>
                @endif
            </x-crm::tabs>
        </section>

        <aside class="space-y-6" aria-label="{{ __('Om kunden') }}">
            <x-ui.card :title="__('Kommande')">
                @if($next->isEmpty())
                    <p class="text-sm text-gray-600">{{ $account->status->isOpen() ? __('Inget planerat. Lägg till ett samtal, ett möte eller en uppgift, så glöms kunden inte bort.') : __('Inget planerat.') }}</p>
                @else
                    <ul class="space-y-3">
                        @foreach($next as $open)
                            @php $late = $open->isOverdue($now); @endphp
                            <li wire:key="next-{{ $open->id }}" class="flex gap-3">
                                <span class="inline-flex size-9 flex-none items-center justify-center rounded-xl bg-stone-100 text-ink ring-1 ring-stone-200"><x-crm::icon :name="$open->type->value" class="size-4" /></span>
                                <span class="min-w-0 flex-1 text-sm">
                                    <span class="block font-medium text-ink">{{ $open->subject }}</span>
                                    <span @class(['block', 'font-medium text-accent-700' => $late, 'text-gray-600' => ! $late])>
                                        {{ $open->due_at?->setTimezone($zone)->translatedFormat('j M H:i') ?? __('Utan datum') }}@if($late) · {{ __('försenad') }}@endif
                                        @if($open->person) · {{ $open->person->name }}@endif
                                    </span>
                                    @if($open->deal)
                                        <a href="{{ route('crm.deals.show', $open->deal) }}" class="block text-accent-600 hover:text-accent-700">{{ __('Affär') }}: {{ $open->deal->title }}</a>
                                    @endif
                                    @if($canManage)
                                        <span class="mt-1 flex gap-3">
                                            <button type="button" class="min-h-11 font-semibold text-ink underline decoration-stone-300 underline-offset-4 hover:decoration-ink sm:min-h-0" wire:click="startCompletion({{ $open->id }})">{{ __('Klar') }}</button>
                                            <button type="button" class="min-h-11 text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="editActivity({{ $open->id }})">{{ __('Ändra') }}</button>
                                        </span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Affärer')">
                <x-slot:action>
                    @if($canDeal)
                        <a href="{{ route('crm.deals', ['ny' => $account->id]) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Ny affär') }}</a>
                    @endif
                </x-slot:action>
                <x-crm::deal-list :deals="$deals" :now="$now" :empty="__('Inga affärer ännu. En affär följer en försäljning genom säljtratten, med värde och väntat avslut.')" data-testid="crm-account-deals" />
            </x-ui.card>

            <x-ui.card :title="__('Personer')">
                @if($people->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Inga kontaktpersoner ännu.') }}</p>
                @else
                    <ul class="space-y-3">
                        @foreach($people as $person)
                            <li wire:key="side-person-{{ $person->id }}" class="flex items-center gap-3">
                                <x-crm::avatar :name="$person->name" size="sm" />
                                <span class="min-w-0 flex-1 text-sm">
                                    <a href="{{ route('crm.people.show', $person) }}" class="block truncate font-medium text-ink hover:text-accent-700">{{ $person->name }}</a>
                                    <span class="block truncate text-gray-600">{{ $person->link->role->label() }}@if($person->title) · {{ $person->title }}@endif</span>
                                </span>
                                @if($person->doNotContact())
                                    <x-ui.badge tone="accent">{{ __('Kontakta inte') }}</x-ui.badge>
                                @elseif($person->callNumber())
                                    <a href="tel:{{ preg_replace('/[^\d+]/', '', (string) $person->callNumber()) }}" class="inline-flex size-11 flex-none items-center justify-center rounded-full bg-stone-100 text-ink ring-1 ring-stone-200 hover:bg-white sm:size-9" aria-label="{{ __('Ring :name', ['name' => $person->name]) }}" title="{{ $person->callNumber() }}"><x-crm::icon name="phone" class="size-4" /></a>
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
            </x-ui.card>

            @if($canExport || $account->customer?->isRestricted())
                <x-ui.card :title="__('Personuppgifter')">
                    @if($account->customer?->isRestricted())
                        <p class="text-sm text-accent-800">{{ __('Behandlingen av kundens uppgifter är begränsad. Inga nya offerter, order eller fakturor.') }}</p>
                    @endif
                    @if($canExport)
                        <p class="text-sm"><a href="{{ route('privacy.export.customer', $account->customer_id) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Ladda ner registerutdrag (JSON)') }}</a></p>
                    @endif
                </x-ui.card>
            @endif
        </aside>
    </div>

    <x-crm::action-bar>
        <x-crm::quick-action icon="phone" :label="__('Ring')" :href="$tel" :disabled="$tel ? null : __('Kunden har inget telefonnummer.')" primary />
        <x-crm::quick-action icon="mail" :label="__('Mejla')" :href="$account->email ? 'mailto:'.$account->email : null" :disabled="$account->email ? null : __('Kunden har ingen e-postadress.')" />
        <x-crm::quick-action icon="CALL" :label="__('Logga')" :disabled="$readOnly" wire:click="startActivity('CALL')" />
        <x-crm::quick-action icon="MEETING" :label="__('Planera')" :disabled="$readOnly" wire:click="startActivity('MEETING')" />
    </x-crm::action-bar>

    {{-- The ledger fields, through the customer register's own form. --}}
    @if($canEditCustomer)
        <livewire:crm.customer-fields :customer-id="$account->customer_id" :key="'customer-fields-'.$account->customer_id" />
    @endif
</div>
