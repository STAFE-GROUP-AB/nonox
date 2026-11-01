@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $ore): string => \App\Support\Money\Money::ofOre($ore)->format();
@endphp
<div class="space-y-6">
    @if($missingAccounts !== [])
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-accent-50 px-5 py-4 ring-1 ring-accent-200">
            <p class="text-sm text-accent-900">{{ __('Kontoplanen saknar :accounts, som registret föreslår för tillgångar, avskrivningar och avyttringar.', ['accounts' => implode(', ', $missingAccounts)]) }}</p>
            <x-secondary-button wire:click="addAccounts">{{ __('Lägg till kontona') }}</x-secondary-button>
        </div>
    @endif

    <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Bokfört värde') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($totals['book_value']) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Anskaffningsvärde') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($totals['acquisition']) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Att skriva av för :month', ['month' => $month]) }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr(array_sum(array_column($preview, 'amount'))) }}</p>
        </div>
    </section>

    <x-ui.card :title="__('Månadens avskrivning')" :description="__('Bokförs som en verifikation den sista dagen i månaden: kostnaden per tillgång mot ackumulerade avskrivningar. En missad månad tas igen nästa gång.')">
        <div class="flex flex-wrap items-end gap-4">
            <x-ui.field for="depreciation_month" :label="__('Månad')">
                <select id="depreciation_month" class="{{ $input }} mt-1" wire:model.live="month">
                    @foreach($months as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-button type="button" wire:click="depreciate" wire:loading.attr="disabled" :disabled="$preview === []">{{ __('Bokför avskrivningar') }}</x-button>
        </div>
        @if($preview === [])
            <p class="mt-4 text-sm text-gray-600">{{ __('Inget att skriva av för :month: allt är bokfört eller ingen tillgång har börjat skrivas av.', ['month' => $month]) }}</p>
        @else
            <ul class="mt-4 divide-y divide-stone-200 text-sm">
                @foreach($preview as $row)
                    <li class="flex justify-between gap-4 py-2"><span>{{ $row['asset']->number }} {{ $row['asset']->name }} · {{ $row['asset']->expense_account }} / {{ $row['asset']->accumulated_account }}</span><span class="tabular-nums font-medium">{{ $kr($row['amount']) }}</span></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <details class="rounded-3xl bg-white px-6 py-4 shadow-xs ring-1 ring-stone-200" @if($errors->has('acquisition_accounts') || $errors->has('small_value_threshold')) open @endif>
        <summary class="cursor-pointer font-semibold text-ink">{{ __('Tillgångar från inköp') }}</summary>
        <p class="mt-2 text-sm text-gray-600">{{ __('När en verifikation eller leverantörsfaktura har en rad i debet på ett anskaffningskonto erbjuds du att skapa tillgången här. Den skapas när inköpet bokförs och länkas till verifikationen.') }}</p>
        <form wire:submit="saveSettings" class="mt-4 grid gap-4 sm:grid-cols-[1fr_14rem_auto] sm:items-start">
            <x-ui.field for="acquisition_accounts" :label="__('Anskaffningskonton')" error="acquisition_accounts" :hint="__('Kontonummer eller intervall, till exempel 1220, 1221-1227. Tomt: registrets tillgångskonton.')">
                <input id="acquisition_accounts" type="text" class="{{ $input }} mt-1 tabular-nums" wire:model="settings.accounts">
            </x-ui.field>
            <x-ui.field for="small_value_threshold" :label="__('Gräns för inventarier av mindre värde, kr')" error="small_value_threshold" :hint="__('Under gränsen visas bara en upplysning. Tomt: ingen gräns. Gränsen bestämmer företaget själv.')">
                <input id="small_value_threshold" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="settings.threshold">
            </x-ui.field>
            <div class="sm:pt-6"><x-secondary-button type="submit">{{ __('Spara') }}</x-secondary-button></div>
        </form>
    </details>

    <x-ui.card :title="__('Register')">
        <x-slot:cta>
            @unless($creating)
                <x-button type="button" wire:click="create">{{ __('Ny tillgång') }}</x-button>
            @endunless
        </x-slot:cta>

        @if($creating)
            <form wire:submit="save" class="mb-6 space-y-4 rounded-2xl bg-paper p-5 ring-1 ring-stone-200">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field for="asset_name" :label="__('Benämning')" error="name" class="sm:col-span-2"><input id="asset_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" placeholder="{{ __('Till exempel espressomaskin') }}"></x-ui.field>
                    <x-ui.field for="asset_category" :label="__('Slag')" error="category">
                        <select id="asset_category" class="{{ $input }} mt-1" wire:model.live="form.category" >
                            @foreach($categories as $category)<option value="{{ $category->value }}">{{ $category->label() }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="asset_acquired" :label="__('Anskaffningsdag')" error="acquired_on"><input id="asset_acquired" type="date" class="{{ $input }} mt-1" wire:model="form.acquired_on"></x-ui.field>
                    <x-ui.field for="asset_value" :label="__('Anskaffningsvärde, kr')" error="acquisition"><input id="asset_value" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.acquisition"></x-ui.field>
                    <x-ui.field for="asset_residual" :label="__('Restvärde, kr')" error="residual" :hint="__('Oftast 0.')"><input id="asset_residual" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.residual"></x-ui.field>
                    <x-ui.field for="asset_life" :label="__('Nyttjandeperiod, månader')" error="life_months"><input id="asset_life" type="number" min="1" class="{{ $input }} mt-1" wire:model="form.life_months"></x-ui.field>
                    <x-ui.field for="asset_start" :label="__('Första avskrivningsmånad')" error="depreciation_start"><input id="asset_start" type="date" class="{{ $input }} mt-1" wire:model="form.depreciation_start"></x-ui.field>
                    <x-ui.field for="asset_account" :label="__('Tillgångskonto')" error="asset_account"><input id="asset_account" type="number" class="{{ $input }} mt-1" wire:model="form.asset_account"></x-ui.field>
                    <x-ui.field for="asset_accumulated" :label="__('Ack. avskrivningar')" error="accumulated_account"><input id="asset_accumulated" type="number" class="{{ $input }} mt-1" wire:model="form.accumulated_account"></x-ui.field>
                    <x-ui.field for="asset_expense" :label="__('Avskrivningskonto')" error="expense_account"><input id="asset_expense" type="number" class="{{ $input }} mt-1" wire:model="form.expense_account"></x-ui.field>
                    <x-ui.field for="asset_cost_center" :label="__('Kostnadsställe')" error="cost_center">
                        <select id="asset_cost_center" class="{{ $input }} mt-1" wire:model="form.cost_center">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($costCenters as $costCenter)<option value="{{ $costCenter->code }}">{{ $costCenter->code }} {{ $costCenter->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="asset_opening" :label="__('Redan avskrivet, kr')" error="opening_accumulated" :hint="__('För tillgångar som flyttas hit från ett annat system.')" class="sm:col-span-2"><input id="asset_opening" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.opening_accumulated"></x-ui.field>
                    <x-ui.field for="asset_notes" :label="__('Anteckning')" error="notes" class="sm:col-span-2"><input id="asset_notes" type="text" class="{{ $input }} mt-1" wire:model="form.notes"></x-ui.field>
                </div>
                <p class="text-sm text-gray-600">{{ __('Inköpet bokförs som vanligt när tillgången köps. Registret bokför avskrivningarna. När en månad är avskriven kan bara benämning och anteckning ändras.') }}</p>
                <div class="flex items-center gap-4">
                    <x-button>{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('creating', false)">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @endif

        @if($assets->isEmpty())
            <x-ui.empty-state :title="__('Inga tillgångar ännu')">{{ __('Lägg in inventarier och annat som ska skrivas av över flera år.') }}</x-ui.empty-state>
        @else
            <div class="-mx-6 overflow-x-auto sm:mx-0">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                            <th class="px-3 py-3">{{ __('Nr') }}</th>
                            <th class="px-3 py-3">{{ __('Tillgång') }}</th>
                            <th class="px-3 py-3">{{ __('Anskaffad') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Anskaffningsvärde') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Avskrivet') }}</th>
                            <th class="px-3 py-3 text-right">{{ __('Bokfört värde') }}</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($assets as $asset)
                            <tr wire:key="asset-{{ $asset->id }}" @class(['text-gray-400' => $asset->status === 'DISPOSED'])>
                                <td class="px-3 py-3 tabular-nums">{{ $asset->number }}</td>
                                <td class="px-3 py-3">
                                    <button type="button" class="font-semibold text-ink hover:underline" wire:click="$set('showing', {{ $showing === $asset->id ? 'null' : $asset->id }})">{{ $asset->name }}</button>
                                    <span class="block text-xs text-gray-500">{{ $asset->category->label() }} · {{ __(':count mån', ['count' => $asset->useful_life_months]) }}@if($asset->status === 'DISPOSED') · {{ __('Avyttrad :date', ['date' => $asset->disposed_on?->toDateString()]) }}@elseif($asset->isFullyDepreciated()) · {{ __('Fullt avskriven') }}@endif</span>
                                    @if($asset->acquisitionVoucher)
                                        <span class="block text-xs text-gray-500">{{ __('Anskaffad med verifikation') }} <a href="{{ route('accounting.vouchers.show', $asset->acquisitionVoucher) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $asset->acquisitionVoucher->reference() }}</a></span>
                                    @elseif($asset->supplierInvoice)
                                        <span class="block text-xs text-gray-500">{{ __('Anskaffad med') }} <a href="{{ route('payables.invoices.show', $asset->supplierInvoice) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $asset->supplierInvoice->title() }}</a></span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 tabular-nums">{{ $asset->acquired_on->toDateString() }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $asset->acquisition_ore->format() }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $kr($asset->accumulatedOre()) }}</td>
                                <td class="px-3 py-3 text-right font-semibold tabular-nums text-ink">{{ $asset->status === 'DISPOSED' ? '–' : $kr($asset->bookValueOre()) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right">
                                    @if($asset->status === 'ACTIVE')
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $asset->id }})">{{ __('Ändra') }}</button>
                                        <button type="button" class="ms-3 text-sm text-gray-600 underline" wire:click="startDisposal({{ $asset->id }})">{{ __('Sälj eller utrangera') }}</button>
                                    @endif
                                </td>
                            </tr>
                            @if($disposing === $asset->id)
                                <tr wire:key="dispose-{{ $asset->id }}">
                                    <td colspan="7" class="bg-paper px-3 py-4">
                                        <form wire:submit="dispose" class="flex flex-wrap items-end gap-4">
                                            <x-ui.field for="disposal_date" :label="__('Datum')"><input id="disposal_date" type="date" class="{{ $input }} mt-1" wire:model="disposal.date"></x-ui.field>
                                            <x-ui.field for="disposal_price" :label="__('Pris exkl. moms, kr (0 vid utrangering)')"><input id="disposal_price" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="disposal.price"></x-ui.field>
                                            <x-ui.field for="disposal_account" :label="__('Betalning till konto')"><input id="disposal_account" type="number" class="{{ $input }} mt-1 w-28" wire:model="disposal.account"></x-ui.field>
                                            <x-button>{{ __('Boka bort tillgången') }}</x-button>
                                            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('disposing', null)">{{ __('Avbryt') }}</button>
                                        </form>
                                        <p class="mt-2 text-xs text-gray-500">{{ __('Anskaffningsvärdet och avskrivningarna bokas bort, och skillnaden mot priset blir vinst (3973) eller förlust (7973). Bokför månadens avskrivning först. Moms på försäljningen bokförs separat.') }}</p>
                                    </td>
                                </tr>
                            @endif
                            @if($shown?->id === $asset->id)
                                <tr wire:key="plan-{{ $asset->id }}">
                                    <td colspan="7" class="bg-paper px-3 py-4">
                                        <p class="mb-2 text-sm font-semibold text-ink">{{ __('Avskrivningsplan') }}</p>
                                        <div class="grid gap-x-6 gap-y-1 text-xs tabular-nums sm:grid-cols-3 lg:grid-cols-4">
                                            @foreach($asset->plan()->schedule() as $row)
                                                <span class="flex justify-between gap-3"><span class="text-gray-500">{{ $row['month'] }}</span><span>{{ $kr($row['amount']) }}</span></span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
