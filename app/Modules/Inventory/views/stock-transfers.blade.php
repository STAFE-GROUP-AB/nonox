<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Flyttorder')" :lead="__('Flytta varor mellan lager. De väntar på väg tills de tas emot, och räknas med i lagervärdet hela tiden.')" />
        <livewire:inventory.stock-transfers />
    </div>
</x-app-layout>
