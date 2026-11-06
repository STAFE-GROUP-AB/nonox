<section class="space-y-3">
    <h2 class="text-base font-semibold text-gray-900">{{ __('Testföretag för API:t') }}</h2>
    <p class="text-sm text-gray-600">{{ __('Vill du eller en utvecklare prova API:t utan att röra den riktiga bokföringen? Skapa ett testföretag. Det blir ett eget företag med påhittade kunder, leverantörer, artiklar, verifikationer och fakturor, och NONOX API påslaget. Det märks tydligt på varje sida, mejlar aldrig fakturor till någon och räknas inte med i statistik eller debitering.') }}</p>

    @if($owned->isNotEmpty())
        <p class="text-sm text-gray-700">{{ __('Dina testföretag:') }} {{ $owned->pluck('name')->implode(', ') }}</p>
    @endif

    @if($owned->count() < $max)
        <form wire:submit="create" class="flex flex-wrap items-end gap-3">
            <x-ui.field for="connect_test_company_name" :label="__('Namn (valfritt)')" error="name">
                <input id="connect_test_company_name" type="text" class="mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model="name" maxlength="255" placeholder="{{ __('Testföretaget AB') }}">
            </x-ui.field>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50" wire:loading.attr="disabled">{{ __('Skapa testföretag') }}</button>
        </form>
    @else
        <p class="text-sm text-gray-500">{{ __('Du har redan :max testföretag. Arkivera ett av dem först.', ['max' => $max]) }}</p>
    @endif
    @error('test_company') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
</section>
