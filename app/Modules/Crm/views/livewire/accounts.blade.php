@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $select = $input.' min-h-11 sm:min-h-0';
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$asCustomer ? __('Ny kund') : __('Nytt prospekt')" :description="$asCustomer ? __('Kunden får nästa kundnummer och kan faktureras direkt. Betalningsvillkor och moms ställer du in på kundens kort.') : __('Ett företag eller en person du vill göra affärer med. Det får ett kundnummer först när det blir kund.')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="account_name" :label="__('Namn')" error="name" class="sm:col-span-2">
                    <input id="account_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" autocomplete="organization" required>
                </x-ui.field>
                <x-ui.field for="account_kind" :label="__('Typ')" error="kind">
                    <select id="account_kind" class="{{ $select }} mt-1" wire:model="form.kind">
                        @foreach($kinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="account_org_number" :label="__('Organisationsnummer')" error="org_number">
                    <input id="account_org_number" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="form.org_number">
                </x-ui.field>
                <x-ui.field for="account_email" :label="__('E-post')" error="email">
                    <input id="account_email" type="email" class="{{ $input }} mt-1" wire:model="form.email">
                </x-ui.field>
                <x-ui.field for="account_phone" :label="__('Telefon')" error="phone">
                    <input id="account_phone" type="tel" class="{{ $input }} mt-1" wire:model="form.phone">
                </x-ui.field>
                <x-ui.field for="account_city" :label="__('Ort')" error="city">
                    <input id="account_city" type="text" class="{{ $input }} mt-1" wire:model="form.city">
                </x-ui.field>
                <x-ui.field for="account_owner" :label="__('Ansvarig')" error="owner_id">
                    <select id="account_owner" class="{{ $select }} mt-1" wire:model="form.owner_id">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="account_source" :label="__('Källa')" error="source" :hint="__('Var kontakten kom ifrån, till exempel mässa, tips eller webben.')">
                    <input id="account_source" type="text" class="{{ $input }} mt-1" wire:model="form.source">
                </x-ui.field>
                <x-ui.field for="account_tags" :label="__('Taggar')" error="tags" :hint="__('Skilj taggarna med kommatecken.')">
                    <input id="account_tags" type="text" class="{{ $input }} mt-1" wire:model="form.tags">
                </x-ui.field>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Lägg till') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage && ! $showForm)
                @if($canCreateCustomer)
                    <x-button type="button" wire:click="create(true)">{{ __('Ny kund') }}</x-button>
                @endif
                <x-button type="button" wire:click="create">{{ __('Nytt prospekt') }}</x-button>
            @endif
            <input type="search" class="{{ $input }} min-h-11 w-full sm:min-h-0 sm:max-w-sm" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på namn, person, ort eller e-post') }}" aria-label="{{ __('Sök kund') }}">
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-end">
            <label class="block text-sm text-gray-700">
                <span class="block">{{ __('Status') }}</span>
                <select class="{{ $select }} mt-1" wire:model.live="status">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm text-gray-700">
                <span class="block">{{ __('Ansvarig') }}</span>
                <select class="{{ $select }} mt-1" wire:model.live="owner">
                    <option value="">{{ __('Alla') }}</option>
                    <option value="mina">{{ __('Mina') }}</option>
                    @foreach($members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm text-gray-700">
                <span class="block">{{ __('Ort') }}</span>
                <select class="{{ $select }} mt-1" wire:model.live="city">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($cities as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm text-gray-700">
                <span class="block">{{ __('Tagg') }}</span>
                <select class="{{ $select }} mt-1" wire:model.live="tag">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($tags as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="col-span-2 block text-sm text-gray-700 sm:col-span-1">
                <span class="block">{{ __('Sortera') }}</span>
                <select class="{{ $select }} mt-1" wire:model.live="sort">
                    <option value="namn">{{ __('Namn') }}</option>
                    <option value="status">{{ __('Status') }}</option>
                    <option value="senast">{{ __('Senaste aktivitet') }}</option>
                    <option value="ort">{{ __('Ort') }}</option>
                </select>
            </label>
            @if($filtered)
                <button type="button" class="col-span-2 min-h-11 text-left text-sm font-medium text-accent-600 hover:text-accent-700 sm:col-span-1 sm:min-h-0" wire:click="clearFilters">{{ __('Rensa filter') }}</button>
            @endif
        </div>

        @if($accounts->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$filtered ? __('Ingen kund matchar') : __('Inga kunder eller prospekt ännu')">
                {{ $filtered ? __('Ändra sökningen eller rensa filtren.') : __('Lägg till ett prospekt. Kunderna i kundregistret visas här så fort modulen är på.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-accounts">
                @foreach($accounts as $account)
                    @php($row = $account->customer_id === null ? null : ($sales[$account->customer_id] ?? null))
                    @php($nextDue = $account->getAttribute('next_due_at') === null ? null : \Carbon\CarbonImmutable::parse($account->getAttribute('next_due_at')))
                    <li wire:key="account-{{ $account->id }}" class="relative flex flex-wrap items-center gap-x-4 gap-y-2 py-4">
                        <span class="min-w-0 flex-1 basis-60">
                            <a href="{{ route('crm.accounts.show', $account) }}" class="block font-medium text-gray-900 after:absolute after:inset-0 hover:text-accent-700">{{ $account->name }}</a>
                            <span class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                                <x-ui.badge :tone="$account->status->tone()">{{ $account->status->label() }}</x-ui.badge>
                                <span>{{ collect([$account->city, $account->owner?->name])->filter()->implode(' · ') }}</span>
                            </span>
                            @if($account->getAttribute('next_subject'))
                                <span @class(['mt-1 block text-sm', 'font-medium text-accent-700' => $nextDue !== null && $nextDue->lessThan($now), 'text-gray-600' => $nextDue === null || $nextDue->greaterThanOrEqualTo($now)])>
                                    {{ __('Nästa steg') }}: {{ $account->getAttribute('next_subject') }}@if($nextDue) · {{ $nextDue->setTimezone('Europe/Stockholm')->translatedFormat('j M H:i') }}@endif
                                </span>
                            @elseif($account->status->isOpen())
                                <span class="mt-1 block text-sm text-gray-500">{{ __('Inget nästa steg') }}</span>
                            @endif
                        </span>
                        {{-- What a private person has bought and owes only for those who may see the invoices. --}}
                        @if($account->customer_id !== null && ($canSeeInvoices || $account->kind !== App\Modules\Crm\Domain\AccountKind::Person))
                            <span class="w-1/2 text-left text-sm sm:w-36 sm:text-right">
                                <span class="block text-gray-500">{{ __('Fakturerat 12 mån') }}</span>
                                <x-ui.amount :money="App\Support\Money\Money::ofOre((int) ($row->invoiced_12m ?? 0))" class="font-medium text-gray-900" />
                            </span>
                            {{-- A private person's debts only for those who may see the invoices. --}}
                            @if($canSeeInvoices || $account->kind !== App\Modules\Crm\Domain\AccountKind::Person)
                                <span class="text-left text-sm sm:w-32 sm:text-right">
                                    <span class="block text-gray-500">{{ __('Obetalt') }}</span>
                                    <x-ui.amount :money="App\Support\Money\Money::ofOre((int) ($row->open ?? 0))" @class(['font-medium', 'text-accent-700' => (int) ($row->overdue ?? 0) > 0, 'text-gray-900' => (int) ($row->overdue ?? 0) === 0]) />
                                </span>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $accounts->links() }}</div>
        @endif
    </x-ui.card>
</div>
