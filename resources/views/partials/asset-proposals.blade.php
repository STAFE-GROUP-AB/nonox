{{--
    Offers to create an asset in the register from a row booked to one of the
    company's acquisition accounts (AssetProposals). Included inside the rows'
    Alpine component, whose `rows` it reads; the proposal is kept on the row.
    $kind: 'voucher' or 'supplier'; $blocked: an Alpine expression that hides
    the offer; $fallbackName: an Alpine expression for the default name.
    A row can instead be added to an existing asset (row.asset.add_to).
--}}
@php
    $field = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm max-md:min-h-11';
    $thresholdText = $assetSettings['threshold'] === null ? '' : \App\Support\Money\Money::ofOre($assetSettings['threshold'])->format();
@endphp
<div x-data="assetProposals(@js($assetSettings), @js($kind))" x-show="candidates(rows, {{ $blocked }}).length > 0" x-cloak class="mt-5 rounded-lg border border-gray-200 p-4" data-asset-proposals>
    <p class="text-sm font-semibold text-gray-900">{{ __('Anläggningstillgångar') }}</p>
    <p class="mt-1 text-xs text-gray-600">{{ $kind === 'voucher'
        ? __('Raden bokförs på ett anskaffningskonto. Lägg in inköpet i anläggningsregistret, så skapas tillgången när verifikationen bokförs. Avskrivningarna bokförs sedan från registret varje månad.')
        : __('Raden bokförs på ett anskaffningskonto. Lägg in inköpet i anläggningsregistret, så skapas tillgången när fakturan registreras. Avskrivningarna bokförs sedan från registret varje månad.') }}</p>

    <template x-for="item in candidates(rows, {{ $blocked }})" :key="item.index">
        <div class="mt-3 border-t border-gray-100 pt-3">
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <p class="text-gray-800">
                    {{ __('Rad') }} <span x-text="item.index + 1"></span>:
                    <span class="font-medium tabular-nums" x-text="item.account"></span>,
                    <span class="tabular-nums" x-text="format(item.amount) + ' kr'"></span>
                </p>
                <span class="flex flex-wrap gap-4">
                    <button type="button" class="text-sm font-semibold text-accent-600 hover:text-accent-700" x-show="! item.row.asset && ! item.small" x-on:click="start(item, {{ $fallbackName }})">{{ __('Skapa anläggningstillgång') }}</button>
                    <button type="button" class="text-sm font-semibold text-accent-600 hover:text-accent-700" x-show="! item.row.asset && assetsFor(item).length > 0" x-on:click="startAddition(item)">{{ __('Lägg till i befintlig tillgång') }}</button>
                    <button type="button" class="text-sm text-gray-600 underline" x-show="item.row.asset && ! isAddition(item)" x-on:click="remove(item)">{{ __('Skapa ingen tillgång') }}</button>
                    <button type="button" class="text-sm text-gray-600 underline" x-show="isAddition(item)" x-on:click="remove(item)">{{ __('Lägg inte till') }}</button>
                </span>
            </div>

            @if($thresholdText !== '')
                <p class="mt-1 text-xs text-gray-600" x-show="item.small && ! item.row.asset">{{ __('Under företagets gräns för inventarier av mindre värde (:amount), så ingen anläggningstillgång föreslås. Gränsen ändrar du under Anläggningar.', ['amount' => $thresholdText]) }}</p>
            @endif

            <template x-if="isAddition(item)">
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="block text-xs font-medium text-gray-600 sm:col-span-2">{{ __('Tillgång') }}
                        <select class="{{ $field }} mt-1" x-model="item.row.asset.add_to">
                            <option value="">{{ __('Välj tillgång') }}</option>
                            <template x-for="asset in assetsFor(item)" :key="asset.id">
                                <option :value="String(asset.id)" x-text="asset.label" :selected="String(asset.id) === item.row.asset.add_to"></option>
                            </template>
                        </select>
                        <span class="mt-1 block font-normal text-gray-500">{{ __('Tillgångar på samma konto. Anskaffningsvärdet ökar med inköpet.') }}</span>
                    </label>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Anskaffningsvärde, kr') }}
                        <input type="text" inputmode="decimal" class="{{ $field }} mt-1 text-right tabular-nums" x-model="item.row.asset.acquisition" :placeholder="format(item.amount)">
                        <span class="mt-1 block font-normal text-gray-500">{{ __('Tomt: radens belopp utan moms.') }}</span>
                    </label>
                </div>
            </template>

            <template x-if="item.row.asset && ! isAddition(item)">
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="block text-xs font-medium text-gray-600 sm:col-span-2 lg:col-span-1">{{ __('Benämning') }}
                        <input type="text" class="{{ $field }} mt-1" x-model="item.row.asset.name" maxlength="255">
                    </label>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Slag') }}
                        <select class="{{ $field }} mt-1" x-model="item.row.asset.category" x-on:change="categoryChanged(item)">
                            <template x-for="category in settings.categories" :key="category.value">
                                <option :value="category.value" x-text="category.label" :selected="category.value === item.row.asset.category"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Nyttjandeperiod, månader') }}
                        <input type="number" min="1" max="600" class="{{ $field }} mt-1 tabular-nums" x-model="item.row.asset.life_months">
                    </label>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Anskaffningsvärde, kr') }}
                        <input type="text" inputmode="decimal" class="{{ $field }} mt-1 text-right tabular-nums" x-model="item.row.asset.acquisition" :placeholder="format(item.amount)">
                        <span class="mt-1 block font-normal text-gray-500">{{ __('Tomt: radens belopp utan moms.') }}</span>
                    </label>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Anskaffningsdag') }}
                        <input type="date" class="{{ $field }} mt-1" x-model="item.row.asset.acquired_on">
                        <span class="mt-1 block font-normal text-gray-500">{{ $kind === 'voucher' ? __('Tomt: verifikationens datum.') : __('Tomt: fakturadatum.') }}</span>
                    </label>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Första avskrivningsmånad') }}
                        <input type="month" class="{{ $field }} mt-1" x-model="item.row.asset.depreciation_start">
                        <span class="mt-1 block font-normal text-gray-500">{{ __('Tomt: anskaffningsmånaden.') }}</span>
                    </label>
                </div>
            </template>
        </div>
    </template>
</div>
