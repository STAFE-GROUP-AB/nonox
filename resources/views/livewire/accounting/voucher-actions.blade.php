@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        @if($canEdit)
            <a href="{{ route('accounting.vouchers.edit', $voucher) }}" class="inline-flex items-center px-4 py-2 bg-accent-600 rounded-lg font-semibold text-sm text-white hover:bg-accent-700">{{ __('Fortsätt med utkastet') }}</a>
            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('confirmingDelete', true)">{{ __('Ta bort utkastet') }}</button>
        @endif

        @if($canCorrect && ! $correcting)
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="$set('correcting', true)">{{ __('Rätta verifikationen') }}</button>
        @endif

        @if($canCopy)
            <a href="{{ route('accounting.vouchers.create', ['kopia' => $voucher->id]) }}" class="text-sm text-gray-600 underline">{{ __('Kopiera till ny verifikation') }}</a>
        @endif
    </div>

    @if($correcting)
        <x-ui.card :title="__('Rätta :reference', ['reference' => $voucher->reference()])" :description="__('En rättelseverifikation vänder alla rader i :reference. Originalet ligger kvar och visar att det är rättat. Behöver något bokföras rätt efteråt kan du kopiera originalet till en ny verifikation.', ['reference' => $voucher->reference()])">
            <form wire:submit="correct" class="grid gap-4 sm:grid-cols-[1fr_10rem]">
                <x-ui.field for="correction_reason" :label="__('Varför rättas den?')" error="reason">
                    <input id="correction_reason" type="text" class="{{ $input }} mt-1" wire:model="reason" maxlength="200" placeholder="{{ __('Till exempel: Fel konto') }}" autofocus>
                </x-ui.field>
                <x-ui.field for="correction_date" :label="__('Datum för rättelsen')" error="voucher_date">
                    <input id="correction_date" type="date" class="{{ $input }} mt-1" wire:model="correctionDate">
                </x-ui.field>
                <div class="sm:col-span-2 flex items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Bokför rättelsen') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('correcting', false)">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($confirmingDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby="confirm-delete-title">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 id="confirm-delete-title" class="text-lg font-semibold text-gray-900">{{ __('Ta bort utkastet?') }}</h2>
                <p class="mt-2 text-sm text-gray-600">{{ __('Utkastet är inte bokfört, så inget i bokföringen påverkas. Det går inte att ångra.') }}</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('confirmingDelete', false)">{{ __('Avbryt') }}</button>
                    <button type="button" class="inline-flex items-center px-4 py-2 rounded-lg bg-accent-600 text-sm font-semibold text-white" wire:click="deleteDraft">{{ __('Ja, ta bort') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
