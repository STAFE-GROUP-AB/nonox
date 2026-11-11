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

    @if($unsplitBuildings->isNotEmpty())
        <div class="rounded-2xl bg-amber-50 px-5 py-4 text-sm text-amber-900 ring-1 ring-amber-200">
            <p>{{ trans_choice('{1} Byggnaden :names är inte uppdelad i komponenter. Enligt företagets princip delas en byggnad alltid upp i mark och betydande komponenter (K3 17.4). Välj Dela upp i komponenter på raden.|[2,*] Byggnaderna :names är inte uppdelade i komponenter. Enligt företagets princip delas en byggnad alltid upp i mark och betydande komponenter (K3 17.4). Välj Dela upp i komponenter på raderna.', $unsplitBuildings->count(), ['names' => $unsplitBuildings->map(fn ($building) => $building->number.' '.$building->name)->implode(', ')]) }}</p>
        </div>
    @endif

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
                    <x-ui.field for="asset_value" :label="__('Anskaffningsvärde, kr')" error="acquisition" :hint="$editingHasAdditions ? __('Summan av inköpen som tillgången består av.') : null"><input id="asset_value" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.acquisition" @readonly($editingHasAdditions)></x-ui.field>
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
                            <th scope="col" class="px-3 py-3"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($assets as $parent)
                            @foreach([$parent, ...($children[$parent->id] ?? collect())->all()] as $asset)
                            <tr wire:key="asset-{{ $asset->id }}" @class(['text-gray-400' => $asset->status === 'DISPOSED', 'bg-paper/60' => $asset->isComponent()])>
                                <td class="px-3 py-3 tabular-nums">{{ $asset->number }}</td>
                                <td @class(['px-3 py-3', 'ps-8' => $asset->isComponent()])>
                                    <button type="button" class="font-semibold text-ink hover:underline" wire:click="$set('showing', {{ $showing === $asset->id ? 'null' : $asset->id }})">{{ $asset->name }}</button>
                                    <span class="block text-xs text-gray-500">@if($asset->isComponent()){{ __('Komponent i :number', ['number' => $parent->number]) }} · @endif{{ $asset->category->label() }}@if($asset->depreciates()) · {{ __(':count mån', ['count' => $asset->useful_life_months]) }}@else · {{ __('Skrivs inte av') }}@endif
                                        @if($asset->status === 'DISPOSED') · {{ __('Avyttrad :date', ['date' => $asset->disposed_on?->toDateString()]) }}@elseif($asset->isSplit()) · {{ __('Uppdelad i komponenter') }}@elseif($asset->depreciates() && $asset->isFullyDepreciated()) · {{ __('Fullt avskriven') }}@endif
                                        @if($asset->isComponent() && $asset->depreciates()) · {{ __('Skrivs av från :date', ['date' => $asset->depreciation_start->toDateString()]) }}@endif
                                    </span>
                                    @if($asset->additions->isNotEmpty())
                                        <span class="block text-xs text-gray-500" data-asset-sources>{{ __('Anskaffad med flera inköp:') }}
                                            @foreach([$asset->acquisitionVoucher ?? $asset->supplierInvoice, ...$asset->additions->map(fn ($addition) => $addition->voucher ?? $addition->supplierInvoice)->all()] as $source)
                                                @if($source instanceof \App\Models\Voucher)<a href="{{ route('accounting.vouchers.show', $source) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $source->reference() }}</a>@elseif($source instanceof \App\Models\SupplierInvoice)<a href="{{ route('payables.invoices.show', $source) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $source->title() }}</a>@else{{ __('Inlagd i registret') }}@endif{{ $loop->last ? '' : ',' }}
                                            @endforeach
                                        </span>
                                    @elseif($asset->acquisitionVoucher)
                                        <span class="block text-xs text-gray-500">{{ __('Anskaffad med verifikation') }} <a href="{{ route('accounting.vouchers.show', $asset->acquisitionVoucher) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $asset->acquisitionVoucher->reference() }}</a></span>
                                    @elseif($asset->supplierInvoice)
                                        <span class="block text-xs text-gray-500">{{ __('Anskaffad med') }} <a href="{{ route('payables.invoices.show', $asset->supplierInvoice) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $asset->supplierInvoice->title() }}</a></span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 tabular-nums">{{ $asset->acquired_on->toDateString() }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $asset->acquisition_ore->format() }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $kr($asset->accumulatedOre()) }}</td>
                                <td class="px-3 py-3 text-right font-semibold tabular-nums text-ink">{{ $asset->status === 'ACTIVE' ? $kr($asset->bookValueOre()) : '–' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right">
                                    @if($asset->status === 'ACTIVE')
                                        <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $asset->id }})">{{ __('Ändra') }}</button>
                                        <button type="button" class="ms-3 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="startAddition({{ $asset->id }})">{{ __('Lägg till inköp') }}</button>
                                        @if($k3 && ! $asset->isComponent() && $asset->depreciates())
                                            <button type="button" class="ms-3 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="startSplit({{ $asset->id }})">{{ __('Dela upp i komponenter') }}</button>
                                        @endif
                                        <button type="button" class="ms-3 text-sm text-gray-600 underline" wire:click="startDisposal({{ $asset->id }})">{{ __('Sälj eller utrangera') }}</button>
                                    @endif
                                </td>
                            </tr>
                            @if($split?->id === $asset->id)
                                <tr wire:key="split-{{ $asset->id }}">
                                    <td colspan="7" class="bg-paper px-3 py-4">
                                        @include('livewire.accounting.partials.fixed-asset-components', ['asset' => $asset])
                                    </td>
                                </tr>
                            @endif
                            @if($addingAsset?->id === $asset->id)
                                <tr wire:key="add-{{ $asset->id }}">
                                    <td colspan="7" class="bg-paper px-3 py-4" data-asset-addition>
                                        <p class="text-sm font-semibold text-ink">{{ __('Lägg till inköp i :name', ['name' => $asset->name]) }}</p>
                                        <p class="mt-1 text-xs text-gray-600">{{ __('Ett inköp som redan är bokfört i debet på konto :account blir en del av tillgången, till exempel nästa faktura för samma investering. Anskaffningsvärdet ökar med beloppet. Det som redan är avskrivet ändras inte.', ['account' => $asset->asset_account]) }}</p>
                                        @if($candidates->isEmpty())
                                            <p class="mt-3 text-sm text-gray-600">{{ __('Det finns inga bokförda inköp på konto :account som inte redan hör till en tillgång.', ['account' => $asset->asset_account]) }}</p>
                                            <button type="button" class="mt-3 text-sm text-gray-600 underline" wire:click="cancelAddition">{{ __('Stäng') }}</button>
                                        @else
                                            <div class="mt-3 flex flex-wrap items-end gap-4">
                                                <x-ui.field for="addition_row" :label="__('Inköp')" error="addition">
                                                    <select id="addition_row" class="{{ $input }} mt-1" wire:model.live="additionRow">
                                                        <option value="">{{ __('Välj verifikation') }}</option>
                                                        @foreach($candidates as $candidate)
                                                            <option value="{{ $candidate->id }}">{{ $candidate->voucher->reference() }} · {{ $candidate->voucher->voucher_date->toDateString() }} · {{ $kr($candidate->debit_ore->ore) }} · {{ $candidate->description ?? $candidate->voucher->description }}</option>
                                                        @endforeach
                                                    </select>
                                                </x-ui.field>
                                                @unless($confirmingAddition)
                                                    <x-button type="button" wire:click="reviewAddition">{{ __('Granska tillägget') }}</x-button>
                                                @endunless
                                                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelAddition">{{ __('Avbryt') }}</button>
                                            </div>
                                            @if($additionReview)
                                                <div class="mt-4 rounded-xl bg-white p-4 text-sm ring-1 ring-stone-200" role="alertdialog" aria-labelledby="addition_review_title">
                                                    <p id="addition_review_title" class="font-semibold text-ink">{{ __('Det här händer') }}</p>
                                                    <ul class="mt-2 list-disc space-y-1 ps-5 text-gray-700">
                                                        <li>{{ __('Anskaffningsvärdet ökar med :amount till :total.', ['amount' => $kr($additionReview['amount']), 'total' => $kr($additionReview['total'])]) }}</li>
                                                        @if($additionReview['depreciates'])
                                                            <li>{{ __('Beloppet skrivs av från :month över återstående :count månader av nyttjandeperioden.', ['month' => $additionReview['from'], 'count' => $additionReview['life']]) }}</li>
                                                        @endif
                                                        <li>{{ __('Bokförda avskrivningar ändras inte, och tillägget kan inte tas bort.') }}</li>
                                                        @if($additionReview['manual'])
                                                            <li class="font-medium text-amber-800">{{ __('Tillgången lades in i registret utan koppling till en verifikation. Kontrollera att inköpet inte redan ingår i dess anskaffningsvärde, annars räknas det två gånger.') }}</li>
                                                        @endif
                                                    </ul>
                                                    <div class="mt-3 flex items-center gap-4">
                                                        <x-button type="button" wire:click="addPurchase" wire:loading.attr="disabled">{{ __('Lägg till i tillgången') }}</x-button>
                                                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('confirmingAddition', false)">{{ __('Tillbaka') }}</button>
                                                    </div>
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endif
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
                            @if($shown?->id === $asset->id && ($asset->depreciates() || $asset->additions->isNotEmpty()))
                                <tr wire:key="plan-{{ $asset->id }}">
                                    <td colspan="7" class="bg-paper px-3 py-4">
                                        @if($asset->additions->isNotEmpty())
                                            <p class="mb-2 text-sm font-semibold text-ink">{{ __('Inköp') }}</p>
                                            <div class="mb-4 overflow-x-auto">
                                                <table class="text-left text-xs" data-asset-purchases>
                                                    <thead>
                                                        <tr class="text-gray-500">
                                                            <th scope="col" class="py-1 pe-6 font-medium">{{ __('Verifikation') }}</th>
                                                            <th scope="col" class="py-1 pe-6 font-medium">{{ __('Datum') }}</th>
                                                            <th scope="col" class="py-1 pe-6 text-right font-medium">{{ __('Belopp') }}</th>
                                                            @if($asset->depreciates())<th scope="col" class="py-1 font-medium">{{ __('Skrivs av från') }}</th>@endif
                                                        </tr>
                                                    </thead>
                                                    <tbody class="tabular-nums">
                                                        <tr>
                                                            <td class="py-1 pe-6">
                                                                @if($asset->acquisitionVoucher)
                                                                    <a href="{{ route('accounting.vouchers.show', $asset->acquisitionVoucher) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $asset->acquisitionVoucher->reference() }}</a>
                                                                @elseif($asset->supplierInvoice)
                                                                    <a href="{{ route('payables.invoices.show', $asset->supplierInvoice) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $asset->supplierInvoice->title() }}</a>
                                                                @else
                                                                    {{ __('Inlagd i registret') }}
                                                                @endif
                                                            </td>
                                                            <td class="py-1 pe-6">{{ $asset->acquired_on->toDateString() }}</td>
                                                            <td class="py-1 pe-6 text-right">{{ $kr($asset->originalAcquisitionOre()) }}</td>
                                                            @if($asset->depreciates())<td class="py-1">{{ $asset->depreciation_start->format('Y-m') }}</td>@endif
                                                        </tr>
                                                        @foreach($asset->additions as $addition)
                                                            <tr>
                                                                <td class="py-1 pe-6">
                                                                    @if($addition->voucher)
                                                                        <a href="{{ route('accounting.vouchers.show', $addition->voucher) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $addition->voucher->reference() }}</a>
                                                                    @elseif($addition->supplierInvoice)
                                                                        <a href="{{ route('payables.invoices.show', $addition->supplierInvoice) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $addition->supplierInvoice->title() }}</a>
                                                                    @endif
                                                                </td>
                                                                <td class="py-1 pe-6">{{ $addition->added_on->toDateString() }}</td>
                                                                <td class="py-1 pe-6 text-right">{{ $kr($addition->amount_ore->ore) }}</td>
                                                                @if($asset->depreciates())<td class="py-1">{{ $asset->additionTerms($addition->added_on, $addition->booked_through)['from']->format('Y-m') }}</td>@endif
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                        @if($asset->depreciates())
                                        <p class="mb-2 text-sm font-semibold text-ink">{{ __('Avskrivningsplan') }}</p>
                                        <div class="grid gap-x-6 gap-y-1 text-xs tabular-nums sm:grid-cols-3 lg:grid-cols-4">
                                            @foreach($asset->plan()->schedule() as $row)
                                                <span class="flex justify-between gap-3"><span class="text-gray-500">{{ $row['month'] }}</span><span>{{ $kr($row['amount']) }}</span></span>
                                            @endforeach
                                        </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
