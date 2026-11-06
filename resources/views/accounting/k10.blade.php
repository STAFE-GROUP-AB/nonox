<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('K10-underlag')" :lead="__('Underlag till blankett K10 för ägarna i ett fåmansföretag: gränsbelopp, lönebaserat utrymme och utdelning ur bokföringen och det ägaren fyller i.')" />
        <livewire:accounting.k10-page />
    </div>
</x-app-layout>
