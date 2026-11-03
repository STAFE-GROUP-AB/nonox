<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Affärer')" :lead="__('Säljtratten: varje affär i sitt steg, med värde, väntat avslut och nästa steg. Dra ett kort till ett annat steg, eller tryck på Flytta.')" />
        <livewire:crm.deals />
    </div>
</x-app-layout>
