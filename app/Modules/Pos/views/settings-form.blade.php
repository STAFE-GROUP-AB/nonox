@php($input = 'mt-1 block w-28 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums')
<form wire:submit="save" class="space-y-5">
    <p class="text-sm text-gray-600">{{ __('Kontona i dagens verifikation från kassan. Förslagen följer BAS och ska stämmas av med den som sköter bokföringen.') }}</p>

    <fieldset class="grid gap-4 sm:grid-cols-2">
        <legend class="text-sm font-semibold text-ink">{{ __('Försäljning och utgående moms') }}</legend>
        @foreach($rates as $rate)
            <x-ui.field :for="'pos-sales-'.$rate" :label="__('Försäljning :rate % moms', ['rate' => intdiv($rate, 100)])" :error="'settings.account_sales_'.$rate">
                <input id="pos-sales-{{ $rate }}" type="number" inputmode="numeric" wire:model="settings.account_sales_{{ $rate }}" class="{{ $input }}">
            </x-ui.field>
            @if($rate > 0)
                <x-ui.field :for="'pos-vat-'.$rate" :label="__('Utgående moms :rate %', ['rate' => intdiv($rate, 100)])" :error="'settings.account_vat_'.$rate">
                    <input id="pos-vat-{{ $rate }}" type="number" inputmode="numeric" wire:model="settings.account_vat_{{ $rate }}" class="{{ $input }}">
                </x-ui.field>
            @endif
        @endforeach
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
        <legend class="text-sm font-semibold text-ink">{{ __('Betalsätt') }}</legend>
        @foreach($methods as $method)
            <x-ui.field :for="'pos-payment-'.$method->value" :label="$method->label()" :error="'settings.account_payment_'.$method->value">
                <input id="pos-payment-{{ $method->value }}" type="number" inputmode="numeric" wire:model="settings.account_payment_{{ $method->value }}" class="{{ $input }}">
            </x-ui.field>
        @endforeach
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
        <legend class="text-sm font-semibold text-ink">{{ __('Avgifter, dricks och avrundning') }}</legend>
        <x-ui.field for="pos-fees" :label="__('Avgifter för kortbetalningar')" error="settings.account_fees">
            <input id="pos-fees" type="number" inputmode="numeric" wire:model="settings.account_fees" class="{{ $input }}">
        </x-ui.field>
        <x-ui.field for="pos-fee-settlement" :label="__('Avgifter dras från')" :hint="__('Samma konto som kortbetalningarna, eftersom kassaleverantören drar avgiften från utbetalningen.')" error="settings.account_fee_settlement">
            <input id="pos-fee-settlement" type="number" inputmode="numeric" wire:model="settings.account_fee_settlement" class="{{ $input }}">
        </x-ui.field>
        <x-ui.field for="pos-tips" :label="__('Dricks')" error="settings.account_tips">
            <input id="pos-tips" type="number" inputmode="numeric" wire:model="settings.account_tips" class="{{ $input }}">
        </x-ui.field>
        <x-ui.field for="pos-rounding" :label="__('Öresutjämning')" error="settings.account_rounding">
            <input id="pos-rounding" type="number" inputmode="numeric" wire:model="settings.account_rounding" class="{{ $input }}">
        </x-ui.field>
    </fieldset>

    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" wire:model="settings.auto_daily_draft" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
        {{ __('Skapa ett verifikationsutkast för gårdagens försäljning varje morgon') }}
    </label>

    <x-button type="submit">{{ __('Spara') }}</x-button>
</form>
