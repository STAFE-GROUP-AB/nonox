@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $secondary = 'inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400 disabled:opacity-50';
@endphp
<div class="space-y-6">
    @if($paused)
        <p role="status" class="rounded-2xl bg-accent-50 p-4 text-sm text-gray-800 ring-1 ring-accent-200">{{ App\Modules\Banking\Support\BankDataProviders::pausedMessage() }}</p>
    @endif
    <x-ui.card :title="__('Kopplade banker')" :description="__('Varje koppling är ett medgivande som du ger i banken med BankID. Det gäller en begränsad tid, som banken bestämmer (oftast 90 eller 180 dagar), och kan kopplas bort när som helst.')">
        @if($canManage && ! $connecting && ! $paused)
            <x-slot:cta><x-button type="button" wire:click="startConnecting">{{ __('Koppla en bank') }}</x-button></x-slot:cta>
        @endif

        @if($connecting)
            <form wire:submit="connect" class="mt-2 grid gap-4 rounded-2xl bg-paper p-4 ring-1 ring-stone-200 sm:grid-cols-3">
                <x-ui.field for="bank_institution" :label="__('Bank')" error="institution">
                    <select id="bank_institution" wire:model="institution" class="{{ $input }} mt-1">
                        <option value="">{{ __('Välj bank') }}</option>
                        @foreach($institutions as $item)
                            <option value="{{ $item->name }}">{{ $item->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="bank_psu_type" :label="__('Typ av konto')" error="psuType" :hint="__('Handelsbanken kräver en fullmakt för öppen bankdata för företagskonton, och Nordea har olika val för Nordea Business och Corporate.')">
                    <select id="bank_psu_type" wire:model="psuType" class="{{ $input }} mt-1">
                        <option value="business">{{ __('Företagskonto') }}</option>
                        <option value="personal">{{ __('Privatkonto (till exempel enskild firma)') }}</option>
                    </select>
                </x-ui.field>
                <div class="flex items-end gap-3">
                    <x-button>{{ __('Fortsätt till banken') }}</x-button>
                    <button type="button" class="{{ $secondary }}" wire:click="$set('connecting', false)">{{ __('Avbryt') }}</button>
                </div>
                @if($institutions === [])
                    <p class="text-sm text-accent-700 sm:col-span-3">{{ __('Listan över banker går inte att hämta just nu. Försök igen om en stund.') }}</p>
                @endif
            </form>
        @endif

        @if($connections->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('Ingen bank är kopplad ännu')">{{ __('Koppla företagets bank så hämtas transaktioner och saldon automatiskt till bankavstämningen.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 space-y-4">
                @foreach($connections as $connection)
                    @php($daysLeft = $connection->daysLeft($today))
                    <div wire:key="connection-{{ $connection->id }}" class="rounded-2xl p-4 ring-1 ring-stone-200">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-ink">{{ $connection->institution_name }}
                                    <x-ui.badge :tone="$connection->isActive() ? 'success' : 'accent'">{{ $connection->status->label() }}</x-ui.badge>
                                </p>
                                <p class="mt-1 text-sm text-gray-600">
                                    @if($connection->isActive() && $connection->valid_until !== null)
                                        {{ __('Medgivandet gäller till och med :date (:days dagar kvar).', ['date' => $connection->valid_until->toDateString(), 'days' => max(0, (int) $daysLeft)]) }}
                                    @endif
                                    {{ $connection->last_fetched_at === null ? __('Inte hämtad ännu.') : __('Senast hämtad :time.', ['time' => $connection->last_fetched_at->format('Y-m-d H:i')]) }}
                                </p>
                                @if($connection->keepsFailing())
                                    <p class="mt-1 text-sm text-accent-700">{{ __('Banken har inte gått att nå de senaste :count gångerna. NONOX försöker igen tidigast :time. Kvarstår felet i ett dygn, kontakta den som driftar NONOX.', ['count' => $connection->consecutive_failures, 'time' => ($connection->next_attempt_at ?? now())->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</p>
                                @elseif($connection->last_error !== null)
                                    <p class="mt-1 text-sm text-accent-700">{{ __('Senaste hämtningen misslyckades. NONOX försöker igen vid nästa hämtning.') }}</p>
                                @endif
                            </div>
                            @if($canManage)
                                <div class="flex flex-wrap gap-2">
                                    @if($connection->isActive() && ! $paused)
                                        <button type="button" class="{{ $secondary }}" wire:click="fetch({{ $connection->id }})" wire:loading.attr="disabled">{{ __('Hämta nu') }}</button>
                                    @endif
                                    @if(! $paused && ($connection->isActive() && $daysLeft !== null && $daysLeft <= 30 || ! $connection->isActive()))
                                        <button type="button" class="{{ $secondary }}" wire:click="startConnecting">{{ __('Koppla igen med BankID') }}</button>
                                    @endif
                                    @if($connection->isActive())
                                        <button type="button" class="{{ $secondary }}" wire:click="askRevoke({{ $connection->id }})">{{ __('Koppla bort') }}</button>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if($confirmingRevoke === $connection->id)
                            <div class="mt-4 rounded-2xl bg-accent-50 p-4 ring-1 ring-accent-200" role="alertdialog" aria-labelledby="revoke-{{ $connection->id }}">
                                <p id="revoke-{{ $connection->id }}" class="text-sm text-gray-800">{{ __('Kopplingen till :bank tas bort och banken ombeds avsluta medgivandet. Inga fler transaktioner hämtas. Det som redan hämtats och avstämts finns kvar.', ['bank' => $connection->institution_name]) }}</p>
                                <div class="mt-3 flex gap-2">
                                    <x-button type="button" wire:click="revoke">{{ __('Koppla bort') }}</x-button>
                                    <button type="button" class="{{ $secondary }}" wire:click="$set('confirmingRevoke', null)">{{ __('Avbryt') }}</button>
                                </div>
                            </div>
                        @endif

                        @if($connection->accounts->isNotEmpty())
                            <div class="-mx-4 mt-4 overflow-x-auto sm:mx-0">
                                <table class="min-w-full text-left text-sm">
                                    <thead>
                                        <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                                            <th class="px-3 py-2">{{ __('Bankkonto') }}</th>
                                            <th class="px-3 py-2">{{ __('Valuta') }}</th>
                                            <th class="px-3 py-2">{{ __('Konto i bokföringen') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-200">
                                        @foreach($connection->accounts as $account)
                                            <tr wire:key="feed-account-{{ $account->id }}">
                                                <td class="px-3 py-2 font-medium text-ink">{{ $account->label() }}</td>
                                                <td class="px-3 py-2">{{ $account->currency }}</td>
                                                <td class="px-3 py-2">
                                                    @if($canManage)
                                                        <label class="sr-only" for="map-{{ $account->id }}">{{ __('Konto i bokföringen för :account', ['account' => $account->label()]) }}</label>
                                                        <select id="map-{{ $account->id }}" class="{{ $input }} max-w-xs" wire:change="map({{ $account->id }}, $event.target.value)">
                                                            <option value="" @selected($account->account_id === null)>{{ __('Hämtas inte') }}</option>
                                                            @foreach($ledgerAccounts as $ledger)
                                                                <option value="{{ $ledger->number }}" @selected($account->account_id === $ledger->id)>{{ $ledger->number }} {{ $ledger->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        {{ $account->ledgerAccount === null ? __('Hämtas inte') : $account->ledgerAccount->number.' '.$account->ledgerAccount->name }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    @if($mapped->isNotEmpty())
        <x-ui.card :title="__('Transaktioner')" :description="__('Hämtade från banken. NONOX föreslår raden i bokföringen med samma belopp inom en vecka; du bekräftar. Det som saknas i bokföringen bokför du som vanligt. Inbetalningar med OCR-nummer finns också under Inbetalningar.')">
            @if($canMatch && $proposals !== [])
                <x-slot:cta><x-button type="button" wire:click="matchAll">{{ __('Matcha alla förslag på sidan (:count)', ['count' => count($proposals)]) }}</x-button></x-slot:cta>
            @endif

            <nav class="mt-2 flex flex-wrap gap-2" aria-label="{{ __('Välj bankkonto') }}">
                @foreach($mapped as $account)
                    @php($isSelected = $selected?->id === $account->id)
                    <button type="button" wire:key="pick-{{ $account->id }}" wire:click="$set('feedAccount', {{ $account->id }})" @class([
                        'rounded-2xl px-4 py-2 text-left text-sm',
                        'bg-ink text-white' => $isSelected,
                        'bg-white text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300' => ! $isSelected,
                    ]) @if($isSelected) aria-current="true" @endif>
                        <span class="font-semibold">{{ $account->label() }}</span>
                        <span @class(['block text-xs', 'text-stone-300' => $isSelected, 'text-gray-500' => ! $isSelected])>{{ $account->ledgerAccount?->number }} {{ $account->ledgerAccount?->name }}</span>
                    </button>
                @endforeach
            </nav>

            @if($transactions === null || $transactions->isEmpty())
                <x-ui.empty-state class="mt-4" :title="__('Inga transaktioner hämtade ännu')">{{ __('De hämtas automatiskt några gånger om dagen, eller med Hämta nu ovan.') }}</x-ui.empty-state>
            @else
                <div class="-mx-6 mt-4 overflow-x-auto sm:mx-0">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                                <th class="px-3 py-2">{{ __('Datum') }}</th>
                                <th class="px-3 py-2">{{ __('Text') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Belopp') }}</th>
                                <th class="px-3 py-2">{{ __('I bokföringen') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach($transactions as $transaction)
                                @php($proposal = $proposals[$transaction->id] ?? null)
                                <tr wire:key="tx-{{ $transaction->id }}">
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $transaction->booking_date->toDateString() }}</td>
                                    <td class="px-3 py-2 text-gray-700">
                                        <span class="font-medium text-ink">{{ $transaction->counterparty ?? __('Okänd motpart') }}</span>
                                        @if($transaction->message !== null || $transaction->reference !== null)
                                            <span class="block text-xs text-gray-500">{{ trim(($transaction->reference !== null ? 'OCR '.$transaction->reference.' ' : '').($transaction->message ?? '')) }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right"><x-ui.amount :money="$transaction->amount_ore" /></td>
                                    <td class="px-3 py-2">
                                        @if($transaction->voucherRow !== null)
                                            <a href="{{ route('accounting.vouchers.show', $transaction->voucherRow->voucher) }}" class="font-medium text-accent-700 hover:underline">{{ $transaction->voucherRow->voucher->reference() }}</a>
                                            @if($canMatch)
                                                <button type="button" class="ml-2 text-xs text-gray-500 underline" wire:click="unmatch({{ $transaction->id }})">{{ __('Ta bort matchning') }}</button>
                                            @endif
                                        @elseif($proposal !== null)
                                            <span class="text-gray-700">{{ __('Förslag: :voucher, :date', ['voucher' => $proposal->voucher->reference(), 'date' => $proposal->voucher->voucher_date->toDateString()]) }}</span>
                                            @if($canMatch)
                                                <button type="button" class="ml-2 text-sm font-semibold text-accent-700 underline" wire:click="match({{ $transaction->id }}, {{ $proposal->id }})">{{ __('Matcha') }}</button>
                                            @endif
                                        @elseif($transaction->incomingPayment !== null)
                                            <a href="{{ route('invoicing.incomingPayments', ['fil' => 'bank']) }}" class="text-accent-700 hover:underline">
                                                {{ $transaction->incomingPayment->invoice === null ? __('Inbetalning att koppla') : __('Inbetalning, förslag :invoice', ['invoice' => $transaction->incomingPayment->invoice->title()]) }}
                                            </a>
                                        @else
                                            <span class="text-gray-500">{{ __('Saknas i bokföringen') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $transactions->links() }}</div>
            @endif
        </x-ui.card>
    @endif
</div>
