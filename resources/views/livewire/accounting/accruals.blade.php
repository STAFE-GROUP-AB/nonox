@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $ore): string => \App\Support\Money\Money::ofOre($ore)->format();
    $steps = [
        'OPENING' => __('Flytt till balanskonto'),
        'RELEASE' => __('Månadens del'),
        'ACCRUAL' => __('Upplupet'),
        'REVERSAL' => __('Återföring'),
    ];
    $spread = in_array($form['kind'] ?? '', ['PREPAID_EXPENSE', 'PREPAID_INCOME'], true);
@endphp
<div class="space-y-6">
    <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Förutbetalda kostnader kvar') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($balances['prepaid']) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Förutbetalda intäkter kvar') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($balances['deferred']) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Att bokföra för :month', ['month' => $month]) }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ __(':count steg', ['count' => count($preview)]) }}</p>
        </div>
    </section>

    <x-ui.card :title="__('Månadens periodiseringar')" :description="__('Den första dagen i månaden återförs förra månadens upplupna poster. Den sista dagen bokförs månadens del av förutbetalda poster och månadens nya upplupna poster. En missad månad tas igen nästa gång.')">
        <div class="flex flex-wrap items-end gap-4">
            <x-ui.field for="accrual_month" :label="__('Månad')">
                <select id="accrual_month" class="{{ $input }} mt-1" wire:model.live="month">
                    @foreach($months as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-button type="button" wire:click="book" wire:loading.attr="disabled" :disabled="$preview === []">{{ __('Bokför periodiseringar') }}</x-button>
        </div>
        @if($preview === [])
            <p class="mt-4 text-sm text-gray-600">{{ __('Inget att bokföra för :month.', ['month' => $month]) }}</p>
        @else
            <ul class="mt-4 divide-y divide-stone-200 text-sm">
                @foreach($preview as $row)
                    <li class="flex justify-between gap-4 py-2">
                        <span>{{ $row['accrual']->number }} {{ $row['accrual']->name }} · <span class="text-gray-500">{{ $steps[$row['type']] }} · {{ $row['accrual']->result_account }} / {{ $row['accrual']->balance_account }}</span></span>
                        <span class="tabular-nums font-medium">{{ $kr($row['amount']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Periodiseringar')">
        <x-slot:cta>
            @unless($creating)
                <x-button type="button" wire:click="create">{{ __('Ny periodisering') }}</x-button>
            @endunless
        </x-slot:cta>

        @if($creating)
            <form wire:submit="save" class="mb-6 space-y-4 rounded-2xl bg-paper p-5 ring-1 ring-stone-200">
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">{{ __('Slag') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach($kinds as $kind)
                            <label wire:key="kind-{{ $kind->value }}" @class([
                                'flex cursor-pointer flex-col gap-1 rounded-2xl bg-white p-4 ring-1 transition',
                                'ring-2 ring-ink' => ($form['kind'] ?? '') === $kind->value,
                                'ring-stone-200 hover:ring-stone-300' => ($form['kind'] ?? '') !== $kind->value,
                            ])>
                                <span class="flex items-center gap-2 font-semibold text-ink"><input type="radio" class="text-ink focus:ring-accent-500" value="{{ $kind->value }}" wire:model.live="form.kind" @disabled($kindLocked)>{{ $kind->label() }}</span>
                                <span class="text-xs text-gray-600">{{ $kind->description() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field for="accrual_name" :label="__('Benämning')" error="name" class="sm:col-span-2"><input id="accrual_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" placeholder="{{ $spread ? __('Till exempel företagsförsäkring 2027') : __('Till exempel el december') }}"></x-ui.field>
                    <x-ui.field for="accrual_total" :label="__('Belopp exkl. moms, kr')" error="total"><input id="accrual_total" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.total"></x-ui.field>
                    <x-ui.field for="accrual_start" :label="$spread ? __('Första månad') : __('Månad')" error="start_month"><input id="accrual_start" type="month" class="{{ $input }} mt-1" wire:model="form.start_month"></x-ui.field>
                    @if($spread)
                        <x-ui.field for="accrual_months" :label="__('Antal månader')" error="months"><input id="accrual_months" type="number" min="1" max="120" class="{{ $input }} mt-1" wire:model="form.months"></x-ui.field>
                    @endif
                    <x-ui.field for="accrual_result" :label="($form['kind'] ?? '') === 'PREPAID_INCOME' || ($form['kind'] ?? '') === 'ACCRUED_INCOME' ? __('Intäktskonto') : __('Kostnadskonto')" error="result_account"><input id="accrual_result" type="number" class="{{ $input }} mt-1" wire:model="form.result_account" placeholder="{{ __('Till exempel 6310') }}"></x-ui.field>
                    <x-ui.field for="accrual_balance" :label="__('Balanskonto')" error="balance_account"><input id="accrual_balance" type="number" class="{{ $input }} mt-1" wire:model="form.balance_account"></x-ui.field>
                    <x-ui.field for="accrual_cost_center" :label="__('Kostnadsställe')" error="cost_center">
                        <select id="accrual_cost_center" class="{{ $input }} mt-1" wire:model="form.cost_center">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($costCenters as $costCenter)<option value="{{ $costCenter->code }}">{{ $costCenter->code }} {{ $costCenter->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="accrual_notes" :label="__('Anteckning')" error="notes" class="sm:col-span-2"><input id="accrual_notes" type="text" class="{{ $input }} mt-1" wire:model="form.notes"></x-ui.field>
                </div>
                @if($spread)
                    <label class="flex items-start gap-3 text-sm text-gray-800">
                        <x-checkbox wire:model="form.move_from_result" class="mt-0.5" />
                        <span>{{ __('Fakturan är bokförd direkt på resultatkontot. Flytta först hela beloppet till balanskontot.') }}<span class="block text-xs text-gray-500">{{ __('Lämna tomt om fakturan redan är bokförd på balanskontot.') }}</span></span>
                    </label>
                @endif
                <p class="text-sm text-gray-600">{{ __('När en månad är bokförd kan bara benämning och anteckning ändras.') }}</p>
                <div class="flex items-center gap-4">
                    <x-button>{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('creating', false)">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @endif

        @if($open->isEmpty() && $done->isEmpty())
            <x-ui.empty-state :title="__('Inga periodiseringar ännu')">{{ __('Lägg in en årsförsäkring som betalats i förväg, eller en kostnad som hör till månaden men faktureras senare.') }}</x-ui.empty-state>
        @else
            @foreach([[__('Pågående'), $open], [__('Klara'), $done]] as [$heading, $group])
                @continue($group->isEmpty())
                <h3 class="mt-2 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">{{ $heading }}</h3>
                <div class="-mx-6 mb-6 overflow-x-auto sm:mx-0">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                                <th class="px-3 py-3">{{ __('Nr') }}</th>
                                <th class="px-3 py-3">{{ __('Periodisering') }}</th>
                                <th class="px-3 py-3">{{ __('Period') }}</th>
                                <th class="px-3 py-3">{{ __('Konton') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Belopp') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Kvar') }}</th>
                                <th scope="col" class="px-3 py-3"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach($group as $accrual)
                                <tr wire:key="accrual-{{ $accrual->id }}">
                                    <td class="px-3 py-3 tabular-nums">{{ $accrual->number }}</td>
                                    <td class="px-3 py-3">
                                        <button type="button" class="font-semibold text-ink hover:underline" wire:click="$set('showing', {{ $showing === $accrual->id ? 'null' : $accrual->id }})">{{ $accrual->name }}</button>
                                        <span class="block text-xs text-gray-500">{{ $accrual->kind->label() }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 tabular-nums">{{ $accrual->start_month }}@if($accrual->kind->isSpread() && $accrual->months > 1) – {{ $accrual->plan()->lastMonth() }}@endif</td>
                                    <td class="px-3 py-3 tabular-nums">{{ $accrual->result_account }} / {{ $accrual->balance_account }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $accrual->total_ore->format() }}</td>
                                    <td class="px-3 py-3 text-right font-semibold tabular-nums text-ink">{{ $kr($accrual->remainingOre()) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right">
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $accrual->id }})">{{ __('Ändra') }}</button>
                                        @if($accrual->postings->isEmpty())
                                            <button type="button" class="ms-3 text-sm text-gray-600 underline" wire:click="delete({{ $accrual->id }})" wire:confirm="{{ __('Ta bort periodiseringen? Inget är bokfört för den ännu.') }}">{{ __('Ta bort') }}</button>
                                        @endif
                                    </td>
                                </tr>
                                @if($shown?->id === $accrual->id)
                                    <tr wire:key="plan-{{ $accrual->id }}">
                                        <td colspan="7" class="bg-paper px-3 py-4">
                                            @if($accrual->kind->isSpread())
                                                <p class="mb-2 text-sm font-semibold text-ink">{{ __('Fördelning') }}</p>
                                                <div class="grid gap-x-6 gap-y-1 text-xs tabular-nums sm:grid-cols-3 lg:grid-cols-4">
                                                    @foreach($accrual->plan()->schedule() as $row)
                                                        @php($booked = $accrual->postings->where('type', 'RELEASE')->where('period', $row['month'])->isNotEmpty())
                                                        <span class="flex justify-between gap-3"><span class="text-gray-500">{{ $row['month'] }}@if($booked) ✓@endif</span><span>{{ $kr($row['amount']) }}</span></span>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if($accrual->postings->isNotEmpty())
                                                <p class="mb-2 mt-4 text-sm font-semibold text-ink first:mt-0">{{ __('Bokfört') }}</p>
                                                <ul class="space-y-1 text-xs tabular-nums">
                                                    @foreach($accrual->postings->sortBy('id') as $posting)
                                                        <li class="flex justify-between gap-3"><span>{{ $posting->period }} · {{ $steps[$posting->type] }}</span><span>{{ $posting->amount_ore->format() }}</span></li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    </x-ui.card>
</div>
