<x-action-section>
    <x-slot name="title">{{ __('Inloggningar') }}</x-slot>
    <x-slot name="description">{{ __('Logga ut från alla andra enheter, till exempel en borttappad telefon.') }}</x-slot>
    <x-slot name="content">
        <p class="text-sm text-gray-600">{{ __('En inloggning slutar gälla efter :hours timmar, och tidigare om den inte används på en stund. Den här enheten fortsätter vara inloggad.', ['hours' => $hours]) }}</p>
        <div class="mt-5">
            <x-button type="button" wire:click="signOutOthers" wire:confirm="{{ __('Logga ut från alla andra enheter?') }}" wire:loading.attr="disabled">{{ __('Logga ut från andra enheter') }}</x-button>
        </div>
    </x-slot>
</x-action-section>
