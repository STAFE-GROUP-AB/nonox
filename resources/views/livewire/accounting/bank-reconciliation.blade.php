@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $ore): string => \App\Support\Money\Money::ofOre($ore)->format();
@endphp
<div class="space-y-6">
    @if($accounts === [])
        <x-ui.empty-state :title="__('Inga bankkonton i kontoplanen')">{{ __('Lägg till bank- eller kassakonton i kontoplanen (1900–1999) för att kunna stämma av dem.') }}</x-ui.empty-state>
    @else
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj konto') }}">
            @foreach($accounts as $row)
                @php($isSelected = $selected?->id === $row['account']->id)
                <button type="button" wire:key="account-{{ $row['account']->id }}" wire:click="$set('account', {{ $row['account']->number }})" @class([
                    'flex flex-col items-start rounded-2xl px-4 py-2 text-left text-sm transition',
                    'bg-ink text-white' => $isSelected,
                    'bg-white text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300' => ! $isSelected,
                ]) @if($isSelected) aria-current="true" @endif>
                    <span class="font-semibold">{{ $row['account']->number }} {{ $row['account']->name }}</span>
                    <span @class(['text-xs', 'text-stone-300' => $isSelected, 'text-gray-500' => ! $isSelected])>
                        @if($row['latest'] === null)
                            {{ __('Inte avstämt') }}
                        @else
                            {{ __('Avstämt :date', ['date' => $row['latest']->statement_date->toDateString()]) }}@unless($row['latest']->isBalanced()) · {{ __('med skillnad') }}@endunless
                        @endif
                    </span>
                </button>
            @endforeach
        </nav>

        @if($selected !== null)
            <x-ui.card :title="__('Avstämning av :account', ['account' => $selected->number.' '.$selected->name])" :description="__('Skriv in saldot på kontoutdraget. Bocka för det som är bokfört men ännu inte syns hos banken, till exempel en betalning som dras först nästa dag.')">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field for="reconcile_date" :label="__('Datum på kontoutdraget')"><input id="reconcile_date" type="date" class="{{ $input }} mt-1" wire:model.live="date"></x-ui.field>
                    <x-ui.field for="reconcile_statement" :label="__('Saldo enligt kontoutdraget, kr')"><input id="reconcile_statement" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="statement" placeholder="{{ __('Till exempel 125 000,00') }}"></x-ui.field>
                </div>

                @if($feed !== null && $error === null)
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200">
                        <p class="text-sm text-gray-700">
                            {{ __('Saldo enligt banken (:account): :amount', ['account' => $feed['bank_account']->label(), 'amount' => $kr($feed['balance_ore'])]) }}
                            @if($feed['calculated'])
                                <span class="block text-xs text-gray-500">{{ __('Räknat från bankens saldo :date och transaktionerna däremellan.', ['date' => $feed['reported_on']->toDateString()]) }}</span>
                            @endif
                        </p>
                        <button type="button" wire:click="useBankFeed" class="inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400">{{ __('Använd bankens saldo') }}</button>
                    </div>
                @endif

                @if($error !== null)
                    <p class="mt-4 text-sm text-accent-700">{{ $error }}</p>
                @elseif($prepared !== null)
                    <dl class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200">
                            <dt class="text-sm text-gray-500">{{ __('Saldo i bokföringen') }}</dt>
                            <dd class="mt-1 text-xl font-bold tabular-nums text-ink">{{ $kr($prepared['ledger']) }}</dd>
                        </div>
                        <div class="rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200">
                            <dt class="text-sm text-gray-500">{{ __('Inte hos banken ännu') }}</dt>
                            <dd class="mt-1 text-xl font-bold tabular-nums text-ink">{{ $kr(-$outstandingOre) }}</dd>
                        </div>
                        <div class="rounded-2xl bg-paper px-4 py-3 ring-1 ring-stone-200">
                            <dt class="text-sm text-gray-500">{{ __('Ska stå på kontoutdraget') }}</dt>
                            <dd class="mt-1 text-xl font-bold tabular-nums text-ink">{{ $kr($expected) }}</dd>
                        </div>
                        <div @class([
                            'rounded-2xl px-4 py-3',
                            'bg-ink text-white' => $difference === 0,
                            'bg-accent-50 ring-1 ring-accent-200' => $difference !== null && $difference !== 0,
                            'bg-paper ring-1 ring-stone-200' => $difference === null,
                        ])>
                            <dt @class(['text-sm', 'text-stone-300' => $difference === 0, 'text-gray-500' => $difference !== 0])>{{ __('Skillnad') }}</dt>
                            <dd class="mt-1 text-xl font-bold tabular-nums">
                                @if($difference === null)
                                    –
                                @elseif($difference === 0)
                                    {{ __('Stämmer') }}
                                @else
                                    <span class="text-accent-800">{{ $kr($difference) }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6">
                        <p class="text-sm font-semibold text-ink">
                            {{ $prepared['previous'] === null ? __('Bokfört sedan räkenskapsårets början') : __('Bokfört sedan avstämningen :date', ['date' => $prepared['previous']->statement_date->toDateString()]) }}
                            <span class="font-normal text-gray-500">· {{ __(':count rader', ['count' => $prepared['rows']->count()]) }}</span>
                        </p>
                        @if($prepared['rows']->isEmpty())
                            <p class="mt-2 text-sm text-gray-600">{{ __('Inget är bokfört på kontot under perioden.') }}</p>
                        @else
                            <div class="-mx-6 mt-2 max-h-128 overflow-auto sm:mx-0">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="sticky top-0 bg-white">
                                        <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                                            <th class="px-3 py-2" title="{{ __('Bokfört men inte på kontoutdraget ännu') }}">{{ __('Ej hos banken') }}</th>
                                            <th class="px-3 py-2">{{ __('Datum') }}</th>
                                            <th class="px-3 py-2">{{ __('Verifikation') }}</th>
                                            <th class="px-3 py-2">{{ __('Text') }}</th>
                                            <th class="px-3 py-2 text-right">{{ __('Belopp') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-200">
                                        @foreach($prepared['rows'] as $row)
                                            <tr wire:key="row-{{ $row->id }}" @class(['bg-accent-50/60' => in_array($row->id, $outstanding)])>
                                                <td class="px-3 py-2"><input type="checkbox" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500" value="{{ $row->id }}" wire:model.live="outstanding" aria-label="{{ __('Inte hos banken ännu') }}"></td>
                                                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $row->voucher->voucher_date->toDateString() }}</td>
                                                <td class="whitespace-nowrap px-3 py-2"><a href="{{ route('accounting.vouchers.show', $row->voucher) }}" class="font-medium text-accent-700 hover:underline">{{ $row->voucher->reference() }}</a></td>
                                                <td class="px-3 py-2 text-gray-700">{{ $row->description ?? $row->voucher->description }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $kr($row->debit_ore->ore - $row->credit_ore->ore) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <form wire:submit="save" class="mt-6 space-y-4">
                        @if($difference !== null && $difference !== 0)
                            <x-ui.field for="reconcile_explanation" :label="__('Förklaring till skillnaden')" :hint="__('Till exempel en bankavgift som ska bokföras. Skillnaden syns i historiken tills nästa avstämning stämmer.')">
                                <textarea id="reconcile_explanation" rows="2" class="{{ $input }} mt-1" wire:model="explanation"></textarea>
                            </x-ui.field>
                        @endif
                        <x-button :disabled="$difference === null">{{ __('Spara avstämningen') }}</x-button>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Tidigare avstämningar')">
                @if($history->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Kontot har inte stämts av ännu.') }}</p>
                @else
                    <div class="-mx-6 overflow-x-auto sm:mx-0">
                        <table class="min-w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                                    <th class="px-3 py-3">{{ __('Datum') }}</th>
                                    <th class="px-3 py-3 text-right">{{ __('Kontoutdraget') }}</th>
                                    <th class="px-3 py-3 text-right">{{ __('Bokföringen') }}</th>
                                    <th class="px-3 py-3 text-right">{{ __('Ej hos banken') }}</th>
                                    <th class="px-3 py-3 text-right">{{ __('Skillnad') }}</th>
                                    <th class="px-3 py-3">{{ __('Avstämd av') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200">
                                @foreach($history as $item)
                                    <tr wire:key="history-{{ $item->id }}">
                                        <td class="whitespace-nowrap px-3 py-3 tabular-nums">{{ $item->statement_date->toDateString() }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ $item->statement_balance_ore->format() }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ $item->ledger_balance_ore->format() }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ __(':count st', ['count' => count($item->outstanding_row_ids)]) }}</td>
                                        <td class="px-3 py-3 text-right">
                                            @if($item->isBalanced())
                                                <span class="inline-flex rounded-full bg-stone-100 px-3 py-1 text-xs font-medium text-gray-800">{{ __('Stämmer') }}</span>
                                            @else
                                                <span class="font-semibold tabular-nums text-accent-800" title="{{ $item->explanation }}">{{ $item->difference_ore->format() }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-gray-700">{{ $item->reconciler->name }} <span class="block text-xs text-gray-500">{{ $item->created_at->toDateString() }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
