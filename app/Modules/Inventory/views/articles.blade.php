<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Artiklar i lager')" :lead="__('Saldo per lagerställe. Flytta, kassera eller ta emot returer direkt i listan.')" />
        <livewire:inventory.articles />
    </div>
</x-app-layout>
