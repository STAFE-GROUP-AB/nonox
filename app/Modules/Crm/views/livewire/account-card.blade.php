@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $quick = 'inline-flex min-h-12 flex-1 basis-[45%] items-center justify-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-semibold text-ink shadow-xs ring-1 ring-stone-200 hover:ring-stone-300 sm:basis-auto sm:flex-none';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $zone = 'Europe/Stockholm';
    $address = collect([$account->address_line1, trim(($account->postal_code ?? '').' '.($account->city ?? ''))])->filter()->implode(', ');
@endphp

<div class="space-y-6">
    {{-- Header: who, status, owner and the ways to reach them. --}}
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm text-gray-500">
                    {{ $account->kind->label() }}@if($account->customer) · {{ __('Kund :number', ['number' => $account->customer->number]) }}@endif @if($account->org_number) · {{ $account->org_number }}@endif
                </p>
                <h1 class="mt-1 break-words text-2xl font-bold tracking-tight text-ink">{{ $account->name }}</h1>
                <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                    <x-ui.badge :tone="$account->status->tone()">{{ $account->status->label() }}</x-ui.badge>
                    <span>{{ __('Ansvarig') }}: {{ $account->owner?->name ?? __('ingen') }}</span>
                    @if($account->parent)
                        <span>· {{ __('Del av') }} <a href="{{ route('crm.accounts.show', $account->parent) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $account->parent->name }}</a></span>
                    @endif
                </p>
                @if($account->status === App\Modules\Crm\Domain\AccountStatus::Lost && $account->lost_reason)
                    <p class="mt-2 text-sm text-gray-600">{{ __('Förlorad') }}: {{ $account->lost_reason }}</p>
                @endif
                @if($account->tags !== [])
                    <p class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($account->tags as $tag)
                            <a href="{{ route('invoicing.customers', ['tagg' => $tag]) }}" class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-gray-700 hover:bg-stone-200">{{ $tag }}</a>
                        @endforeach
                    </p>
                @endif
            </div>
            @if($canManage)
                <div class="flex flex-wrap gap-3 text-sm">
                    <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startStatus">{{ __('Ändra status') }}</button>
                    <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="startEdit">{{ __('Ändra uppgifter') }}</button>
                </div>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap gap-2 text-sm">
            @if($account->phone)
                <a href="tel:{{ preg_replace('/[^\d+]/', '', $account->phone) }}" class="inline-flex min-h-11 items-center rounded-xl bg-stone-100 px-4 font-medium text-ink hover:bg-stone-200">{{ __('Ring') }} {{ $account->phone }}</a>
            @endif
            @if($account->email)
                <a href="mailto:{{ $account->email }}" class="inline-flex min-h-11 items-center rounded-xl bg-stone-100 px-4 font-medium text-ink hover:bg-stone-200">{{ $account->email }}</a>
            @endif
            @if($address !== '')
                <a href="https://www.openstreetmap.org/search?query={{ urlencode($address) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center rounded-xl bg-stone-100 px-4 font-medium text-ink hover:bg-stone-200">{{ __('Karta') }}: {{ $address }}</a>
            @endif
            @if($account->website)
                <a href="{{ str_starts_with($account->website, 'http') ? $account->website : 'https://'.$account->website }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center rounded-xl bg-stone-100 px-4 font-medium text-ink hover:bg-stone-200">{{ $account->website }}</a>
            @endif
        </div>
    </x-ui.card>

    {{-- Quick buttons: the phone's main way in. --}}
    @if($canManage)
        <div class="flex flex-wrap gap-3" role="group" aria-label="{{ __('Logga eller planera') }}">
            <button type="button" class="{{ $quick }}" wire:click="startActivity('CALL')">{{ __('Ring') }}</button>
            <button type="button" class="{{ $quick }}" wire:click="startActivity('MEETING')">{{ __('Möte') }}</button>
            <button type="button" class="{{ $quick }}" wire:click="startActivity('NOTE')">{{ __('Anteckning') }}</button>
            <button type="button" class="{{ $quick }}" wire:click="startActivity('TASK')">{{ __('Uppgift') }}</button>
        </div>
    @endif

    {{-- The open panel. --}}
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
                <x-ui.field for="activity_person" :label="__('Person')" error="person_id">
                    <select id="activity_person" class="{{ $input }} mt-1" wire:model="activity.person_id">
                        <option value="">{{ __('Ingen särskild') }}</option>
                        @foreach($account->people as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
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
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Klar') }}</x-button>
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

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Next step: the open activities, due first. --}}
            <x-ui.card :title="__('Nästa steg')">
                @if($next->isEmpty())
                    <p class="text-sm text-gray-600">{{ $account->status->isOpen() ? __('Inget planerat. Lägg till ett samtal, ett möte eller en uppgift, så glöms kunden inte bort.') : __('Inget planerat.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach($next as $open)
                            <li wire:key="next-{{ $open->id }}" class="flex flex-wrap items-center gap-3 py-3">
                                <span class="min-w-0 flex-1 basis-48">
                                    <span class="block font-medium text-gray-900">{{ $open->subject }}</span>
                                    <span @class(['block text-sm', 'font-medium text-accent-700' => $open->isOverdue($now), 'text-gray-600' => ! $open->isOverdue($now)])>
                                        {{ $open->type->label() }}@if($open->due_at) · {{ $open->due_at->setTimezone($zone)->translatedFormat('j M Y H:i') }}@if($open->isOverdue($now)) · {{ __('försenad') }}@endif @endif
                                        @if($open->person) · {{ $open->person->name }}@endif
                                        @if($open->assignee) · {{ $open->assignee->name }}@endif
                                    </span>
                                </span>
                                @if($canManage)
                                    <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-black" wire:click="startCompletion({{ $open->id }})">{{ __('Klar') }}</button>
                                    <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="editActivity({{ $open->id }})">{{ __('Ändra') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Timeline: activities and the ledger, newest first. --}}
            <x-ui.card :title="__('Tidslinje')">
                @if($timeline->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Inget har hänt ännu. Aktiviteter, offerter, order, fakturor, betalningar och påminnelser visas här.') }}</p>
                @else
                    <ol class="space-y-4" data-testid="crm-timeline">
                        @foreach($timeline as $entry)
                            @php
                                $at = \Carbon\CarbonImmutable::parse($entry->at)->setTimezone($zone);
                                $label = match ($entry->kind) {
                                    'activity' => App\Modules\Crm\Domain\ActivityType::from($entry->status)->label(),
                                    'quote' => __('Offert :number', ['number' => $entry->number]),
                                    'order' => __('Order :number', ['number' => $entry->number]),
                                    'invoice' => __('Faktura :number', ['number' => $entry->number]),
                                    'credit' => __('Kreditfaktura :number', ['number' => $entry->number]),
                                    'payment' => __('Betalning av faktura :number', ['number' => $entry->number]),
                                    'reminder' => __('Påminnelse :sequence för faktura :number', ['sequence' => $entry->status, 'number' => $entry->number]),
                                    default => '',
                                };
                                // Links into the ledger only for those who may open it; others would get 403.
                                $url = ! $canSeeInvoices ? null : match ($entry->kind) {
                                    'quote' => route('invoicing.quotes.show', $entry->ref),
                                    'order' => route('invoicing.orders.show', $entry->ref),
                                    'invoice', 'credit', 'payment', 'reminder' => route('invoicing.invoices.show', $entry->ref),
                                    default => null,
                                };
                            @endphp
                            <li wire:key="t-{{ $entry->kind }}-{{ $entry->ref }}-{{ $loop->index }}" class="flex gap-3">
                                <span @class(['mt-1.5 size-2.5 flex-none rounded-full', 'bg-accent-500' => $entry->kind === 'activity', 'bg-stone-400' => $entry->kind !== 'activity'])></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="font-medium text-gray-900">
                                            @if($url)<a href="{{ $url }}" class="hover:text-accent-700">{{ $label }}</a>@else{{ $label }}@endif
                                            @if($entry->kind === 'activity' && $entry->subject !== $label) · {{ $entry->subject }}@endif
                                        </span>
                                        <span class="text-sm text-gray-500">{{ $entry->kind === 'activity' ? $at->translatedFormat('j M Y H:i') : $at->translatedFormat('j M Y') }}</span>
                                    </span>
                                    <span class="block text-sm text-gray-600">
                                        @if($entry->kind === 'activity')
                                            {{ collect([
                                                $entry->done_at === null ? ($entry->due_at ? __('Planerad till :time', ['time' => \Carbon\CarbonImmutable::parse($entry->due_at)->setTimezone($zone)->translatedFormat('j M H:i')]) : __('Inte gjord än')) : null,
                                                $entry->outcome ? App\Modules\Crm\Domain\CallOutcome::from($entry->outcome)->label() : null,
                                                $entry->person_name,
                                                $entry->user_name,
                                            ])->filter()->implode(' · ') }}
                                        @elseif($entry->amount_ore !== null && ! ($limitedLedger && in_array($entry->kind, ['invoice', 'credit', 'payment'], true)))
                                            <x-ui.amount :money="$money((int) $entry->amount_ore)" />
                                            @if(in_array($entry->kind, ['invoice', 'credit', 'quote', 'order'], true)) {{ __('exkl. moms') }}@endif
                                            @if($entry->kind === 'reminder') {{ __('kvar att betala') }}@endif
                                        @endif
                                    </span>
                                    @if($entry->kind === 'activity' && $entry->body)
                                        <span class="mt-1 block whitespace-pre-line text-sm text-gray-800">{{ $entry->body }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-4">{{ $timeline->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6">
            {{-- Key figures from the ledger. --}}
            <x-ui.card :title="__('Nyckeltal')" :description="$limitedLedger ? __('En privatpersons köp och skulder visas bara för den som får se fakturorna.') : ($account->customer ? __('Ur bokföringen, i kronor.') : __('Visas när prospektet har blivit kund och fått sin första faktura.'))">
                @if($account->customer && ! $limitedLedger)
                    <dl class="divide-y divide-gray-100 text-sm" data-testid="crm-figures">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                            <dt class="text-gray-500">{{ __('Fakturerat 12 mån') }}</dt>
                            <dd><x-ui.amount :money="$money($figures->invoiced12Months)" class="text-base font-bold text-ink" /></dd>
                        </div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                            <dt class="text-gray-500">{{ __('Fakturerat totalt') }}</dt>
                            <dd><x-ui.amount :money="$money($figures->invoicedTotal)" class="text-base font-bold text-ink" /></dd>
                        </div>
                        @if(! $limitedLedger)
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                            <dt class="text-gray-500">{{ __('Obetalt') }}</dt>
                            <dd><x-ui.amount :money="$money($figures->open)" class="text-base font-bold text-ink" /></dd>
                        </div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                            <dt class="text-gray-500">{{ __('Förfallet') }}</dt>
                            <dd><x-ui.amount :money="$money($figures->overdue)" @class(['text-base font-bold', 'text-accent-700' => $figures->overdue > 0, 'text-ink' => $figures->overdue === 0]) /></dd>
                        </div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                            <dt class="text-gray-500">{{ __('Betaltid i snitt') }}</dt>
                            <dd class="text-base font-bold text-ink tabular-nums">{{ $figures->paymentDays === null ? '–' : __(':days dagar', ['days' => number_format($figures->paymentDays, 1, ',', ' ')]) }}</dd>
                        </div>
                        @endif
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                            <dt class="text-gray-500">{{ __('Täckningsbidrag 12 mån') }}</dt>
                            <dd class="text-right">
                                @if($figures->margin === null)
                                    <span class="text-base font-bold text-ink">–</span>
                                @else
                                    <x-ui.amount :money="$money($figures->margin)" class="text-base font-bold text-ink" />
                                    @if($figures->marginRate() !== null)<span class="ms-1 text-xs text-gray-500">{{ number_format($figures->marginRate() * 100, 0, ',', ' ') }} %</span>@endif
                                @endif
                            </dd>
                        </div>
                    </dl>
                    @if($figures->marginCoverage !== null)
                        <p class="mt-3 text-xs text-gray-500">{{ __('Täckningsbidraget bygger på :share % av försäljningen: raderna med en artikel som har inköpspris.', ['share' => number_format($figures->marginCoverage * 100, 0, ',', ' ')]) }}</p>
                    @endif
                @endif
                @if($canConvert)
                    <div class="mt-4">
                        <x-button type="button" wire:click="convert" wire:confirm="{{ __('Göra :name till kund? Kunden får nästa kundnummer och kan faktureras.', ['name' => $account->name]) }}">{{ __('Gör till kund') }}</x-button>
                    </div>
                @endif
                @if($canSell)
                    <div class="mt-4 flex flex-wrap gap-3 text-sm">
                        <a href="{{ route('invoicing.quotes.create', ['kund' => $account->customer_id]) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700">{{ __('Ny offert') }}</a>
                        <a href="{{ route('invoicing.orders.create', ['kund' => $account->customer_id]) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700">{{ __('Ny order') }}</a>
                        <a href="{{ route('invoicing.invoices.create', ['kund' => $account->customer_id]) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700">{{ __('Ny faktura') }}</a>
                    </div>
                @endif
            </x-ui.card>

            {{-- People and their roles. --}}
            <x-ui.card :title="__('Personer')">
                @if($account->people->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Inga kontaktpersoner ännu.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach($account->people as $person)
                            <li wire:key="person-{{ $person->id }}" class="py-3">
                                <span class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-medium text-gray-900">{{ $person->name }}@if($person->doNotContact()) <x-ui.badge tone="accent" class="ms-1">{{ __('Kontakta inte') }}</x-ui.badge>@endif</span>
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
            </x-ui.card>

            @if($canExport)
                <p class="text-sm"><a href="{{ route('privacy.export.customer', $account->customer_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner registerutdrag (JSON)') }}</a></p>
            @endif
        </div>
    </div>

    {{-- The ledger fields, through the customer register's own form. --}}
    @if($canEditCustomer)
        <livewire:crm.customer-fields :customer-id="$account->customer_id" :key="'customer-fields-'.$account->customer_id" />
    @endif
</div>
