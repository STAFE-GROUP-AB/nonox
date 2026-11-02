<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Lager')" :lead="__('Lagervärde, varor som behöver beställas och de senaste rörelserna.')" />
        <livewire:inventory.overview />
    </div>
</x-app-layout>
