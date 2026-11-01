<x-action-section>
    <x-slot name="title">{{ __('Inloggning') }}</x-slot>
    <x-slot name="description">{{ __('Passkey krävs alltid för den som kan se sjukfrånvaro eller fullständiga personnummer. Här kan du kräva det för alla.') }}</x-slot>
    <x-slot name="content">
        <label class="flex items-start gap-3">
            <input type="checkbox" class="mt-1 rounded border-gray-300" wire:model="required" wire:change="save">
            <span>
                <span class="font-medium text-gray-900">{{ __('Kräv passkey för alla i företaget') }}</span>
                <span class="block text-sm text-gray-600">{{ __('Alla som är med i företaget bekräftar inloggningen med passkey, utöver koden i mejlet. Den som saknar en får lägga till en vid nästa inloggning. Gäller hela kontot, även i andra företag personen är med i.') }}</span>
            </span>
        </label>
    </x-slot>
</x-action-section>
