<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Mina aktiviteter')" :lead="__('Samtal, möten och uppgifter som väntar. Klarmarkera med ett tryck och planera nästa steg.')" />
        <livewire:crm.activities />
    </div>
</x-app-layout>
